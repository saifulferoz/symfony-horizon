<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Supervisor;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Owns the messenger:consume child processes for one supervisor.
 */
class WorkerPool
{
    /** @var list<Process> */
    private array $processes = [];

    /**
     * @param list<string> $command full messenger:consume command line
     */
    public function __construct(
        private readonly array $command,
        private readonly ?OutputInterface $output = null,
    ) {
    }

    public function count(): int
    {
        return \count($this->processes);
    }

    /** @return list<int> */
    public function pids(): array
    {
        $pids = [];
        foreach ($this->processes as $process) {
            $pid = $process->getPid();
            if ($pid !== null) {
                $pids[] = $pid;
            }
        }

        return $pids;
    }

    /**
     * Drops exited processes from the pool so scaleTo() can replace them.
     *
     * @return int number of processes that had exited
     */
    public function reap(): int
    {
        $before = \count($this->processes);
        $this->processes = array_values(array_filter(
            $this->processes,
            static fn (Process $process): bool => $process->isRunning(),
        ));

        return $before - \count($this->processes);
    }

    public function scaleTo(int $desired): void
    {
        $desired = max(0, $desired);

        while (\count($this->processes) < $desired) {
            $this->processes[] = $this->spawn();
        }

        while (\count($this->processes) > $desired) {
            // Stop the most recently started worker first.
            $process = array_pop($this->processes);
            $this->stop($process);
        }
    }

    public function stopAll(): void
    {
        // Signal every worker first so they wind down in parallel...
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->signal(\SIGTERM);
            }
        }
        // ...then wait for each to finish its in-flight message.
        foreach ($this->processes as $process) {
            $this->stop($process);
        }
        $this->processes = [];
    }

    protected function spawn(): Process
    {
        $process = new Process($this->command);
        $process->setTimeout(null);

        $output = $this->output;
        $process->start($output !== null
            ? static function (string $type, string $buffer) use ($output): void {
                $output->write($buffer);
            }
            : null);

        $this->output?->writeln(sprintf('<info>[horizon]</info> started worker pid %d', $process->getPid() ?? 0));

        return $process;
    }

    private function stop(Process $process): void
    {
        if (!$process->isRunning()) {
            return;
        }

        $pid = $process->getPid();
        // messenger:consume finishes the in-flight message on SIGTERM;
        // Process::stop() escalates to SIGKILL after the grace period.
        $process->stop(30, \SIGTERM);

        $this->output?->writeln(sprintf('<info>[horizon]</info> stopped worker pid %d', $pid ?? 0));
    }
}
