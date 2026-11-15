<?php

declare(strict_types=1);

namespace Modules\Newsletter\Infrastructure\Services;

use App\Application\Impersonation\Services\ImpersonationContextInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use Modules\Newsletter\Application\Services\NewsletterProviderInterface;
use Modules\Newsletter\Application\Services\ProfileNewsletterServiceInterface;
use Modules\Newsletter\Application\Services\SubscriptionRequesterInterface;
use Modules\Newsletter\Domain\Enums\ProfileNewsletterState;
use Modules\Newsletter\Domain\ValueObjects\SubscriberHash;
use Modules\Newsletter\Infrastructure\Jobs\UnsubscribeFromNewsletterJob;
use SensitiveParameter;
use Throwable;

final readonly class ProfileNewsletterService implements ProfileNewsletterServiceInterface
{
    /** Seconds the status of an account is remembered */
    public const int CACHE_SECONDS = 300;

    public function __construct(
        private NewsletterProviderInterface $provider,
        private SubscriptionRequesterInterface $subscriptions,
        private Dispatcher $bus,
        private Cache $cache,
        private ImpersonationContextInterface $impersonation,
    ) {}

    public function state(string $userId, #[SensitiveParameter] string $email): ProfileNewsletterState
    {
        // The safety net neutralises the network during an impersonation; do not rely on it
        if ($this->impersonation->isActive()) {
            return ProfileNewsletterState::Unavailable;
        }

        $key = self::key($userId, $email);
        $cached = $this->remembered($key);

        if ($cached instanceof ProfileNewsletterState) {
            return $cached;
        }

        try {
            $state = ProfileNewsletterState::fromStatus($this->provider->status($email));
        } catch (Throwable $e) {
            // The class only: a message may carry the address. Not remembered: the next visit asks again
            Log::warning('Newsletter: the status of an account could not be read', ['exception' => $e::class]);

            return ProfileNewsletterState::Unavailable;
        }

        $this->remember($key, $state);

        return $state;
    }

    public function subscribe(string $userId, #[SensitiveParameter] string $email, #[SensitiveParameter] ?string $name): ProfileNewsletterState
    {
        $state = $this->state($userId, $email);

        if ($state !== ProfileNewsletterState::Unsubscribed) {
            return $state;
        }

        $this->subscriptions->request($email, $name);
        // What the provider will answer once the job ran: the confirmation email is on its way
        $this->remember(self::key($userId, $email), ProfileNewsletterState::Pending);

        return ProfileNewsletterState::Pending;
    }

    public function unsubscribe(string $userId, #[SensitiveParameter] string $email): ProfileNewsletterState
    {
        $state = $this->state($userId, $email);

        if ($state !== ProfileNewsletterState::Subscribed && $state !== ProfileNewsletterState::Pending) {
            return $state;
        }

        $this->bus->dispatch(new UnsubscribeFromNewsletterJob($userId, $email));
        $this->remember(self::key($userId, $email), ProfileNewsletterState::Unsubscribed);

        return ProfileNewsletterState::Unsubscribed;
    }

    /**
     * By account and by the hash of its address (so a new address is asked again): never the
     * address itself.
     */
    public static function key(string $userId, #[SensitiveParameter] string $email): string
    {
        return 'newsletter:profile-status:'.hash('sha256', $userId).':'.SubscriberHash::fromEmail($email)->value();
    }

    private function remembered(string $key): ?ProfileNewsletterState
    {
        try {
            $value = $this->cache->get($key);
        } catch (Throwable) {
            return null;
        }

        return is_string($value) ? ProfileNewsletterState::tryFrom($value) : null;
    }

    private function remember(string $key, ProfileNewsletterState $state): void
    {
        try {
            $this->cache->put($key, $state->value, self::CACHE_SECONDS);
        } catch (Throwable) {
            // Without a cache every visit asks the provider: slower, never wrong
        }
    }
}
