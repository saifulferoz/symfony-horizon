<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\EventListener;

use Saifulferoz\SymfonyHorizon\Collector\MetricsCollector;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * Bridges Messenger worker events to the MetricsCollector. These events only
 * fire inside messenger:consume processes, so neither this subscriber nor the
 * collector is ever instantiated during a web request.
 */
final class WorkerEventSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly MetricsCollector $collector)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'onWorkerStarted',
            WorkerRunningEvent::class => 'onWorkerRunning',
            WorkerStoppedEvent::class => 'onWorkerStopped',
            // Low priority on "received" and high on "handled" keeps the measured
            // window as close to the actual handling as possible. "failed" must run
            // AFTER messenger's SendFailedMessageForRetryListener (priority 100),
            // otherwise willRetry() hasn't been decided yet.
            WorkerMessageReceivedEvent::class => ['onMessageReceived', -1024],
            WorkerMessageHandledEvent::class => ['onMessageHandled', 1024],
            WorkerMessageFailedEvent::class => ['onMessageFailed', -1024],
        ];
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        $this->collector->workerStarted($event->getWorker()->getMetadata()->getTransportNames());
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        $this->collector->workerRunning($event->isWorkerIdle());
    }

    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        $this->collector->workerStopped();
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->collector->jobReceived($event->getEnvelope(), $event->getReceiverName());
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->collector->jobHandled($event->getEnvelope());
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $this->collector->jobFailed($event->getEnvelope(), $event->getThrowable(), $event->willRetry());
    }
}
