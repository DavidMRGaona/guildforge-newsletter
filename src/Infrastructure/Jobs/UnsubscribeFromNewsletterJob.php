<?php

declare(strict_types=1);

namespace Modules\Newsletter\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\Newsletter\Application\Services\NewsletterProviderInterface;
use Modules\Newsletter\Domain\Enums\ConnectionFailure;
use Modules\Newsletter\Domain\Exceptions\NewsletterProviderException;
use Modules\Newsletter\Domain\ValueObjects\SubscriberHash;
use Modules\Newsletter\Infrastructure\Services\ProfileNewsletterService;
use SensitiveParameter;
use Throwable;

/**
 * Takes an address off the list, outside the request, when the person asks for it in their profile.
 *
 * Encrypted like the subscription (the queue is the database and failed jobs keep their payload).
 * A failure the retries cannot fix gives up inside handle(), which logs only the subscriber hash,
 * so the worker never writes the address to failed_jobs; what handle() cannot catch (a timeout)
 * ends in failed(). Giving up forgets the remembered status, so the profile asks the provider
 * again and tells the truth.
 */
final class UnsubscribeFromNewsletterJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 4;

    private const array PERMANENT = [
        ConnectionFailure::InvalidApiKey,
        ConnectionFailure::ListNotFound,
        ConnectionFailure::NotConfigured,
        ConnectionFailure::Impersonation,
    ];

    public function __construct(
        public readonly string $userId,
        #[SensitiveParameter] public readonly string $email,
    ) {}

    /**
     * @return list<int> Seconds to wait before each retry
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(NewsletterProviderInterface $provider): void
    {
        try {
            $provider->unsubscribe($this->email);
        } catch (Throwable $e) {
            $permanent = $e instanceof NewsletterProviderException && in_array($e->failure, self::PERMANENT, true);

            if (! $permanent && $this->attempts() < $this->tries) {
                throw $e;
            }

            $this->giveUp($e);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->giveUp($exception);
    }

    private function giveUp(?Throwable $exception): void
    {
        $failure = $exception instanceof NewsletterProviderException ? $exception->failure : null;

        Log::warning('Newsletter: an unsubscription could not be sent to the provider', [
            'subscriber_hash' => SubscriberHash::fromEmail($this->email)->value(),
            'failure' => $failure->value ?? ($exception instanceof Throwable ? $exception::class : 'unknown'),
        ]);

        try {
            app(Cache::class)->forget(ProfileNewsletterService::key($this->userId, $this->email));
        } catch (Throwable) {
            // The remembered status expires by itself
        }
    }
}
