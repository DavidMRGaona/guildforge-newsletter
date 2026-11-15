<?php

declare(strict_types=1);

namespace Modules\Newsletter\Application\Services;

/**
 * How many subscriptions in a row the provider did not take, shared by every worker. Counts one:
 * every queued subscription given up (after its retries, or at once when retrying cannot help) and
 * every address the provider refuses (Rejected). Starts again from zero only when the provider
 * writes to the list (ConfirmationRequested). Answers that write nothing (AlreadyActive,
 * NotReactivable) leave the count as it is: people already on the list joining again must not hide
 * a provider that refuses every new address. The provider health check reads it to look at the
 * connection after several failures in a row.
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
