<?php declare(strict_types=1);

namespace Gos\Bundle\WebSocketBundle\Topic;

use Ratchet\Wamp\Topic;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

class TopicPeriodicTimer implements \IteratorAggregate
{
    /**
     * @var array<string, array<string, TimerInterface>>
     */
    protected array $registry = [];
    protected LoopInterface $loop;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

    /**
     * @return TimerInterface[]
     */
    public function getPeriodicTimers(Topic $topic): array
    {
        return $this->registry[$topic->getId()] ?? [];
    }

    /**
     * @param int|float $timeout
     */
    public function addPeriodicTimer(Topic $topic, string $name, $timeout, callable $callback): void
    {
        if (!isset($this->registry[$topic->getId()])) {
            $this->registry[$topic->getId()] = [];
        }

        $this->registry[$topic->getId()][$name] = $this->loop->addPeriodicTimer($timeout, $callback);
    }

    public function isRegistered(Topic $topic): bool
    {
        return isset($this->registry[$topic->getId()]);
    }

    public function isPeriodicTimerActive(Topic $topic, string $name): bool
    {
        return isset($this->registry[$topic->getId()][$name]);
    }

    public function cancelPeriodicTimer(Topic $topic, string $name): void
    {
        if (!isset($this->registry[$topic->getId()][$name])) {
            return;
        }

        $timer = $this->registry[$topic->getId()][$name];
        $this->loop->cancelTimer($timer);
        unset($this->registry[$topic->getId()][$name]);
    }

    public function clearPeriodicTimer(Topic $topic): void
    {
        unset($this->registry[$topic->getId()]);
    }

    /**
     * @return \ArrayIterator|array<string, array<string, TimerInterface>>
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->registry);
    }
}
