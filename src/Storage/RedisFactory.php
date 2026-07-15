<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Storage;

/**
 * Creates a Redis client from a DSN, preferring phpredis over Predis.
 * DSN format: redis[s]://[user:password@]host[:port][/db]
 */
final class RedisFactory
{
    public static function create(string $dsn): object
    {
        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['host'])) {
            throw new \InvalidArgumentException(sprintf('Invalid Redis DSN "%s".', $dsn));
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? 6379;
        $password = isset($parts['pass']) ? urldecode($parts['pass']) : null;
        $user = isset($parts['user']) && $parts['user'] !== '' ? urldecode($parts['user']) : null;
        $db = isset($parts['path']) ? (int) ltrim($parts['path'], '/') : 0;
        $tls = ($parts['scheme'] ?? 'redis') === 'rediss';

        if (\extension_loaded('redis')) {
            $redis = new \Redis();
            $redis->connect(($tls ? 'tls://' : '') . $host, $port, 2.5);
            if ($password !== null) {
                $user !== null ? $redis->auth([$user, $password]) : $redis->auth($password);
            }
            if ($db > 0) {
                $redis->select($db);
            }

            return $redis;
        }

        if (class_exists(\Predis\Client::class)) {
            $options = [
                'scheme' => $tls ? 'tls' : 'tcp',
                'host' => $host,
                'port' => $port,
                'database' => $db,
            ];
            if ($password !== null) {
                $options['password'] = $password;
            }
            if ($user !== null) {
                $options['username'] = $user;
            }

            return new \Predis\Client($options);
        }

        throw new \LogicException('Symfony Horizon needs a Redis client: install the "redis" PHP extension or run "composer require predis/predis".');
    }
}
