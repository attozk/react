<?php
namespace React\Smtp;

//use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\Socket\Server as SocketServer;

class Server extends SocketServer //EventEmitter
{
    /**
     * @var SocketServer
     */
    //private $socket;

    /**
     * @var LoopInterface
     */
    //private $loop;

    /** 
     * smtp config - making it static so Client class can access config
     * without passing config (duplicate) for each client.
     *
     * @var array
     */
    private static $arrConfig;
    
    private $version = 0.1;

    public function __construct(LoopInterface $loop, array $arrConfig = array())
    {
        parent::__construct($loop);
        $this->initConfig($arrConfig);
        $this->initSocket();
        $this->initEvents();

        /*
         *
        $self =& $this;
        $loop->addPeriodicTimer(30, function () use($self)
        {
            printr($self);
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
     * relayFromHosts: array() of hosts which for which relay is supported
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
            'relayFromHosts' => array(),
            'supportedDomains' => array()
        );

        foreach($arrDefaultConfig as $conf => $value)
        {
            if (!isset($arrConfig[$conf]))
                $arrConfig[$conf] = $value;
        }

        #// supportedDomains to also include hostname
        #array_push($arrConfig['supportedDomains'], $arrConfig['hostname']);

        self::$arrConfig = $arrConfig;
    }

    /**
     * Initializes socket
     * 
     * @param object $socket instance of SocketServer
     */
    private function initSocket(/*SocketServer $socket*/)
    {
        $this->listen(
                        $this->getConfig('port'),
                        $this->getConfig('listenIP'));
    }

    /**
     * Initializes event binding
     */
    private function initEvents()
    {
        $this->on('connection', function($conn)
        {
            $client = new Client($conn);

            $client->on('stream', function($client)
            {
                $this->emit($client->getCommand(), array($client));
            });

            $conn->on('data', function($data) use($client)
            {
                $client->feed($data);
            });

            $conn->on('close', function($conn) use ($client)
            {
                $client->removeAllListeners();
                $this->emit('CLOSE', array($client));
                unset($client);
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
