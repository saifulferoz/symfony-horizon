<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Command;

use Saifulferoz\SymfonyHorizon\Realtime\DashboardBroadcaster;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Publishes dashboard snapshots without running the Horizon supervisor.
 *
 * `bin/console horizon` already broadcasts on each tick, so this is for
 * deployments whose workers are managed elsewhere — Caddy's supervisor
 * directive, systemd units, or Kubernetes running messenger:consume directly —
 * where no Horizon process exists to do the publishing.
 */
#[AsCommand(name: 'horizon:broadcast', description: 'Push dashboard updates in real time without running the supervisor')]
final class HorizonBroadcastCommand extends Command implements SignalableCommandInterface
{
    private const SLEEP_SLICE_US = 100_000;

    /** Set from handleSignal(), which a static analyser cannot observe. */
    private bool $shouldStop = false;

    public function __construct(private readonly DashboardBroadcaster $broadcaster)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many seconds (0 = run forever)', '0')
            ->setHelp(<<<'HELP'
                Run this as a long-lived process alongside your workers when they are
                managed by something other than <info>bin/console horizon</info>:

                  <info>bin/console horizon:broadcast</info>

                It only publishes when <comment>dashboard.realtime.transport</comment> is
                "mercure"; in polling mode it exits immediately.
                HELP);
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return \defined('SIGTERM') ? [\SIGTERM, \SIGINT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->shouldStop = true;

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->broadcaster->publishNow()) {
            $output->writeln('<comment>Nothing to broadcast.</comment> Real-time updates are off; set <info>symfony_horizon.dashboard.realtime.transport: mercure</info> to enable them.');

            return Command::SUCCESS;
        }

        $timeLimit = (int) $input->getOption('time-limit');
        $deadline = $timeLimit > 0 ? microtime(true) + $timeLimit : null;

        $output->writeln('<info>Broadcasting dashboard updates.</info> Press Ctrl+C to stop.');

        // do/while: the first tick always runs, and the flag is only ever
        // cleared by a signal or the time limit, both evaluated at the bottom.
        do {
            $this->broadcaster->tick();

            // Sleep in slices so a termination signal is honoured promptly.
            $until = microtime(true) + 1;
            while (!$this->shouldStop && microtime(true) < $until) {
                usleep(self::SLEEP_SLICE_US);
            }

            if ($deadline !== null && microtime(true) >= $deadline) {
                $this->shouldStop = true;
            }
        } while (!$this->shouldStop);

        $output->writeln('<info>Broadcast stopped.</info>');

        return Command::SUCCESS;
    }
}
