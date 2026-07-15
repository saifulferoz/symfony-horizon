<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Tests\Fixtures;

/**
 * Minimal in-memory Redis double implementing the commands RedisStorage uses,
 * with a Predis-compatible pipeline(callable): array interface.
 */
final class FakeRedis
{
    /** @var array<string, string> */
    public array $strings = [];
    /** @var array<string, array<string, string>> */
    public array $hashes = [];
    /** @var array<string, array<string, bool>> */
    public array $sets = [];
    /** @var array<string, array<string, float>> member => score */
    public array $zsets = [];
    /** @var array<string, list<string>> */
    public array $lists = [];
    /** @var array<string, int> */
    public array $ttls = [];

    /** @return list<mixed> */
    public function pipeline(callable $commands): array
    {
        $recorder = new class($this) {
            /** @var list<mixed> */
            public array $results = [];

            public function __construct(private readonly FakeRedis $redis)
            {
            }

            /** @param list<mixed> $args */
            public function __call(string $method, array $args): mixed
            {
                $this->results[] = $this->redis->{$method}(...$args);

                return $this;
            }
        };

        $commands($recorder);

        return $recorder->results;
    }

    /** @param array<string, string|int|float> $map */
    public function hmset(string $key, array $map): bool
    {
        foreach ($map as $field => $value) {
            $this->hashes[$key][(string) $field] = (string) $value;
        }

        return true;
    }

    /** @return array<string, string> */
    public function hgetall(string $key): array
    {
        return $this->hashes[$key] ?? [];
    }

    public function hincrby(string $key, string $field, int $by): int
    {
        $value = (int) ($this->hashes[$key][$field] ?? 0) + $by;
        $this->hashes[$key][$field] = (string) $value;

        return $value;
    }

    public function hincrbyfloat(string $key, string $field, float $by): float
    {
        $value = (float) ($this->hashes[$key][$field] ?? 0) + $by;
        $this->hashes[$key][$field] = (string) $value;

        return $value;
    }

    public function expire(string $key, int $ttl): bool
    {
        $this->ttls[$key] = $ttl;

        return true;
    }

    /** @param array<string, float|int> $memberScores Predis-style */
    public function zadd(string $key, array $memberScores): int
    {
        foreach ($memberScores as $member => $score) {
            $this->zsets[$key][(string) $member] = (float) $score;
        }

        return \count($memberScores);
    }

    public function zrem(string $key, string $member): int
    {
        $existed = isset($this->zsets[$key][$member]);
        unset($this->zsets[$key][$member]);

        return $existed ? 1 : 0;
    }

    public function zcard(string $key): int
    {
        return \count($this->zsets[$key] ?? []);
    }

    /** @return list<string> */
    public function zrevrange(string $key, int $start, int $stop): array
    {
        $zset = $this->zsets[$key] ?? [];
        arsort($zset);
        $members = array_keys($zset);
        $length = $stop === -1 ? null : $stop - $start + 1;

        return \array_slice($members, $start, $length);
    }

    public function zremrangebyscore(string $key, string $min, string $max): int
    {
        $removed = 0;
        $minScore = $min === '-inf' ? -\INF : (float) $min;
        $maxScore = $max === '+inf' ? \INF : (float) $max;
        foreach ($this->zsets[$key] ?? [] as $member => $score) {
            if ($score >= $minScore && $score <= $maxScore) {
                unset($this->zsets[$key][$member]);
                ++$removed;
            }
        }

        return $removed;
    }

    public function sadd(string $key, string $member): int
    {
        $added = !isset($this->sets[$key][$member]);
        $this->sets[$key][$member] = true;

        return $added ? 1 : 0;
    }

    public function srem(string $key, string $member): int
    {
        $existed = isset($this->sets[$key][$member]);
        unset($this->sets[$key][$member]);

        return $existed ? 1 : 0;
    }

    /** @return list<string> */
    public function smembers(string $key): array
    {
        return array_keys($this->sets[$key] ?? []);
    }

    public function del(string $key): int
    {
        $existed = isset($this->strings[$key]) || isset($this->hashes[$key]) || isset($this->sets[$key]) || isset($this->zsets[$key]) || isset($this->lists[$key]);
        unset($this->strings[$key], $this->hashes[$key], $this->sets[$key], $this->zsets[$key], $this->lists[$key]);

        return $existed ? 1 : 0;
    }

    public function get(string $key): ?string
    {
        return $this->strings[$key] ?? null;
    }

    public function incrby(string $key, int $by): int
    {
        $value = (int) ($this->strings[$key] ?? 0) + $by;
        $this->strings[$key] = (string) $value;

        return $value;
    }

    public function rpush(string $key, string $value): int
    {
        $this->lists[$key][] = $value;

        return \count($this->lists[$key]);
    }

    public function lpop(string $key): ?string
    {
        if (($this->lists[$key] ?? []) === []) {
            return null;
        }

        return array_shift($this->lists[$key]);
    }
}
