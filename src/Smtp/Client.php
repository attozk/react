<?php
namespace React\Smtp;

use Evenement\EventEmitter;
use React\Socket\Connection as SocketConnection;
use React\Smtp\Helper\Emailper;

/**
 * Email Client class 
 * 
 */
class Client extends EventEmitter
{
    /**
     * Maximum number of TOs, CCs & BCCs recipients allowed
     * @url ref http://tools.ietf.org/html/rfc5321#section-4.5.3.1.10
     * @url ref https://support.google.com/a/answer/166852?hl=en
     */
    const MAX_RECIPIENTS = 200;

    /**
     * Terminate connection after this many errors
     */
    const MAX_CLIENT_ERRORS = 20;

    /**
     * @var SocketConnection
     */
    private $conn;
    
    /**
     * object representation of the actual email
     * 
     * @var use React\Email
     */
    private $email = null;

    /**
     * Current mail command (EHLO, MAIl, RCPT, DATA , QUIT, RSET and custom DATA, DATA-INCOMING, DATA-END, etc..)
     *
     * @var
     */
    private $command;

    /**
     * Which mode is the smtp connection is being used for INBOUND vs OUTBOUND vs RELAY:
     * This gets reset with RSET command
     * @var mode
     */
    private $mode;

    /**
     * Could be user_id, email or anything unique which is set when user has authenticated
     *
     * @var
     */
    private $authId;

    /**
     * Session id
     */
    private $sessionId;

    /**
     * Session log
     */
    private $sessionLog;

    /**
     * State of transaction (EHLO, MAIL, RCPT, DATA)
     *
     * For a given command, say MAIL the value of this variable will only be
     * MAIL when client has passed correct information (syntax) and we have
     * validated it.
     *
     * In other words, for a valid command $this->command == $this->state
     * @var
     */
    private $state;

    /**
     * To keep track of client errors during connection
     * This value is not reset with RSET or EHLO command
     *
     * When it exceeds MAX_CLIENT_ERRORS errors, we force a connection shutdown..
     * @var int
     */
    private $totalClientErrors = 0;

    /**
     * Stores auto generated response from feed() which emits "stream" for
     * others to implement their own logic. If such an implement does not exist
     * then this default message is send to client.
     *
     * This is reset with every respond() call
     */
    private $respondDefaultMessage;

    public function __construct(SocketConnection $conn)
    {
        $this->conn = $conn;
        $this->sessionId = $this->generateSessionId();
        $this->respond(220, 'React SMTP Server');
        $this->email = new Email();
    }

    /**
     * Returns the email object
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     * Makes sense of incoming mail data and emit events accordingly
     *
     * Order of commands @ http://tools.ietf.org/html/rfc5321#page-44
     * EHLO -> Clear state -> MAIL -> RCPT (multiple) -> DATA
     * NOOP, HELP, EXPN, VRFY, and RSET commands can be used at any time during a session
     * @url Enhanced Mail System Status Codes https://tools.ietf.org/html/rfc3463
     * @param $data
     */
    public function feed($data)
    {
        $this->storeSessionLog('C', $data);

        if ($this->totalClientErrors < self::MAX_CLIENT_ERRORS)
        {
            preg_match('/^(\w+)/', $data, $arrMatches);
            $mailCmd = strtoupper(@array_pop($arrMatches));
            $this->command = null;
            $arrSupportedDomains = Server::getConfig('supportedDomains');

            // these are array of array
            $arrDefaultMessageSuccess = $arrDefaultMessageError = null;

            /**
             *   RSET @ http://tools.ietf.org/html/rfc5321#section-4.1.1.5
             *   Syntax -> rset = "RSET" CRLF
             *   S: 250
             */
            if (preg_match('/^RSET\r\n$/i', $data) && $this->state != 'DATA-INCOMING')
            {
                $this->reset();
                $this->command = 'RSET';
                $arrDefaultMessageSuccess = array(array(250, 'Flushed', '2.1.5'));
            }
            /**
             *  S: 250
             *   E: 504 (a conforming implementation could return this code only
             *   in fairly obscure cases), 550, 502 (permitted only with an old-
             *   style server that does not support EHLO)
             */
            else if ($mailCmd == 'EHLO' || $mailCmd == 'HELO')
            {
                $this->reset();
                $this->state = $this->command = 'EHLO';

                $arrDefaultMessageSuccess = array(array('250-', server::getConfig('hostname') . ' at your service, ' . $this->conn->getRemoteAddress()),
                                                  array('250-', 'SIZE ' . server::getConfig('mailSizeMax')),
                                                  array('250-', '8BITMIME'),
                                                  array('250-', 'ENHANCEDSTATUSCODES'));

                // AUTH supported?
                if ($auth = server::getConfig('mailAuths'))
                    $arrDefaultMessageSuccess[] = array('250-', trim('AUTH ' . $auth));

                $arrDefaultMessageSuccess[] = array(250, 'CHUNKING');
            }
            /**
             *  MAIL @ http://tools.ietf.org/html/rfc5321#section-3.3
             *   syntax is MAIL FROM:<reverse-path> [SP <mail-parameters> ] <CRLF>
             *   with size extension is also valid @ MAIL FROM:<userx@test.ex> SIZE=1000000000
             *   S: 250
             *   E: 552, 451, 452, 550, 553, 503, 455, 555
             */
            else if ($mailCmd == 'MAIL')
            {
                $this->command = 'MAIL';
                // check if we are in valid session state
                if ($this->state == 'EHLO')
                {
                    $arrParseFeed = Emailper::parseFROMFeed($data);
                    if (is_array($arrParseFeed) && ($from = $arrParseFeed['email']))
                    {
                        $this->state = 'MAIL';
                        $arrDefaultMessageSuccess = array(array(250, 'OK', '2.1.0'));

                        /**
                         * Let the "stream" emit decide what needs to happen here
                         */
                        $this->email->storeRawHeader($data);
                        $this->email->setHeaderFrom($from);
                    }
                    else
                        $arrDefaultMessageError = array(array(503, 'Syntax error', '5.5.2'));
                }
                // EHLO not initialized
                else if (!$this->state)
                    $arrDefaultMessageError = array(array(503, 'EHLO/HELO first', '5.5.1'));
                else
                    $arrDefaultMessageError = array(array(503, 'Invalid command', '5.5.1'));
            }
            /**
              *  S: 250, 251 (but see Section 3.4 for discussion of 251 and 551)
              *  E: 550, 551, 552, 553, 450, 451, 452, 503, 455, 555
             */
            else if ($mailCmd == 'RCPT')
            {
                $this->command = 'RCPT';
                // check if we are in valid session state
                if ($this->state == 'MAIL' || $this->state == 'RCPT')
                {
                    if ($this->email->getTotalRecipients() < self::MAX_RECIPIENTS)
                    {
                        $arrParseFeed = Emailper::parseTOFeed($data);
                        if (is_array($arrParseFeed) && ($to = $arrParseFeed['email']))
                        {
                            // prevent open relay
                            if ($this->authId || Emailper::isSupportedEmailAddress($to, $arrSupportedDomains))
                            {
                                $this->state = 'RCPT';
                                $arrDefaultMessageSuccess = array(array(250, 'OK', '2.1.0'));

                                /**
                                 * Let the "stream" emit decide what needs to happen here
                                 */
                                $this->email->storeRawHeader($data);
                                $this->email->setHeaderTos($to);
                            }
                            else
                                $arrDefaultMessageError = array(array(550, 'Unable to relay for '. $to, '5.7.1'));
                        }
                        else
                            $arrDefaultMessageError = array(array(503, 'Syntax error', '5.5.2'));
                    }
                    else
                        $arrDefaultMessageError = array(array(552, 'Too many recipients', '5.5.3'));
                }
                else
                    $arrDefaultMessageError = array(array(503, 'Mail first', '5.5.1'));
            }
            /**
             *  I: 354 -> data -> S: 250
             *          E: 552, 554, 451, 452
             *          E: 450, 550 (rejections for policy reasons)
             *    E: 503, 554
             */
            else if ($mailCmd == 'DATA')
            {
                $this->command = 'DATA';
                //$this->setMode();

                // check if we are in valid session state
                if ($this->state == 'RCPT')
                {
                    $this->state = 'DATA';
                    $arrDefaultMessageSuccess = array(array(354, 'Go ahead'));
                }
                // EHLO not initialized
                else if (!$this->state)
                    $arrDefaultMessageError = array(array(503, 'EHLO/HELO first', '5.5.1'));
                else
                    $arrDefaultMessageError = array(array(503, 'Invalid command', '5.5.1'));
            }
            // we are getting data
            else if ($this->state == 'DATA')
            {
                $this->command = $this->state = 'DATA-INCOMING';
                $this->email->storeRawBody($data);
                $this->email->setBody($data);
            }
            // we have got all the data
            else if ($this->state == 'DATA-INCOMING')
            {
                // this is end of email
                if (preg_match('/^\.\r\n$/', $data))
                {
                    $this->command = $this->state = 'DATA-END';
                    $arrDefaultMessageSuccess = array(array(250, 'OK'));
                }
            }
            else if ($mailCmd == 'QUIT')
            {
                $this->command = $this->state = 'QUIT';
                $arrDefaultMessageSuccess = array(array(221, 'closing connection', '2.0.0'));
            }
            else
                $arrDefaultMessageError = array(array(500, 'Invalid command', '5.5.1'));

            // there were errors?
            if (is_array($arrDefaultMessageError))
                $this->totalClientErrors++;

            // let others implement their own logic (via emit "stream")
            $this->respondDefaultMessage = (array) $arrDefaultMessageSuccess + (array) $arrDefaultMessageError;

            // notify so one can implement their own handlers
            $this->emit('stream', array($this));

            // if steam emit was not overwritten, proceed with default response
            $this->respondDefault();

            // close stream
            if ($mailCmd == 'QUIT')
            {
                if ($this->isConnectionOpen())
                    $this->close();
            }
        }
        // too many errors
        else
        {
            $this->respond(421, 'Too many errors on this connection---closing', '4.5.2');
            $this->close();
        }
    }

    /**
     * Sends default response
     */
    private function respondDefault()
    {
        if (is_array($this->respondDefaultMessage) && count($this->respondDefaultMessage))
        {
            // copy to local var as respond would reset $this->respondDefaultMessage
            $respondBuffer = $this->respondDefaultMessage;
            foreach ($respondBuffer as $_arrMsg)
            {
                call_user_func_array(array($this, 'respond'), (array) $_arrMsg);
            }
        }
    }

    /**
     * Write to stream
     */
    public function respond($code, $message, $extendedCode = null)
    {
        $this->respondDefaultMessage = null;

        if (preg_match('/-$/', $code))
            $extendedCode = null;
        else
        {
            $code = $code .' ';

            if ($extendedCode)
                $extendedCode = $extendedCode .' ';
        }

        if ($this->state != 'EHLO')
            $message .= ' - '. $this->getSessionId();

        $message = $code . $extendedCode . Emailper::messageln($message);

        $this->storeSessionLog('S', $message);

        return $this->conn->write($message);
    }

    /**
     * Generate a uniq smtp id
     */
    private function generateSessionId()
    {
        return uniqid();
    }

    /**
     * Returns session id
     */
    public function getSessionId()
    {
        return $this->sessionId;
    }

    /**
     * Get session log
     */
    public function getSessionLog()
    {
        return $this->sessionLog;
    }

    /**
     * Store session log
     *
     * @param string $who (S)erver, (C)lient
     */
    private function storeSessionLog($who, $data)
    {
        $this->sessionLog .= $who .': ' .$data;

        echo "$who: $data";
    }

    /**
     * Check for OpenRelay and sets Mode
     *
     * @url to prevent relay http://www.spamsoap.com/smtp-open-relay-test/
     * @return true when client is trying to use as open relay
     */
    private function setMode()
    {
        // check for OpenRelay
        $isOpenRelay = true;
        $arrRelayFromHosts = Server::getConfig('relayFromHosts');


        $from = $this->email->getFrom();
        $domainOfFrom = Emailper::getDomainOfEmailAddress($from);


        /*
         *
        if (count($arrSupportedDomains))
        {
            if (in_array($arrSupportedDomains, $domainOfFrom))
            {
                // Not OpenRelay when "MAIL FROM" is a supported domain and authenticated user
                if ($this->authId)
                {
                    $this->mode = 'OUTBOUND';
                    $openRelay = false;
                }
            }

        }

        // check for relay
        if (in_array($arrRelayFromHosts, $this->conn->getRemoteAddress()))
        {

        }


        return $openRelay;
        */
    }

    /**
     * Returns the mode in which smtp is being used (send or receive or relay)
     *
     * @return mode
     */
    public function getMode()
    {
        return $this->mode;
    }

    /**
     * Sets authId
     *
     * @param $authId
     */
    public function setAuthId($authId)
    {
        $this->authId = $authId;
    }

    /**
     * Returns authId
     */
    public function getAuthId()
    {
        return $this->authId;
    }

    /**
     * Sets session state
     *
     * @param $state
     */
    public function setState($state)
    {
        $this->state = $state;
    }

    /**
     * Returns session id
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * Returns current command
     */
    public function getCommand()
    {
        return $this->command;
    }

    /**
     * Returns true when client has send valid command, in sequence without
     * any syntax errors
     */
    public function isValidCommand()
    {
        return $this->command == $this->state;
    }

    /**
     * Reset's sessions state
     */
    public function reset()
    {
        $this->state = $this->mode = null;
        $this->email = new Email();
    }

    /**
     * check if stream is readable
     */
    public function isConnectionOpen()
    {
        return $this->conn->isReadable();
    }

    /**
     * Close conn
     */
    public function close()
    {
        return $this->conn->close();
    }
}
