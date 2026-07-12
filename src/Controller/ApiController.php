<?php

namespace Saifulferoz\SymfonyHorizon\Controller;

use Psr\Container\ContainerInterface;
use Saifulferoz\SymfonyHorizon\Storage\StorageInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ApiController
{
    private StorageInterface $storage;
    private MessageBusInterface $messageBus;
    private array $config;
    private ?ContainerInterface $receiverLocator;
    private ?AuthorizationCheckerInterface $authorizationChecker;
    private ?string $role;

    public function __construct(
        StorageInterface $storage,
        MessageBusInterface $messageBus,
        array $config,
        ?ContainerInterface $receiverLocator = null,
        ?AuthorizationCheckerInterface $authorizationChecker = null,
        ?string $role = null
    ) {
        $this->storage = $storage;
        $this->messageBus = $messageBus;
        $this->config = $config;
        $this->receiverLocator = $receiverLocator;
        $this->authorizationChecker = $authorizationChecker;
        $this->role = $role;
    }

    #[Route('/horizon/api/stats', name: 'symfony_horizon_api_stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        $this->denyAccessUnlessGranted();

        $metrics = $this->storage->getDashboardMetrics();

        // Add active count from supervisor heartbeats
        $supervisors = $this->storage->getSupervisors();
        $workersCount = 0;
        foreach ($supervisors as $name => $meta) {
            if ($name !== 'master') {
                $workersCount += (int) ($meta['active_workers'] ?? 0);
            }
        }

        return new JsonResponse(array_merge($metrics, [
            'active_workers' => $workersCount,
            'status' => isset($supervisors['master']) ? 'active' : 'inactive',
        ]));
    }

    #[Route('/horizon/api/supervisors', name: 'symfony_horizon_api_supervisors', methods: ['GET'])]
    public function supervisors(): JsonResponse
    {
        $this->denyAccessUnlessGranted();

        return new JsonResponse($this->storage->getSupervisors());
    }

    #[Route('/horizon/api/workloads', name: 'symfony_horizon_api_workloads', methods: ['GET'])]
    public function workloads(): JsonResponse
    {
        $this->denyAccessUnlessGranted();

        $workloads = [];
        $supervisorsConfig = $this->config['supervisors'] ?? [];

        foreach ($supervisorsConfig as $name => $supConfig) {
            $queues = $supConfig['queues'] ?? [$supConfig['connection']];
            foreach ($queues as $queue) {
                $pending = 0;
                if ($this->receiverLocator && $this->receiverLocator->has($queue)) {
                    $receiver = $this->receiverLocator->get($queue);
                    if ($receiver instanceof MessageCountAwareInterface) {
                        $pending = $receiver->getMessageCount();
                    }
                }

                $workloads[$queue] = [
                    'queue' => $queue,
                    'pending' => $pending,
                    'connection' => $supConfig['connection'],
                ];
            }
        }

        return new JsonResponse(array_values($workloads));
    }

    #[Route('/horizon/api/jobs/recent', name: 'symfony_horizon_api_jobs_recent', methods: ['GET'])]
    public function recent(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted();

        $limit = $request->query->getInt('limit', 50);
        $offset = $request->query->getInt('offset', 0);

        return new JsonResponse($this->storage->getRecentJobs($limit, $offset));
    }

    #[Route('/horizon/api/jobs/failed', name: 'symfony_horizon_api_jobs_failed', methods: ['GET'])]
    public function failed(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted();

        $limit = $request->query->getInt('limit', 50);
        $offset = $request->query->getInt('offset', 0);

        return new JsonResponse($this->storage->getFailedJobs($limit, $offset));
    }

    #[Route('/horizon/api/jobs/failed/retry/{id}', name: 'symfony_horizon_api_failed_retry', methods: ['POST'])]
    public function retry(string $id): JsonResponse
    {
        $this->denyAccessUnlessGranted();

        $failureTransport = $this->config['failure_transport'] ?? 'failed';

        if ($this->receiverLocator && $this->receiverLocator->has($failureTransport)) {
            $receiver = $this->receiverLocator->get($failureTransport);
            if ($receiver instanceof ListableReceiverInterface) {
                $envelope = $receiver->find($id);
                if ($envelope) {
                    // Re-dispatch the message to the original bus, clearing any delay stamps
                    $this->messageBus->dispatch($envelope->withoutStampsOfType(DelayStamp::class));
                    // Reject from failed receiver
                    $receiver->reject($envelope);
                    // Remove from local log
                    $this->storage->deleteFailedJob($id);

                    return new JsonResponse(['success' => true]);
                }
            }
        }

        return new JsonResponse(['error' => 'Job or failure transport not found.'], 404);
    }

    #[Route('/horizon/api/jobs/failed/delete/{id}', name: 'symfony_horizon_api_failed_delete', methods: ['POST'])]
    public function delete(string $id): JsonResponse
    {
        $this->denyAccessUnlessGranted();

        $failureTransport = $this->config['failure_transport'] ?? 'failed';

        if ($this->receiverLocator && $this->receiverLocator->has($failureTransport)) {
            $receiver = $this->receiverLocator->get($failureTransport);
            if ($receiver instanceof ListableReceiverInterface) {
                $envelope = $receiver->find($id);
                if ($envelope) {
                    $receiver->reject($envelope);
                }
            }
        }

        $this->storage->deleteFailedJob($id);
        return new JsonResponse(['success' => true]);
    }

    private function denyAccessUnlessGranted(): void
    {
        if (!$this->authorizationChecker || !$this->role) {
            return;
        }

        if (!$this->authorizationChecker->isGranted($this->role)) {
            throw new AccessDeniedException(sprintf('Access Denied. Required role: %s', $this->role));
        }
    }
}
