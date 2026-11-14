<?php

declare(strict_types=1);

namespace Modules\Newsletter\Tests\Feature;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Modules\Newsletter\Application\Services\SubscribeFailureCounterInterface;
use Modules\Newsletter\Infrastructure\Services\CacheSubscribeFailureCounter;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;

final class SubscribeFailureCounterTest extends ModuleTestCase
{
    protected ?string $moduleName = 'newsletter';

    protected bool $autoEnableModule = true;

    public function test_the_implementation_is_the_one_bound(): void
    {
        $this->assertInstanceOf(CacheSubscribeFailureCounter::class, $this->counter());
    }

    public function test_failures_in_a_row_add_up_until_a_subscription_goes_through(): void
    {
        $this->assertSame(0, $this->counter()->current());
        $this->assertSame(1, $this->counter()->recordFailure());
        $this->assertSame(2, $this->counter()->recordFailure());
        $this->assertSame(3, $this->counter()->recordFailure());

        $this->counter()->reset();

        $this->assertSame(0, $this->counter()->current());
        $this->assertSame(1, $this->counter()->recordFailure());
    }

    public function test_the_count_is_shared_between_workers(): void
    {
        $this->counter()->recordFailure();

        // Another process builds its own counter over the same cache
        $other = new CacheSubscribeFailureCounter(Cache::store());

        $this->assertSame(2, $other->recordFailure());
    }

    public function test_a_cache_that_fails_never_breaks_a_subscription(): void
    {
        $cache = $this->createStub(Repository::class);
        $cache->method('increment')->willThrowException(new RuntimeException('cache down'));
        $cache->method('add')->willThrowException(new RuntimeException('cache down'));
        $cache->method('forget')->willThrowException(new RuntimeException('cache down'));
        $cache->method('get')->willThrowException(new RuntimeException('cache down'));
        $counter = new CacheSubscribeFailureCounter($cache);

        $this->assertSame(0, $counter->recordFailure());
        $this->assertSame(0, $counter->current());
        $counter->reset();
    }

    private function counter(): SubscribeFailureCounterInterface
    {
        return $this->app->make(SubscribeFailureCounterInterface::class);
    }
}
