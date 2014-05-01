<?php
namespace React\Smtp;

/**
 * Class emailContent - object representation of an email
 *
 * @package hathoora\kabootar\lib\smtp
 */
class Email
{
    /**
     * @var stores raw header
     */
    private $rawHeader = null;

    /**
     * @var stores raw body
     */
    private $rawBody = null;

    /**
     * @var stores MAIL FROM:<$fromEmail>
     */
    private $arrHeaderFromEmail = array();

    /**
     * Stores array of array
     *
     * @var stores RCPT TO:<$fromEmail>
     */
    private $arrHeaderarrToEmails = array();

    /**
     * Stores MAIL FROM:<$email> in structured way
     */
    public function setHeaderFrom($email)
    {
        $this->arrHeaderFromEmail['email'] = $email;
    }

    /**
     * get MAIL FROM:<$email>
     */
    public function getFrom()
    {
        return $this->arrHeaderFromEmail['email'];
    }

    /**
     * Stores RCPT TO:<$email> in structured way
     */
    public function setHeaderTo($email)
    {
        $this->arrHeaderarrToEmails[$email] = array(
            'email' => $email
        );
    }

    /**
     * Returns array of arrays of RCPT TO:<$email>
     */
    public function getTo()
    {
        return $this->arrHeaderarrToEmails;
    }

    /**
     * Stores DATA from email in structured way
     */
    public function setBody()
    {
        //
    }

    /**
     * Stores raw header
     */
    public function storeRawHeader($data)
    {
        $this->rawHeader .= $data;
    }

    /**
     * Get raw header
     */
    public function getRawHeader()
    {
        return $this->rawHeader;
    }

    /**
     * Stores raw body
     */
    public function storeRawBody($data)
    {
        $this->rawBody .= $data;
    }

    /**
     * Get raw body
     */
    public function getRawBody()
    {
        return $this->rawBody;
    }

    /**
     * Get raw email
     */
    public function getRaw()
    {
        return $this->getRawHeader() . $this->getRawBody();
    }
}