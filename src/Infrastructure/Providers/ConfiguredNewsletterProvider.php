<?php

declare(strict_types=1);

namespace Modules\Newsletter\Infrastructure\Providers;

use Illuminate\Support\Facades\Log;
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
use SensitiveParameter;
use Throwable;

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

    public function subscribe(#[SensitiveParameter] string $email, #[SensitiveParameter] ?string $name = null): SubscribeOutcome
    {
        return $this->provider('subscribe')->subscribe($email, $name);
    }

    public function status(#[SensitiveParameter] string $email): SubscriptionStatus
    {
        return $this->provider('status')->status($email);
    }

    public function unsubscribe(#[SensitiveParameter] string $email): void
    {
        $this->provider('unsubscribe')->unsubscribe($email);
    }

    public function checkConnection(): ConnectionCheckResultDTO
    {
        try {
            return $this->provider('check connection')->checkConnection();
        } catch (NewsletterProviderException $e) {
            return new ConnectionCheckResultDTO($e->failure);
        } catch (Throwable $e) {
            // The settings could not be read: a failed check, never an exception
            Log::warning('Newsletter: the connection check failed unexpectedly', ['exception' => $e::class]);

            return new ConnectionCheckResultDTO(ConnectionFailure::RequestFailed);
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
