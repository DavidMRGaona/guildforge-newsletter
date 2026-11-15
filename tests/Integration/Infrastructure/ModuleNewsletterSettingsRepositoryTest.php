<?php

declare(strict_types=1);

namespace Modules\Newsletter\Tests\Integration\Infrastructure;

use App\Application\Modules\Services\ModuleManagerServiceInterface;
use App\Domain\Modules\ValueObjects\ModuleName;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Newsletter\Application\DTOs\NewsletterSettings;
use Modules\Newsletter\Application\Services\NewsletterSettingsRepositoryInterface;
use Modules\Newsletter\Domain\Enums\ConnectionFailure;
use Modules\Newsletter\Domain\ValueObjects\ConnectionCheck;
use Modules\Newsletter\Domain\ValueObjects\MailchimpApiKey;
use Tests\Support\Modules\ModuleTestCase;

final class ModuleNewsletterSettingsRepositoryTest extends ModuleTestCase
{
    private const string SECRET = 'fakemailchimpkeyfakemailchimpkey';

    protected ?string $moduleName = 'newsletter';

    protected bool $autoEnableModule = true;

    public function test_nothing_saved_gives_the_defaults(): void
    {
        $settings = $this->repository()->get();

        $this->assertNull($settings->apiKey);
        $this->assertNull($settings->listId);
        $this->assertNull($settings->lastCheck);
    }

    public function test_it_reads_back_what_it_saved(): void
    {
        $checkedAt = new DateTimeImmutable('2026-11-14 10:30:00', new DateTimeZone('UTC'));

        $this->repository()->save(new NewsletterSettings(
            apiKey: new MailchimpApiKey(self::SECRET, 'us21'),
            listId: 'a1b2c3d4e5',
            listName: 'Socios',
            consentText: 'Quiero recibir el boletín.',
            privacyUrl: '/privacidad',
            lastCheck: new ConnectionCheck($checkedAt, ConnectionFailure::Unreachable),
        ));

        $settings = $this->repository()->get();

        $this->assertSame(self::SECRET.'-us21', $settings->apiKey?->value());
        $this->assertSame('a1b2c3d4e5', $settings->listId);
        $this->assertSame('Socios', $settings->listName);
        $this->assertSame('Quiero recibir el boletín.', $settings->consentText);
        $this->assertSame('/privacidad', $settings->privacyUrl);
        $this->assertSame(ConnectionFailure::Unreachable, $settings->lastCheck?->failure);
        $this->assertFalse($settings->lastCheck->ok());
        $this->assertSame($checkedAt->getTimestamp(), $settings->lastCheck->checkedAt->getTimestamp());
    }

    public function test_the_key_is_stored_encrypted(): void
    {
        $this->repository()->save(new NewsletterSettings(apiKey: new MailchimpApiKey(self::SECRET, 'us21')));

        $row = (string) DB::table('settings')->where('key', 'module_settings:newsletter')->value('value');

        $this->assertNotSame('', $row);
        $this->assertStringNotContainsString(self::SECRET, $row);
        $this->assertStringNotContainsString(self::SECRET, json_encode(config('modules.settings.newsletter'), JSON_THROW_ON_ERROR));
    }

    public function test_a_key_that_cannot_be_decrypted_is_dropped_without_logging_it(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context, JSON_THROW_ON_ERROR);
        });

        $this->app->make(ModuleManagerServiceInterface::class)->updateSettings(new ModuleName('newsletter'), [
            'api_key' => self::SECRET.'-us21',
            'list_id' => 'a1b2c3d4e5',
        ]);

        $settings = $this->repository()->get();

        $this->assertNull($settings->apiKey);
        $this->assertSame('a1b2c3d4e5', $settings->listId);
        $this->assertNotSame([], $logged);
        $this->assertStringNotContainsString(self::SECRET, implode("\n", $logged));
    }

    public function test_malformed_values_are_read_as_missing(): void
    {
        $this->app->make(ModuleManagerServiceInterface::class)->updateSettings(new ModuleName('newsletter'), [
            'provider' => 'sendinblue',
            'list_id' => '../../other',
            'last_check' => ['checked_at' => 'yesterday-ish', 'failure' => null],
        ]);

        $settings = $this->repository()->get();

        $this->assertNull($settings->listId);
        $this->assertNull($settings->lastCheck);
    }

    public function test_record_check_writes_only_the_last_check_while_key_and_list_are_the_saved_ones(): void
    {
        $this->saveConfigured();
        $key = new MailchimpApiKey(self::SECRET, 'us21');
        $check = new ConnectionCheck(new DateTimeImmutable('2026-11-15 06:00:00', new DateTimeZone('UTC')), ConnectionFailure::InvalidApiKey);

        $recording = $this->repository()->recordCheck($check, $key, 'a1b2c3d4e5', $this->savedCheck());

        $this->assertTrue($recording->written);
        $this->assertTrue($recording->replaced?->ok());

        $settings = $this->repository()->get();
        $this->assertSame(ConnectionFailure::InvalidApiKey, $settings->lastCheck?->failure);
        $this->assertSame(self::SECRET.'-us21', $settings->apiKey?->value());
        $this->assertSame('a1b2c3d4e5', $settings->listId);
        $this->assertSame('Socios', $settings->listName);
        $this->assertSame('Texto de consentimiento.', $settings->consentText);
        $this->assertSame('/privacidad', $settings->privacyUrl);
    }

    public function test_record_check_keeps_what_was_saved_after_the_settings_were_read(): void
    {
        $this->saveConfigured();
        // The copy this request already read (the scoped one) is now old
        $this->repository()->get();
        $this->app->make(ModuleManagerServiceInterface::class)->updateSettings(new ModuleName('newsletter'), [
            ...$this->app->make(ModuleManagerServiceInterface::class)->getSettings(new ModuleName('newsletter')),
            'consent_text' => 'Texto nuevo.',
        ]);

        $this->assertTrue($this->repository()->recordCheck(
            new ConnectionCheck(new DateTimeImmutable('2026-11-15 06:00:00'), null),
            new MailchimpApiKey(self::SECRET, 'us21'),
            'a1b2c3d4e5',
            $this->savedCheck(),
        )->written);

        $this->assertSame('Texto nuevo.', $this->repository()->get()->consentText);
        $this->assertTrue($this->repository()->get()->lastCheck?->ok());
    }

    public function test_record_check_refuses_a_check_made_with_an_old_key(): void
    {
        $this->saveConfigured(lastCheckFailure: ConnectionFailure::Unreachable);
        // Someone saved another key while the check was running
        $this->repository()->save(new NewsletterSettings(
            apiKey: new MailchimpApiKey('anotherfakekeyanotherfakekey00', 'us6'),
            listId: 'a1b2c3d4e5',
            lastCheck: new ConnectionCheck(new DateTimeImmutable('2026-11-15 05:59:00'), ConnectionFailure::Unreachable),
        ));

        $recorded = $this->repository()->recordCheck(
            new ConnectionCheck(new DateTimeImmutable('2026-11-15 06:00:00'), null),
            new MailchimpApiKey(self::SECRET, 'us21'),
            'a1b2c3d4e5',
            $this->savedCheck(ConnectionFailure::Unreachable),
        );

        $this->assertFalse($recorded->written);
        $settings = $this->repository()->get();
        $this->assertSame('anotherfakekeyanotherfakekey00-us6', $settings->apiKey?->value());
        $this->assertSame(ConnectionFailure::Unreachable, $settings->lastCheck?->failure);
    }

    public function test_record_check_refuses_a_check_made_against_another_list(): void
    {
        $this->saveConfigured(lastCheckFailure: ConnectionFailure::Unreachable);

        $recorded = $this->repository()->recordCheck(
            new ConnectionCheck(new DateTimeImmutable('2026-11-15 06:00:00'), null),
            new MailchimpApiKey(self::SECRET, 'us21'),
            'zzzzzzzzzz',
            $this->savedCheck(ConnectionFailure::Unreachable),
        );

        $this->assertFalse($recorded->written);
        $this->assertFalse($this->repository()->get()->lastCheck?->ok());
    }

    public function test_record_check_refuses_when_nothing_is_saved(): void
    {
        $this->assertFalse($this->repository()->recordCheck(
            new ConnectionCheck(new DateTimeImmutable('2026-11-15 06:00:00'), null),
            new MailchimpApiKey(self::SECRET, 'us21'),
            'a1b2c3d4e5',
            null,
        )->written);
        $this->assertNull($this->repository()->get()->lastCheck);
    }

    public function test_record_check_refuses_when_a_newer_check_was_saved_with_the_same_key(): void
    {
        // The slow daily check started from a failed one; meanwhile the panel saved a good one
        $this->saveConfigured(lastCheckFailure: ConnectionFailure::Unreachable);
        $startedFrom = $this->savedCheck(ConnectionFailure::Unreachable);
        $this->repository()->save(new NewsletterSettings(
            apiKey: new MailchimpApiKey(self::SECRET, 'us21'),
            listId: 'a1b2c3d4e5',
            listName: 'Socios',
            consentText: 'Texto de consentimiento.',
            privacyUrl: '/privacidad',
            lastCheck: new ConnectionCheck(new DateTimeImmutable('2026-11-15 05:59:00'), null),
        ));

        $recording = $this->repository()->recordCheck(
            new ConnectionCheck(new DateTimeImmutable('2026-11-15 06:00:00'), ConnectionFailure::Unreachable),
            new MailchimpApiKey(self::SECRET, 'us21'),
            'a1b2c3d4e5',
            $startedFrom,
        );

        $this->assertFalse($recording->written);
        $this->assertTrue($this->repository()->get()->lastCheck?->ok());
    }

    public function test_record_check_reads_the_table_not_a_cached_copy(): void
    {
        $this->saveConfigured();
        // A stale copy of the row sits in the core's settings cache, as a concurrent reader may leave it
        $stale = json_encode(['api_key' => null, 'list_id' => 'a1b2c3d4e5']);
        Cache::put('site_settings', ['module_settings:newsletter' => $stale], 3600);

        $recording = $this->repository()->recordCheck(
            new ConnectionCheck(new DateTimeImmutable('2026-11-15 06:00:00'), ConnectionFailure::Unreachable),
            new MailchimpApiKey(self::SECRET, 'us21'),
            'a1b2c3d4e5',
            $this->savedCheck(),
        );

        $this->assertTrue($recording->written);
    }

    private function savedCheck(?ConnectionFailure $failure = null): ConnectionCheck
    {
        return new ConnectionCheck(new DateTimeImmutable('2026-11-14 10:00:00'), $failure);
    }

    private function saveConfigured(?ConnectionFailure $lastCheckFailure = null): void
    {
        $this->repository()->save(new NewsletterSettings(
            apiKey: new MailchimpApiKey(self::SECRET, 'us21'),
            listId: 'a1b2c3d4e5',
            listName: 'Socios',
            consentText: 'Texto de consentimiento.',
            privacyUrl: '/privacidad',
            lastCheck: new ConnectionCheck(new DateTimeImmutable('2026-11-14 10:00:00'), $lastCheckFailure),
        ));
    }

    private function repository(): NewsletterSettingsRepositoryInterface
    {
        return $this->app->make(NewsletterSettingsRepositoryInterface::class);
    }
}
