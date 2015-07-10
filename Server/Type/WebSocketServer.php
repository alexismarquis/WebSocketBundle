<?php

namespace Gos\Bundle\WebSocketBundle\Server\Type;

use Gos\Bundle\WebSocketBundle\Event\Events;
use Gos\Bundle\WebSocketBundle\Event\ServerEvent;
use Gos\Bundle\WebSocketBundle\Periodic\PeriodicInterface;
use Gos\Bundle\WebSocketBundle\Periodic\PeriodicMemoryUsage;
use Gos\Bundle\WebSocketBundle\Pusher\NullPusher;
use Gos\Bundle\WebSocketBundle\Pusher\PusherInterface;
use Gos\Bundle\WebSocketBundle\Pusher\Zmq\ZmqPusher;
use Gos\Bundle\WebSocketBundle\Server\App\Registry\OriginRegistry;
use Gos\Bundle\WebSocketBundle\Server\App\Registry\PeriodicRegistry;
use Gos\Bundle\WebSocketBundle\Server\App\WampApplication;
use Gos\Component\RatchetStack\Builder;
use ProxyManager\Proxy\ProxyInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\LoopInterface;
use React\Socket\Server;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NullSessionHandler;
use React\ZMQ\Context;

/**
 * @author Johann Saunier <johann_27@hotmail.fr>
 */
class WebSocketServer implements ServerInterface
{
    /** @var  LoopInterface */
    protected $loop;

    /**
     * @var \SessionHandler|null
     */
    protected $sessionHandler;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * @var PeriodicRegistry
     */
    protected $periodicRegistry;

    /**
     * @var WampApplication
     */
    protected $wampApplication;

    /**
     * @var OriginRegistry|null
     */
    protected $originRegistry;

    /**
     * @var bool
     */
    protected $originCheck;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /** @var  PusherInterface */
    protected $pusher;

    /**
     * @param LoopInterface            $loop
     * @param EventDispatcherInterface $eventDispatcher
     * @param PeriodicRegistry         $periodicRegistry
     * @param WampApplication          $wampApplication
     * @param OriginRegistry           $originRegistry
     * @param bool                         $originCheck
     * @param PusherInterface          $pusher
     * @param LoggerInterface|null     $logger
     */
    public function __construct(
        LoopInterface $loop,
        EventDispatcherInterface $eventDispatcher,
        PeriodicRegistry $periodicRegistry,
        WampApplication $wampApplication,
        OriginRegistry $originRegistry,
        $originCheck,
        PusherInterface $pusher,
        LoggerInterface $logger = null
    ) {
        $this->loop = $loop;
        $this->eventDispatcher = $eventDispatcher;
        $this->periodicRegistry = $periodicRegistry;
        $this->wampApplication = $wampApplication;
        $this->originRegistry = $originRegistry;
        $this->originCheck = $originCheck;
        $this->pusher = $pusher;
        $this->logger = null === $logger ? new NullLogger() : $logger;
        $this->sessionHandler = new NullSessionHandler();
    }

    /**
     * @param \SessionHandlerInterface $sessionHandler
     */
    public function setSessionHandler(\SessionHandlerInterface $sessionHandler)
    {
        $this->sessionHandler = $sessionHandler;
    }

    /**
     * @param bool $profile
     *
     * @throws \React\Socket\ConnectionException
     */
    public function launch($host, $port, $profile)
    {
        $this->logger->info('Starting web socket');

        $stack = new Builder();

        $server = new Server($this->loop);
        $server->listen($port, $host);

        if (true === $profile) {
            $memoryUsagePeriodicTimer = new PeriodicMemoryUsage($this->logger);
            $this->periodicRegistry->addPeriodic($memoryUsagePeriodicTimer);
        }

        /** @var PeriodicInterface $periodic */
        foreach ($this->periodicRegistry->getPeriodics() as $periodic) {
            $this->loop->addPeriodicTimer($periodic->getTimeout(), [$periodic, 'tick']);

            $this->logger->info(sprintf(
                'Register periodic callback %s, executed each %s seconds',
                $periodic instanceof ProxyInterface ? get_parent_class($periodic) : get_class($periodic),
                $periodic->getTimeout()
            ));
        }

        $allowedOrigins = array_merge(array('localhost', '127.0.0.1'), $this->originRegistry->getOrigins());

        $stack
            ->push('Ratchet\Server\IoServer', $server, $this->loop)
            ->push('Ratchet\Http\HttpServer');

        if ($this->originCheck) {
            $stack->push('Gos\Bundle\WebSocketBundle\Server\App\Stack\OriginCheck', $allowedOrigins, $this->eventDispatcher);
        }

        $stack
            ->push('Ratchet\WebSocket\WsServer')
            ->push('Gos\Bundle\WebSocketBundle\Server\App\Stack\WampConnectionPeriodicTimer', $this->loop)
            ->push('Ratchet\Session\SessionProvider', $this->sessionHandler)
            ->push('Ratchet\Wamp\WampServer');

        $app = $stack->resolve($this->wampApplication);

        //Transport layer
        if(!$this->pusher instanceof NullPusher){
            switch(true){
                case $this->pusher instanceof ZmqPusher :
                    $pusherConfig = $this->pusher->getConfig();
                    $context = new Context($this->loop);
                    $pull = $context->getSocket(\ZMQ::SOCKET_PULL);

                    $this->logger->info(sprintf(
                        'ZMQ transport listening on %s:%s',
                        $pusherConfig['host'],
                        $pusherConfig['port']
                    ));

                    $pull->bind($pusherConfig['host'].':'.$pusherConfig['port']);
                    $pull->on('message', array($this->wampApplication, 'onPush'));
                break;
            }
        }

        /* Server Event Loop to add other services in the same loop. */
        $event = new ServerEvent($this->loop, $server);
        $this->eventDispatcher->dispatch(Events::SERVER_LAUNCHED, $event);

        $this->logger->info(sprintf(
            'Launching %s on %s PID: %s',
            $this->getName(),
            $host.':'.$port,
            getmypid()
        ));

        $app->run();
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'Ratchet';
    }
}
