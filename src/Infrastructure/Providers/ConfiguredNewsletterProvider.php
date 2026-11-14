<?php

declare(strict_types=1);

namespace Modules\Newsletter\Infrastructure\Providers;

use Modules\Newsletter\Application\DTOs\ConnectionCheckResultDTO;
use Modules\Newsletter\Application\Services\NewsletterProviderFactoryInterface;
use Modules\Newsletter\Application\Services\NewsletterProviderInterface;
use Modules\Newsletter\Application\Services\NewsletterSettingsRepositoryInterface;
use Modules\Newsletter\Domain\Enums\ConnectionFailure;
use Modules\Newsletter\Domain\Enums\NewsletterProviderName;
use Modules\Newsletter\Domain\Enums\SubscribeOutcome;
use Modules\Newsletter\Domain\Enums\SubscriptionStatus;
use Modules\Newsletter\Domain\Exceptions\NewsletterProviderException;
use Modules\Newsletter\Domain\ValueObjects\MailchimpApiKey;

/**
 * The provider of the saved settings, read again on every call: a queue worker started before the
 * settings changed uses the new key and list too.
 */
final readonly class ConfiguredNewsletterProvider implements NewsletterProviderInterface
{
    public function __construct(
        private NewsletterSettingsRepositoryInterface $settings,
        private NewsletterProviderFactoryInterface $factory,
    ) {}

    public function subscribe(string $email, ?string $name = null): SubscribeOutcome
    {
        return $this->provider('subscribe')->subscribe($email, $name);
    }

    public function status(string $email): SubscriptionStatus
    {
        return $this->provider('status')->status($email);
    }

    public function unsubscribe(string $email): void
    {
        $this->provider('unsubscribe')->unsubscribe($email);
    }

    public function checkConnection(): ConnectionCheckResultDTO
    {
        try {
            return $this->provider('check connection')->checkConnection();
        } catch (NewsletterProviderException $e) {
            return new ConnectionCheckResultDTO($e->failure);
        }
    }

    private function provider(string $operation): NewsletterProviderInterface
    {
        $settings = $this->settings->get();

        if (! $settings->apiKey instanceof MailchimpApiKey) {
            throw NewsletterProviderException::because(ConnectionFailure::NotConfigured, $operation);
        }

        return match ($settings->provider) {
            NewsletterProviderName::Mailchimp => $this->factory->mailchimp($settings->apiKey, $settings->listId),
        };
    }
}
