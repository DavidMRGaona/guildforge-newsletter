<?php

declare(strict_types=1);

namespace Modules\Newsletter\Infrastructure\Settings;

use App\Application\Modules\Services\ModuleManagerServiceInterface;
use App\Application\Modules\Services\ModuleSettingsStoreInterface;
use App\Domain\Modules\ValueObjects\ModuleName;
use App\Infrastructure\Persistence\Eloquent\Models\SettingModel;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Newsletter\Application\DTOs\CheckRecording;
use Modules\Newsletter\Application\DTOs\NewsletterSettings;
use Modules\Newsletter\Application\Services\NewsletterSettingsRepositoryInterface;
use Modules\Newsletter\Domain\Enums\ConnectionFailure;
use Modules\Newsletter\Domain\Enums\NewsletterProviderName;
use Modules\Newsletter\Domain\ValueObjects\ConnectionCheck;
use Modules\Newsletter\Domain\ValueObjects\MailchimpApiKey;
use Modules\Newsletter\Domain\ValueObjects\MailchimpListId;
use SensitiveParameter;

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

    private const string LOCK = 'newsletter:settings-write';

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
        return $this->fromRaw($this->store->stored(self::MODULE));
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function fromRaw(array $raw): NewsletterSettings
    {
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
        $this->withWriteLock(function () use ($settings): void {
            $this->write($settings);
        });
    }

    public function recordCheck(
        ConnectionCheck $check,
        #[SensitiveParameter] MailchimpApiKey $checkedKey,
        string $checkedListId,
        ?ConnectionCheck $startedFrom,
    ): CheckRecording {
        // A lock that cannot be taken throws: the caller reports the check as not run
        return $this->withWriteLock(function () use ($check, $checkedKey, $checkedListId, $startedFrom): CheckRecording {
            // The table itself: the settings cache of the core can hold a row older than a save
            $saved = $this->fromRaw($this->readTable());

            if ($saved->apiKey?->value() !== $checkedKey->value()
                || $saved->listId !== $checkedListId
                || ! $this->sameCheck($saved->lastCheck, $startedFrom)) {
                return CheckRecording::refused();
            }

            $this->write(new NewsletterSettings(
                provider: $saved->provider,
                apiKey: $saved->apiKey,
                listId: $saved->listId,
                listName: $saved->listName,
                consentText: $saved->consentText,
                privacyUrl: $saved->privacyUrl,
                lastCheck: $check,
            ));

            return CheckRecording::written($saved->lastCheck);
        });
    }

    private function sameCheck(?ConnectionCheck $a, ?ConnectionCheck $b): bool
    {
        if (! $a instanceof ConnectionCheck || ! $b instanceof ConnectionCheck) {
            return $a === $b;
        }

        return $a->checkedAt->getTimestamp() === $b->checkedAt->getTimestamp() && $a->failure === $b->failure;
    }

    /**
     * @return array<string, mixed>
     */
    private function readTable(): array
    {
        $value = SettingModel::query()->where('key', 'module_settings:'.self::MODULE)->value('value');
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Saving and recording both rewrite the whole row, so they take turns: without it a check could
     * read, a save could write another key, and the check would then put the old key back.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withWriteLock(Closure $callback): mixed
    {
        return Cache::lock(self::LOCK, 10)->block(5, $callback);
    }

    private function write(NewsletterSettings $settings): void
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
