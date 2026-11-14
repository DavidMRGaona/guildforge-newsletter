<?php

declare(strict_types=1);

namespace Modules\Newsletter\Application\Services;

/**
 * How many subscriptions in a row the provider did not take: every queued subscription that fails
 * for good (after its retries) and every address the provider refuses counts one; any subscription
 * the provider answers on purpose (confirmation sent, already on the list, bounced) starts again
 * from zero. Shared by every worker. The provider health check reads it to look at the connection
 * after several failures in a row.
 *
 * It never throws: a counter that cannot be read or written counts nothing.
 */
interface SubscribeFailureCounterInterface
{
    /**
     * Count one more failure and return the failures in a row (0 when it could not be counted).
     */
    public function recordFailure(): int;

    /**
     * A subscription went through: start again from zero.
     */
    public function reset(): void;

    public function current(): int;
}
