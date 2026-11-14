<?php

declare(strict_types=1);

namespace Modules\Newsletter\Tests\Integration\Infrastructure;

use App\Application\Modules\Services\ModuleManagerServiceInterface;
use App\Domain\Modules\ValueObjects\ModuleName;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Log\Events\MessageLogged;
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

    private function repository(): NewsletterSettingsRepositoryInterface
    {
        return $this->app->make(NewsletterSettingsRepositoryInterface::class);
    }
}
