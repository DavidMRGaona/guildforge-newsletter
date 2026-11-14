<?php

declare(strict_types=1);

namespace Modules\Newsletter\Infrastructure\Settings;

use App\Application\Modules\Services\ModuleManagerServiceInterface;
use App\Application\Modules\Services\ModuleSettingsStoreInterface;
use App\Domain\Modules\ValueObjects\ModuleName;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Newsletter\Application\DTOs\NewsletterSettings;
use Modules\Newsletter\Application\Services\NewsletterSettingsRepositoryInterface;
use Modules\Newsletter\Domain\Enums\ConnectionFailure;
use Modules\Newsletter\Domain\Enums\NewsletterProviderName;
use Modules\Newsletter\Domain\ValueObjects\ConnectionCheck;
use Modules\Newsletter\Domain\ValueObjects\MailchimpApiKey;
use Modules\Newsletter\Domain\ValueObjects\MailchimpListId;

/**
 * The settings live in the module's own row of the settings table ("module_settings:newsletter"),
 * the one every module uses. The API key is encrypted with the application key.
 *
 * Reads go to the stored row, not to the copy the core puts in the configuration at boot: a queue
 * worker that started before a change still sees the new values. Bound "scoped", so the row is
 * read and the key decrypted once per request or queued job; save() drops that copy.
 */
final class ModuleNewsletterSettingsRepository implements NewsletterSettingsRepositoryInterface
{
    private const string MODULE = 'newsletter';

    private ?NewsletterSettings $read = null;

    public function __construct(
        private readonly ModuleSettingsStoreInterface $store,
        private readonly ModuleManagerServiceInterface $modules,
    ) {}

    public function get(): NewsletterSettings
    {
        return $this->read ??= $this->load();
    }

    private function load(): NewsletterSettings
    {
        $raw = $this->store->stored(self::MODULE);

        return new NewsletterSettings(
            provider: NewsletterProviderName::tryFrom($this->string($raw, 'provider') ?? '') ?? NewsletterProviderName::Mailchimp,
            apiKey: $this->apiKey($this->string($raw, 'api_key')),
            listId: MailchimpListId::isWellFormed($raw['list_id'] ?? null) ? $raw['list_id'] : null,
            listName: $this->string($raw, 'list_name'),
            consentText: $this->string($raw, 'consent_text'),
            privacyUrl: $this->string($raw, 'privacy_url'),
            lastCheck: $this->lastCheck($raw['last_check'] ?? null),
        );
    }

    public function save(NewsletterSettings $settings): void
    {
        $this->read = null;
        $lastCheck = $settings->lastCheck;

        // Through the module manager, so the configuration of this request sees the new values too
        $this->modules->updateSettings(new ModuleName(self::MODULE), [
            'provider' => $settings->provider->value,
            'api_key' => $settings->apiKey instanceof MailchimpApiKey ? Crypt::encryptString($settings->apiKey->value()) : null,
            'list_id' => $settings->listId,
            'list_name' => $settings->listName,
            'consent_text' => $settings->consentText,
            'privacy_url' => $settings->privacyUrl,
            'last_check' => $lastCheck instanceof ConnectionCheck ? [
                'checked_at' => $lastCheck->checkedAt->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
                'failure' => $lastCheck->failure?->value,
            ] : null,
        ]);
    }

    private function apiKey(?string $encrypted): ?MailchimpApiKey
    {
        if ($encrypted === null) {
            return null;
        }

        try {
            return MailchimpApiKey::fromString(Crypt::decryptString($encrypted));
        } catch (DecryptException|InvalidArgumentException) {
            // The application key changed or the row was edited by hand. Never the value in the log
            Log::warning('Newsletter: the saved API key cannot be read; save it again in the panel');

            return null;
        }
    }

    /**
     * A check that cannot be read counts as none; one with an unknown failure as a failed one.
     */
    private function lastCheck(mixed $raw): ?ConnectionCheck
    {
        if (! is_array($raw) || ! is_string($raw['checked_at'] ?? null)) {
            return null;
        }

        $checkedAt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $raw['checked_at']);

        if ($checkedAt === false) {
            return null;
        }

        $failure = $raw['failure'] ?? null;

        return new ConnectionCheck(
            $checkedAt,
            $failure === null ? null : (is_string($failure) ? ConnectionFailure::tryFrom($failure) : null) ?? ConnectionFailure::RequestFailed,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function string(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
