<?php
namespace React\Smtp\Helper;

/**
 * Email Helper & parser class
 */
class Emailper
{
    /**
     * Given RCPT, TO, CC, BCC fields, this would parse email address
     * (and names, if any) and would return an array of array
     *
     * @return null|array (and array of array when multiple email addresses)
     */
    public static function parseTOFeed($line)
    {
        $arrResult = null;

        if (preg_match('/^RCPT\sTO:\s?<(.+?)>/i', $line, $arrMatches))
        {
            $to = $arrMatches[1];

            $arrResult = array();
            $arrResult['email'] = $to;
        }

        return $arrResult;
    }

    /**
     * Given MAIL FROM this would parse email address
     * (and names, if any) and would return an array of array
     *
     * @return null|array
     */
    public static function parseFROMFeed($line)
    {
        $arrResult = null;

        // allow space in between MAIL FROM & <address@domain> as I have seen
        // other smtps allowing it
        if (preg_match('/^MAIL\sFROM:\s?<(.+?)>\s{0,}(SIZE=(\d+))?/i', $line, $arrMatches))
        {
            $size = null;
            $from = $arrMatches[1];
            if (isset($arrMatches[3])) // for checking SIZE=VALUE
                $size = $arrMatches[3];

            // validate $from address
            if (self::validateEmailAddress($from))
            {
                $arrResult = array();
                $arrResult['email'] = $from;

                if ($size)
                    $arrResult['size'] = $size;
            }
        }

        return $arrResult;
    }

    /**
     * Returns the part after @ of email address
     *
     * @param string $email
     */
    public static function getDomainOfEmailAddress($email)
    {
        if (preg_match('/^(.+?)@(.+?)$/', $email, $arrMatch))
            return array_pop($arrMatch);
    }

    /**
     * Returns string with line ending
     *
     * @param $str
     * @param string $lineEnd
     */
    public static function messageln($str, $lineEnd = "\r\n")
    {
        return $str . $lineEnd;
    }

    /**
     * Returns true when an email address is valid
     */
    public static function validateEmailAddress($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}
