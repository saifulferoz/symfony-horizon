<?php

namespace Saifulferoz\SymfonyHorizon\EventListener;

use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Serializer\SerializerInterface;

class MessengerEventListener implements EventSubscriberInterface
{
    private StorageInterface $storage;
    private ?SerializerInterface $serializer;
    private string $workerId;
    private array $startTime = [];

    public function __construct(StorageInterface $storage, ?SerializerInterface $serializer = null)
    {
        $this->storage = $storage;
        $this->serializer = $serializer;
        $this->workerId = getmypid() . '@' . gethostname();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'onWorkerStarted',
            WorkerRunningEvent::class => 'onWorkerRunning',
            WorkerStoppedEvent::class => 'onWorkerStopped',
            WorkerMessageReceivedEvent::class => 'onMessageReceived',
            WorkerMessageHandledEvent::class => 'onMessageHandled',
            WorkerMessageFailedEvent::class => 'onMessageFailed',
        ];
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        $this->storage->recordWorkerHeartbeat($this->workerId, [
            'id' => $this->workerId,
            'pid' => (string) getmypid(),
            'host' => gethostname(),
            'transports' => implode(', ', $event->getWorker()->getMetadata()->getTransportNames()),
            'started_at' => (string) microtime(true),
            'status' => 'idle',
        ]);
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        // Periodic heartbeat
        $this->storage->recordWorkerHeartbeat($this->workerId, [
            'id' => $this->workerId,
            'pid' => (string) getmypid(),
            'host' => gethostname(),
            'transports' => implode(', ', $event->getWorker()->getMetadata()->getTransportNames()),
            'status' => $event->isIdle() ? 'idle' : 'processing',
        ]);
    }

    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        $this->storage->removeWorker($this->workerId);
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $message = $envelope->getMessage();
        $jobId = $this->getEnvelopeId($envelope);

        $this->startTime[$jobId] = microtime(true);

        $this->storage->recordJobReceived($jobId, [
            'id' => $jobId,
            'class' => get_class($message),
            'queue' => $event->getReceiverName(),
            'status' => 'processing',
            'payload' => $this->serializeMessage($message),
            'tags' => json_encode($this->extractTags($message)),
            'worker_id' => $this->workerId,
            'started_at' => (string) $this->startTime[$jobId],
        ]);

        $this->storage->recordWorkerHeartbeat($this->workerId, [
            'status' => 'processing',
            'current_job_id' => $jobId,
        ]);
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $jobId = $this->getEnvelopeId($envelope);
        $duration = 0.0;

        if (isset($this->startTime[$jobId])) {
            $duration = microtime(true) - $this->startTime[$jobId];
            unset($this->startTime[$jobId]);
        }

        $this->storage->recordJobHandled($jobId, $duration);

        $this->storage->recordWorkerHeartbeat($this->workerId, [
            'status' => 'idle',
            'current_job_id' => '',
        ]);
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $jobId = $this->getEnvelopeId($envelope);
        $exception = $event->getThrowable();

        $this->storage->recordJobFailed($jobId, $exception->getMessage(), $exception->getTraceAsString());

        $this->storage->recordWorkerHeartbeat($this->workerId, [
            'status' => 'idle',
            'current_job_id' => '',
        ]);
    }

    private function getEnvelopeId($envelope): string
    {
        $stamp = $envelope->last(TransportMessageIdStamp::class);
        if ($stamp) {
            return (string) $stamp->getId();
        }

        // Fallback to a hash representing this specific execution cycle
        return md5(spl_object_hash($envelope->getMessage()) . '_' . microtime(true));
    }

    private function serializeMessage(object $message): string
    {
        if ($this->serializer) {
            try {
                return $this->serializer->serialize($message, 'json');
            } catch (\Throwable) {
                // Fallback to json_encode
            }
        }

        return json_encode($message) ?: get_class($message);
    }

    private function extractTags(object $message): array
    {
        $tags = [get_class($message)];
        if (method_exists($message, 'getHorizonTags')) {
            $tags = array_merge($tags, $message->getHorizonTags());
        } elseif (method_exists($message, 'getTags')) {
            $tags = array_merge($tags, $message->getTags());
        }

        return array_unique($tags);
    }
}
