<?php

declare(strict_types=1);

namespace Modules\Newsletter\Tests\Feature;

use App\Application\Modules\Services\ModuleManagerServiceInterface;
use App\Domain\Modules\ValueObjects\ModuleName;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Modules\Newsletter\Application\DTOs\NewsletterSettings;
use Modules\Newsletter\Application\Services\NewsletterAvailabilityInterface;
use Modules\Newsletter\Application\Services\NewsletterSettingsRepositoryInterface;
use Modules\Newsletter\Domain\Enums\ConnectionFailure;
use Modules\Newsletter\Domain\ValueObjects\ConnectionCheck;
use Modules\Newsletter\Domain\ValueObjects\MailchimpApiKey;
use Modules\Newsletter\Infrastructure\Services\NewsletterAvailability;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;

final class NewsletterAvailabilityTest extends ModuleTestCase
{
    protected ?string $moduleName = 'newsletter';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_it_is_available_with_key_list_and_a_good_last_check(): void
    {
        $this->store($this->configured());

        $this->assertTrue($this->availability()->isAvailable());
        // Asking never calls the provider: the last check decides
        Http::assertNothingSent();
    }

    public function test_the_implementation_is_the_one_bound(): void
    {
        $this->assertInstanceOf(NewsletterAvailability::class, $this->availability());
    }

    public function test_it_is_not_available_without_a_key(): void
    {
        $this->store($this->configured(apiKey: null));

        $this->assertFalse($this->availability()->isAvailable());
    }

    public function test_it_is_not_available_without_a_list(): void
    {
        $this->store($this->configured(listId: null));

        $this->assertFalse($this->availability()->isAvailable());
    }

    public function test_it_is_not_available_before_the_first_check(): void
    {
        $this->store($this->configured(lastCheck: null));

        $this->assertFalse($this->availability()->isAvailable());
    }

    public function test_it_is_not_available_after_a_failed_check(): void
    {
        $this->store($this->configured(lastCheck: new ConnectionCheck(new DateTimeImmutable, ConnectionFailure::InvalidApiKey)));

        $this->assertFalse($this->availability()->isAvailable());
    }

    public function test_it_is_not_available_with_nothing_saved(): void
    {
        $this->assertFalse($this->availability()->isAvailable());
    }

    public function test_it_is_not_available_when_the_module_is_disabled(): void
    {
        $this->store($this->configured());

        $this->disableModule('newsletter');

        $this->assertFalse($this->availability()->isAvailable());
    }

    public function test_a_key_that_can_no_longer_be_decrypted_counts_as_no_key(): void
    {
        $this->store($this->configured());
        $stored = $this->moduleManager()->getSettings(new ModuleName('newsletter'));
        $stored['api_key'] = 'not-something-this-app-encrypted';
        $this->app->make(ModuleManagerServiceInterface::class)->updateSettings(new ModuleName('newsletter'), $stored);

        $this->assertFalse($this->availability()->isAvailable());
    }

    public function test_it_fails_closed_when_the_settings_cannot_be_read(): void
    {
        $this->store($this->configured());
        $this->mock(NewsletterSettingsRepositoryInterface::class)
            ->shouldReceive('get')
            ->andThrow(new RuntimeException('database down'));

        $this->assertFalse($this->app->make(NewsletterAvailabilityInterface::class)->isAvailable());
    }

    private function availability(): NewsletterAvailabilityInterface
    {
        return $this->app->make(NewsletterAvailabilityInterface::class);
    }

    private function store(NewsletterSettings $settings): void
    {
        $this->app->make(NewsletterSettingsRepositoryInterface::class)->save($settings);
    }

    private function configured(
        ?MailchimpApiKey $apiKey = new MailchimpApiKey('fakemailchimpkeyfakemailchimpkey', 'us21'),
        ?string $listId = 'a1b2c3d4e5',
        ?ConnectionCheck $lastCheck = new ConnectionCheck(new DateTimeImmutable('2026-11-14 10:00:00'), null),
    ): NewsletterSettings {
        return new NewsletterSettings(
            apiKey: $apiKey,
            listId: $listId,
            listName: 'Socios',
            consentText: 'Quiero recibir el boletín.',
            privacyUrl: '/privacidad',
            lastCheck: $lastCheck,
        );
    }
}
