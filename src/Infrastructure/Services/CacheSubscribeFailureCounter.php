<?php

declare(strict_types=1);

namespace Modules\Newsletter\Infrastructure\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Modules\Newsletter\Application\Services\SubscribeFailureCounterInterface;
use Throwable;

/**
 * The failures in a row, in the application cache (shared by every worker). A count that nobody
 * resets fades after a day, so an old streak never adds up with a new one.
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
            // add() only writes when the key is missing, so the expiry is set once per streak
            $this->cache->add(self::KEY, 0, self::TTL_SECONDS);
            $count = $this->cache->increment(self::KEY);

            return is_int($count) ? $count : 0;
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
