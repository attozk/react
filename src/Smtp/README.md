## EventEmitter Events

* `MAIL`: Emitted whenever client sends a valid "MAIL FROM" command
* `RCPT`: Emitted whenever client sends a valid "RCPT TO" command
* `DATA`: Emitted whenever client sends a valid "DATA" command
* `DATA-INCOMING`: Emitted whenever client sends data
* `DATA-END`: Emitted whenever client sends .CRLF
* `QUIT`: Emitted whenever client sends "QUIT" command

## Usage

    $loop = \React\EventLoop\Factory::create();
    $arrConfig = array(
        'port' => 25,
        'listenIP' => '0.0.0.0',
        'hostname' => 'myserver.com',
        'mailSizeMax' => 1000,
        'mailAuths' => 'PLAIN LOGIN',
        'relayFromHosts' => array('999.99.99.99'), // using invalid IP for demonstration
        'supportedDomains' => array('myserver2.com', 'myserver2.com'));

    $smtp = new \React\Smtp\Server($loop, $arrConfig);

    $smtp->on('connection', function($conn)
    {
    });

    $smtp->on('MAIL', function($email)
    {
        if ($email->isValidCommand())
        {
            //$sender->isValid();
        }
    });

    $smtp->on('RCPT', function($email)
    {
        if ($email->isValidCommand())
        {
            //$recipient->isValid();
        }
    });

    $smtp->on('DATA-END', function($email)
    {
        $raw = $email->getEmail()->getRaw();
    });

    $smtp->on('CLOSE', function($email)
    {
        echo '--Session Log--' . "\n" . $email->getSessionLog(). '=====' . "\n";
        echo '--EMAIL RAW--' . "\n" . $email->getEmail()->getRaw() . '=====' . "\n";
    });


    $loop->run();