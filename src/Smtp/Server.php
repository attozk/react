<?php
namespace React\Smtp;

use Evenement\EventEmitter;
use React\Socket\Server as SocketServer;

class Server extends EventEmitter
{
    /**
     * @var SocketServer
     */
    private $socket;

    /** 
     * smtp config - making it static so EmailConnection class can access config
     * without passing config (duplicate) for each client.
     *
     * @var array
     */
    private static $arrConfig;
    
    private $version = 0.1;

    /**
     * Array of connected clients
     * 
     * @var array
     */
    private $arrClients = array();

    public function __construct(SocketServer $socket, array $arrConfig = array())
    {
        $this->initConfig($arrConfig);
        $this->initSocket($socket);
        $this->initEvents();
        
        /*
        $this->socket->addPeriodicTimer(50, function ()
        {
            $memory = memory_get_usage() / 1024;
            $formatted = number_format($memory, 3).'K';
            echo date('Y-m-d') . ' -> ' . "Current memory usage: {$formatted}\n";

        });
        */
    }

    /**
     * Configures smtp and adds default configs as needed:
     *
     *  port: 25
     *  listenIP: localhost
     *  hostname: of the mail server
     *  mailSizeMax: 35882577 in bytes maximum mail size (for SIZE extension)
     *  mailAuths:  PLAIN
     *      Other options are: LOGIN CRAM-MD5 (seperated by space) to be implemented by user
     * relayHosts: array() of hosts which for which relay is supported
     * supportedDomains: array() of supported domains that SMTP server is responsible for
     *
     * @param $arrConfig
     */
    private function initConfig(&$arrConfig)
    {
        $arrDefaultConfig = array(
            'port' => 25,
            'listenIP' => 'localhost',
            'hostname' => 'phpreact-smtpd',
            'mailSizeMax' => 35882577,
            'mailAuths' => 'PLAIN',
            'relayHosts' => array(),
            'supportedDomains' => array()
        );

        foreach($arrDefaultConfig as $conf => $value)
        {
            if (!isset($arrConfig[$conf]))
                $arrConfig[$conf] = $value;
        }


        self::$arrConfig = $arrConfig;
    }

    /**
     * Initializes socket
     * 
     * @param object $socket instance of SocketServer
     */
    private function initSocket(SocketServer &$socket)
    {
        $this->socket = $socket;
        $this->socket->listen(
                                $this->getConfig('port'),
                                $this->getConfig('listenIP'));        
    }

    /**
     * Initializes event binding
     */
    private function initEvents()
    {
        $this->socket->on('connection', function($conn)
        {
            $client = new Client($conn);
            $sessionid = $client->getSessionId();

            $this->arrClients[$sessionid] = array(
                                                    'totalCommands' => 0,
                                                    'timestamp' => time());

            $client->on('stream', function($client)
            {
                $this->emit($client->getCommand(), array($client));
            });

            $conn->on('data', function($data) use($client)
            {
                $sessionid = $client->getSessionId();
                /*
                if (isset($this->arrClients[$sessionid]))
                {
                    if (!isset($this->arrClients[$sessionid]['totalCommands']))
                        $this->arrClients[$sessionid]['totalCommands'] = 0;

                    $this->arrClients[$sessionid]['totalCommands']++;
                }
                */

                $client->feed($data);
            });

            $conn->on('close', function($conn) use ($client)
            {
                echo '------------------>' . $client->getEmail()->getRaw() . '<----------';
                if ($client->isReadable())
                    $client->close();

                /*
                $sessionid = $client->getSessionId();
                if (isset($this->arrClients[$sessionid]))
                    unset($this->arrClients[$sessionid]);
                */
            });
        });
    }

    /**
     * Get config
     */
    public static function getConfig($key, $defaultValue = null)
    {
        $value = $defaultValue;

        if (array_key_exists($key, self::$arrConfig))
            $value = self::$arrConfig[$key];

        return $value;
    }
}
