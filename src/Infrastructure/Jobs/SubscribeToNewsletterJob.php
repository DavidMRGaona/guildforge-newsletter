<?php

declare(strict_types=1);

namespace Modules\Newsletter\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobFailed;
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
 * configured) fails at once; the rest is tried again with waits. A job that fails for good logs
 * only the subscriber hash and leaves no copy in failed_jobs.
 */
final class SubscribeToNewsletterJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
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
        } catch (NewsletterProviderException $e) {
            if (in_array($e->failure, self::PERMANENT, true)) {
                $this->fail($e);

                return;
            }

            throw $e;
        }

        // The provider refused the address itself: an answer, not a reason to try again
        if ($outcome === SubscribeOutcome::Rejected) {
            $failures->recordFailure();

            return;
        }

        $failures->reset();
    }

    /**
     * Called once the job will not be tried again.
     */
    public function failed(?Throwable $exception): void
    {
        $failure = $exception instanceof NewsletterProviderException ? $exception->failure : null;

        // Never the message: another exception may carry the address
        Log::warning('Newsletter: a subscription could not be sent to the provider', [
            'subscriber_hash' => SubscriberHash::fromEmail($this->email)->value(),
            'failure' => $failure->value ?? ($exception instanceof Throwable ? $exception::class : 'unknown'),
        ]);

        if ($failure !== ConnectionFailure::Impersonation) {
            app(SubscribeFailureCounterInterface::class)->recordFailure();
        }

        $this->forgetFailedCopy();
    }

    /**
     * The worker writes the failed job to failed_jobs after this method returns (when it hears
     * JobFailed), so the copy is deleted from a listener that runs after the worker's own.
     */
    private function forgetFailedCopy(): void
    {
        $uuid = $this->job?->uuid();

        if (! is_string($uuid) || $uuid === '') {
            return;
        }

        $forget = static function () use ($uuid): void {
            try {
                app('queue.failer')->forget($uuid);
            } catch (Throwable $e) {
                Log::warning('Newsletter: a failed subscription could not be removed from the failed jobs', ['exception' => $e::class]);
            }
        };

        // In case the copy is already there
        $forget();

        $handled = false;
        app(Dispatcher::class)->listen(JobFailed::class, static function (JobFailed $event) use ($uuid, $forget, &$handled): void {
            if ($handled || $event->job->uuid() !== $uuid) {
                return;
            }

            $handled = true;
            $forget();
        });
    }
}
