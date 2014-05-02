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
     * Which mode is the smtp connection is being used for (in this order):
     *      send:
     *          - When user is authenticated AND "MAIL FROM" is from supportedDomains
     *
     *     relay:
     *          - when "MAIL FROM" is not from supportedDOmains AND
     *            client's IP/hostname is a valid relayHosts
     *
      *     open-relay:
     *          - when checkForRelay() is true
     *
     *      receive:
     *          - When RCPT TO is a local user of supportedDomains
     *
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
        $this->sessionLog('C', $data);

        preg_match('/^(\w+)/', $data, $arrMatches);
        $mailCmd = strtoupper(@array_pop($arrMatches));
        $this->command = null;

        // these are array of array
        $arrDefaultMessageSuccess = $arrDefaultMessageError = null;

        /**
         *   RSET @ http://tools.ietf.org/html/rfc5321#section-4.1.1.5
         *   Syntax -> rset = "RSET" CRLF
         *   S: 250
         */
        if ($mailCmd == 'RSET' && preg_match('/^RSET\r\n$/i', $data))
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
                $arrParseFeed = Emailper::parseTOFeed($data);
                if (is_array($arrParseFeed) && ($to = $arrParseFeed['email']))
                {
                    $this->checkForOpenRelay($this->email->getFrom(), $to);

                    $this->state = 'RCPT';
                    $arrDefaultMessageSuccess = array(array(250, 'OK', '2.1.0'));

                    /**
                     * Let the "stream" emit decide what needs to happen here
                     */
                    $this->email->storeRawHeader($data);
                    $this->email->setHeaderTo($to);
                }
                else
                    $arrDefaultMessageError = array(array(503, 'Syntax error', '5.5.2'));
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
            $arrDefaultMessageError = array(array(503, 'Invalid command', '5.5.1'));

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
        if ($mailCmd == 'QUIT' && $this->isReadable())
            $this->close();
    }

    /**
     * Check for OpenRelay and sets Mode
     *
     * @url to prevent relay http://www.spamsoap.com/smtp-open-relay-test/
     * @return true when client is trying to use as open relay
     */
    private function checkForOpenRelay($from, $to)
    {
        // check for OpenRelay
        $isOpenRelay = true;
        $domainOfFrom = Emailper::getDomainOfEmailAddress($from);
        $domainOfTo = Emailper::getDomainOfEmailAddress($to);
        $arrSupportedDomains = Server::getConfig('supportedDomains');

        if (count($arrSupportedDomains))
        {
            if (in_array($arrSupportedDomains, $domainOfFrom))
            {
                // 1) Not OpenRelay when "MAIL FROM" is a supported domain and authenticated user
                if ($this->authId)
                {
                    $this->mode = 'send';
                    $openRelay = false;
                }
            }

        }



        return $openRelay;
    }

    /**
     * Sends buffered response
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

        $this->sessionLog('S', $message);

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
     * Sets the mode in which smtp is being used (send or receive or relay)
     *
     * @return mode
     */
    public function setMode($mode)
    {
        $this->mode = $mode;
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
    public function isReadable()
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

    /**
     * log transaction for debugging
     *
     * @param string $who (S)erver, (C)lient
     */
    private function sessionLog($who, $data)
    {
        echo "$who: $data";
    }
}
