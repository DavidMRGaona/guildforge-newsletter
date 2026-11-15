<?php

declare(strict_types=1);

namespace Modules\Newsletter\Infrastructure\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Modules\Newsletter\Application\Services\SubscribeFailureCounterInterface;
use Throwable;

/**
 * The failures in a row, in the application cache (shared by every worker). The streak expires a
 * day after its first failure, so an old streak never adds up with a new one.
 *
 * Atomic without a lock: add() creates the key with its expiry only when it is missing or expired,
 * and increment() adds one to what is there and keeps the expiry. With the `database` store (the
 * default here) add() is an insert that loses to a concurrent one, and increment() updates the row
 * inside a transaction that locks it; an expired row counts as missing, so the next failure starts
 * a new streak. If the key expires between both calls, increment() either refuses (database) or
 * creates a key without expiry (Redis): either way the streak starts again at one, with its expiry.
 */
final readonly class CacheSubscribeFailureCounter implements SubscribeFailureCounterInterface
{
    public const string KEY = 'newsletter:subscribe-failures';

    public const int TTL_SECONDS = 86400;

    public function __construct(
        private Repository $cache,
    ) {}

    public function recordFailure(): int
    {
        try {
            if ($this->cache->add(self::KEY, 1, self::TTL_SECONDS)) {
                return 1;
            }

            $count = $this->cache->increment(self::KEY);

            if (! is_int($count) || $count <= 1) {
                // The key expired in between: start again, always with an expiry
                $this->cache->put(self::KEY, 1, self::TTL_SECONDS);

                return 1;
            }

            return $count;
        } catch (Throwable $e) {
            $this->warn($e);

            return 0;
        }
    }

    public function reset(): void
    {
        try {
            $this->cache->forget(self::KEY);
        } catch (Throwable $e) {
            $this->warn($e);
        }
    }

    public function current(): int
    {
        try {
            $count = $this->cache->get(self::KEY, 0);

            return is_numeric($count) ? (int) $count : 0;
        } catch (Throwable $e) {
            $this->warn($e);

            return 0;
        }
    }

    private function warn(Throwable $e): void
    {
        Log::warning('Newsletter: the subscription failure count could not be updated', ['exception' => $e::class]);
    }
}
