## EventEmitter Events

* `MAIL`: Emitted whenever client sends a valid "MAIL FROM" command
* `RCPT`: Emitted whenever client sends a valid "RCPT TO" command
* `DATA`: Emitted whenever client sends a valid "DATA" command
* `DATA-INCOMING`: Emitted whenever client sends data
* `DATA-END`: Emitted whenever client sends .CRLF
* `QUIT`: Emitted whenever client sends "QUIT" command

## Usage

    $loop = \React\EventLoop\Factory::create();
    $socket = new \React\Socket\Server($loop);

    $arrConfig = array(
        'port' => 25,
        'listenIP' => '0.0.0.0',
        'hostname' => 'server.com',
        'maxMailSize' => 10000); // in bytes

    $smtp = new \React\Smtp\Server($socket, $arrConfig);

    $smtp->on('MAIL', function($email)
    {
        if ($email->isValidCommand()) { /*Validate from email address*/ }
    });

    $smtp->on('DATA-END', function($email)
    {
        $raw = $email->getEmail()->getRaw();
    });

    $loop->run();