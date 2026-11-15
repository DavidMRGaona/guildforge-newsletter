<?php

declare(strict_types=1);

namespace Modules\Newsletter\Application\Services;

use Modules\Newsletter\Application\DTOs\ConnectionCheckRun;

/**
 * Whether Mailchimp still accepts the saved key and list: the daily check, the one asked for after
 * failed subscriptions, and the notice to whoever configures the module when it stops working.
 */
interface ConnectionHealthInterface
{
    /**
     * Ask the provider with the saved key and list, record the answer and, when a working
     * connection has just stopped working, tell whoever can configure the module (once per outage).
     * A failed check switches the module off; a good one switches it on again.
     *
     * A provider that cannot be reached (timeout, 429, 5xx) is not an outage at the first answer:
     * it counts only when a second such answer comes at least 10 minutes after the first, with
     * nothing else in between. Any other failure (key refused, list gone) counts at once.
     *
     * Never throws: a check that could not run comes back as `Error`.
     */
    public function check(): ConnectionCheckRun;

    /**
     * Whether a short-interval check is worth making: the last check failed (the module is off
     * and should come back as soon as the provider does) or an unreachable provider awaits its
     * confirmation. The scheduler asks it every 30 minutes.
     */
    public function needsRecheck(): bool;

    /**
     * Called every time a subscription fails for good, with the failures in a row so far. From the
     * third it only queues a check, at most one every 15 minutes: it never switches the module off
     * by itself, so nobody can take the newsletter down by sending bad addresses.
     *
     * @return bool Whether a check was queued
     */
    public function checkAfterFailures(int $consecutiveFailures): bool;

    /**
     * A subscription went through: the next streak of refused sign-ups may be told about again.
     */
    public function subscriptionsWorking(): void;
}
