<?php
namespace React\Smtp;

use Evenement\EventEmitter;
use React\Socket\SocketServerInterface;

class Server extends EventEmitter
{
    /**
     * @var ServerInterface
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

    public function __construct(SocketServerInterface $socket, array $arrConfig = array())
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
     * Configures smtp and adds default configs as needed
     * 
     * @param $arrConfig
     */
    private function initConfig(&$arrConfig)
    {
        // default configs
        if (!isset($arrConfig['port']))
            $arrConfig['port'] = 25;
        if (!isset($arrConfig['listenIP']))
            $arrConfig['listenIP'] = 'localhost';
        if (!isset($arrConfig['hostname']))
            $arrConfig['hostname'] = 'kabootar mail';
        if (!isset($arrConfig['mailSizeMax']))
            $arrConfig['mailSizeMax'] = 35882577;

        self::$arrConfig = $arrConfig;        
    }

    /**
     * Initializes socket
     * 
     * @param object $socket instance of SocketServerInterface
     */
    private function initSocket(SocketServerInterface &$socket)
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
