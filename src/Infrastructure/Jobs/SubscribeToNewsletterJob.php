<?php

declare(strict_types=1);

namespace Modules\Newsletter\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\Newsletter\Application\Services\NewsletterProviderInterface;
use Modules\Newsletter\Application\Services\SubscribeFailureCounterInterface;
use Modules\Newsletter\Domain\Enums\ConnectionFailure;
use Modules\Newsletter\Domain\Enums\SubscribeOutcome;
use Modules\Newsletter\Domain\Exceptions\NewsletterProviderException;
use Modules\Newsletter\Domain\ValueObjects\SubscriberHash;
use SensitiveParameter;
use Throwable;

/**
 * Asks the provider to add an address to the list (double opt-in), outside the request.
 *
 * The payload is encrypted (the queue is the database, and failed jobs keep their payload) and
 * carries only the address and the name: the provider, with its key, is resolved in handle(), so
 * the key is never serialized. A failure the retries cannot fix (key refused, list gone, nothing
 * configured) gives up at once; the rest is tried again with waits. Giving up happens inside
 * handle(), which logs only the subscriber hash, counts the failure and returns, so the worker
 * never sees a failed job and never writes it to failed_jobs. failed() stays as a safety net for
 * what handle() cannot catch (a timeout, a worker killed mid-job): it does the same and removes the
 * copy the worker writes.
 */
final class SubscribeToNewsletterJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use ForgetsFailedJobCopy;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 4;

    /** Failures that trying again would not fix */
    private const array PERMANENT = [
        ConnectionFailure::InvalidApiKey,
        ConnectionFailure::ListNotFound,
        ConnectionFailure::NotConfigured,
        ConnectionFailure::Impersonation,
    ];

    public function __construct(
        #[SensitiveParameter] public readonly string $email,
        #[SensitiveParameter] public readonly ?string $name = null,
    ) {}

    /**
     * @return list<int> Seconds to wait before each retry
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(NewsletterProviderInterface $provider, SubscribeFailureCounterInterface $failures): void
    {
        try {
            $outcome = $provider->subscribe($this->email, $this->name);
        } catch (Throwable $e) {
            $permanent = $e instanceof NewsletterProviderException && in_array($e->failure, self::PERMANENT, true);

            if (! $permanent && $this->attempts() < $this->tries) {
                // The worker tries again after the next wait
                throw $e;
            }

            $this->giveUp($e, $failures);

            return;
        }

        match ($outcome) {
            // The only answer that writes to the list: the provider is working
            SubscribeOutcome::ConfirmationRequested => $failures->reset(),
            // The provider refused the address itself: an answer, not a reason to try again
            SubscribeOutcome::Rejected => $failures->recordFailure(),
            // Nothing was written (already on the list, bounced): says nothing about the provider
            SubscribeOutcome::AlreadyActive, SubscribeOutcome::NotReactivable => null,
        };
    }

    /**
     * Safety net: the worker calls it only for what handle() could not catch (a timeout, too many
     * attempts after a worker was killed).
     */
    public function failed(?Throwable $exception): void
    {
        $this->giveUp($exception, app(SubscribeFailureCounterInterface::class));
        $this->forgetFailedCopy();
    }

    /**
     * The subscription will not be tried again: log the hash (never the message: an exception may
     * carry the address) and count the failure, unless nothing left the site.
     */
    private function giveUp(?Throwable $exception, SubscribeFailureCounterInterface $failures): void
    {
        $failure = $exception instanceof NewsletterProviderException ? $exception->failure : null;

        Log::warning('Newsletter: a subscription could not be sent to the provider', [
            'subscriber_hash' => SubscriberHash::fromEmail($this->email)->value(),
            'failure' => $failure->value ?? ($exception instanceof Throwable ? $exception::class : 'unknown'),
        ]);

        if ($failure !== ConnectionFailure::Impersonation) {
            $failures->recordFailure();
        }
    }
}
