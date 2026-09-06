<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Tests\Command;

use PHPUnit\Framework\TestCase;
use Saifulferoz\SymfonyHorizon\Command\HorizonCommand;
use Saifulferoz\SymfonyHorizon\Supervisor\AutoScaler;
use Saifulferoz\SymfonyHorizon\Supervisor\QueueDepthProvider;
use Saifulferoz\SymfonyHorizon\Tests\Fixtures\SpyStorage;

/**
 * Workers are spawned as child processes, so the binary used to start them must
 * be correct on every runtime — PHP_BINARY is empty under FrankenPHP's CLI and
 * can point at a server binary under other embedded SAPIs.
 */
final class PhpBinaryResolutionTest extends TestCase
{
    private function command(?string $phpBinary): HorizonCommand
    {
        $locator = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): object
            {
                throw new \RuntimeException('not used');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        return new HorizonCommand(
            new SpyStorage(),
            new QueueDepthProvider($locator),
            new AutoScaler(),
            [],
            '/app',
            'prod',
            $phpBinary,
        );
    }

    /**
     * @return list<string>
     */
    private function consumeCommand(?string $phpBinary): array
    {
        $method = new \ReflectionMethod(HorizonCommand::class, 'buildConsumeCommand');

        return $method->invoke($this->command($phpBinary), [
            'transports' => ['async'],
            'time_limit' => 3600,
            'memory_limit' => 128,
            'consume_options' => [],
        ]);
    }

    public function testFrankenPhpBinaryIsSplitIntoBinaryAndSubcommand(): void
    {
        $command = $this->consumeCommand('frankenphp php-cli');

        self::assertSame(['frankenphp', 'php-cli', '/app/bin/console', 'messenger:consume', 'async'], \array_slice($command, 0, 5));
    }

    public function testExplicitBinaryWithArgumentsIsPreserved(): void
    {
        $command = $this->consumeCommand('/usr/bin/php -d opcache.enable=0');

        self::assertSame(['/usr/bin/php', '-d', 'opcache.enable=0', '/app/bin/console'], \array_slice($command, 0, 4));
    }

    public function testDefaultsToTheRunningInterpreter(): void
    {
        $command = $this->consumeCommand(null);

        // Under the normal CLI SAPI used for tests, PHP_BINARY is a real path.
        self::assertNotSame('', $command[0], 'the spawn command must never start with an empty program name');
        self::assertSame('/app/bin/console', $command[1]);
    }

    public function testTheSpawnCommandNeverStartsEmpty(): void
    {
        // Process() throws ValueError on an empty program name, which is exactly
        // what a bare PHP_BINARY would produce under FrankenPHP.
        foreach ([null, '', 'frankenphp php-cli'] as $configured) {
            $command = $this->consumeCommand($configured);
            self::assertNotSame('', $command[0], sprintf('binary "%s" produced an empty program name', (string) $configured));
        }
    }
}
