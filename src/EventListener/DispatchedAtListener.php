<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\EventListener;

use Saifulferoz\SymfonyHorizon\Stamp\DispatchedAtStamp;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;

/**
 * Stamps async messages with the dispatch time so workers can report queue
 * wait time. This is the bundle's only dispatch-side hook, it costs a single
 * object allocation, and it can be disabled with metrics.wait_time_stamp: false.
 */
final class DispatchedAtListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            SendMessageToTransportsEvent::class => ['onSend', -255],
        ];
    }

    public function onSend(SendMessageToTransportsEvent $event): void
    {
        $event->setEnvelope($event->getEnvelope()->with(new DispatchedAtStamp()));
    }
}
