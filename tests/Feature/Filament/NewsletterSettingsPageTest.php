<?php

declare(strict_types=1);

namespace Modules\Newsletter\Tests\Feature\Filament;

use App\Application\Impersonation\Services\ImpersonationContextInterface;
use App\Domain\Impersonation\Enums\ImpersonationMode;
use App\Filament\Clusters\SettingsCluster;
use App\Filament\Support\ModuleSettingsPages;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery\MockInterface;
use Modules\Newsletter\Application\DTOs\NewsletterSettings;
use Modules\Newsletter\Application\Services\NewsletterAvailabilityInterface;
use Modules\Newsletter\Application\Services\NewsletterSettingsRepositoryInterface;
use Modules\Newsletter\Domain\Enums\ConnectionFailure;
use Modules\Newsletter\Domain\ValueObjects\ConnectionCheck;
use Modules\Newsletter\Domain\ValueObjects\MailchimpApiKey;
use Modules\Newsletter\Filament\Pages\NewsletterSettingsPage;
use Modules\Newsletter\NewsletterServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Modules\ModuleTestCase;

final class NewsletterSettingsPageTest extends ModuleTestCase
{
    private const string KEY = 'fakemailchimpkeyfakemailchimpkey-us21';

    private const string OTHER_KEY = 'otherfakekeyzzzzotherfakekeyzzzz-us6';

    private const string LIST = 'a1b2c3d4e5';

    protected ?string $moduleName = 'newsletter';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel(pages: [NewsletterSettingsPage::class]);
        Http::preventStrayRequests();
    }

    public function test_the_provider_declares_the_page_and_registers_it(): void
    {
        $provider = new NewsletterServiceProvider($this->app);

        $this->assertSame(NewsletterSettingsPage::class, $provider->getSettingsPage());
        $this->assertContains(NewsletterSettingsPage::class, $provider->registerFilamentPages());
        $this->assertSame([], $provider->getSettingsSchema());
    }

    public function test_the_page_sits_in_the_settings_cluster_with_its_slot(): void
    {
        $this->assertSame(SettingsCluster::class, NewsletterSettingsPage::getCluster());
        $this->assertSame(115, NewsletterSettingsPage::getNavigationSort());
        $this->assertSame('Boletín', NewsletterSettingsPage::getNavigationLabel());
    }

    public function test_the_module_permission_opens_the_page(): void
    {
        $this->actingAs($this->userWith(NewsletterSettingsPage::PERMISSION));

        $this->assertTrue(NewsletterSettingsPage::canAccess());
        Livewire::test(NewsletterSettingsPage::class)
            ->assertOk()
            ->assertFormFieldExists('provider')
            ->assertFormFieldExists('api_key')
            ->assertFormFieldExists('server')
            ->assertFormFieldExists('list_id')
            ->assertFormFieldExists('consent_text')
            ->assertFormFieldExists('privacy_url');
    }

    public function test_modules_configure_alone_is_not_enough(): void
    {
        $this->actingAs($this->userWith('modules.configure'));

        $this->assertFalse(NewsletterSettingsPage::canAccess());
    }

    public function test_the_module_page_shows_the_button_only_to_those_who_may_enter(): void
    {
        $resolver = $this->app->make(ModuleSettingsPages::class);

        $this->assertNotNull($resolver->pageUrl('newsletter'));

        $this->actingAs($this->userWith('modules.configure'));
        $this->assertNull($resolver->accessibleUrl('newsletter'));

        $this->actingAs($this->userWith(NewsletterSettingsPage::PERMISSION));
        $this->assertNotNull($resolver->accessibleUrl('newsletter'));
    }

    public function test_saving_or_testing_without_the_permission_is_refused(): void
    {
        Http::fake();
        $this->actingAsPanelAdmin();
        $saving = Livewire::test(NewsletterSettingsPage::class)->fillForm(['api_key' => self::KEY]);
        $testing = Livewire::test(NewsletterSettingsPage::class)->fillForm(['api_key' => self::KEY]);

        $this->actingAs($this->userWith('modules.configure'));

        $saving->call('save')->assertForbidden();
        $testing->call('testConnection')->assertForbidden();

        $this->assertNull(DB::table('settings')->where('key', 'module_settings:newsletter')->first());
        Http::assertNothingSent();
    }

    public function test_testing_the_connection_loads_the_lists_to_choose_from(): void
    {
        $this->fakeLists();
        $this->actingAsPanelAdmin();

        $component = Livewire::test(NewsletterSettingsPage::class)
            ->fillForm(['api_key' => self::KEY])
            ->call('testConnection')
            ->assertHasNoErrors()
            ->assertFormSet(['server' => 'us21'])
            ->assertNotified();

        $this->assertSame([self::LIST => 'Socios', 'zz99yy88xx' => 'Otra'], $component->get('lists'));
        // Testing saves nothing
        $this->assertNull(DB::table('settings')->where('key', 'module_settings:newsletter')->first());
    }

    public function test_testing_with_a_malformed_key_never_calls_mailchimp(): void
    {
        Http::fake();
        $this->actingAsPanelAdmin();

        Livewire::test(NewsletterSettingsPage::class)
            ->fillForm(['api_key' => 'abc-evil.example.com'])
            ->call('testConnection')
            ->assertHasErrors(['data.api_key']);

        Http::assertNothingSent();
    }

    public function test_saving_with_a_working_connection_marks_it_checked_and_makes_the_newsletter_available(): void
    {
        $this->fakeLists();
        $this->actingAsPanelAdmin();

        Livewire::test(NewsletterSettingsPage::class)
            ->fillForm(['api_key' => self::KEY])
            ->call('testConnection')
            ->fillForm([
                'list_id' => self::LIST,
                'consent_text' => 'Quiero recibir el boletín por correo.',
                'privacy_url' => 'https://example.com/privacidad',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertFormSet(['api_key' => null]);

        $settings = $this->settings();
        $this->assertSame(self::KEY, $settings->apiKey?->value());
        $this->assertSame(self::LIST, $settings->listId);
        $this->assertSame('Socios', $settings->listName);
        $this->assertSame('Quiero recibir el boletín por correo.', $settings->consentText);
        $this->assertSame('https://example.com/privacidad', $settings->privacyUrl);
        $this->assertTrue($settings->lastCheck?->ok());
        $this->assertTrue($this->app->make(NewsletterAvailabilityInterface::class)->isAvailable());
    }

    public function test_saving_with_a_failing_connection_keeps_the_values_but_not_the_check(): void
    {
        Http::fake(['https://us21.api.mailchimp.com/3.0/lists*' => Http::sequence()
            ->push(['lists' => [['id' => self::LIST, 'name' => 'Socios']]], 200)
            ->push(['title' => 'API Key Invalid'], 401)]);
        $this->actingAsPanelAdmin();

        Livewire::test(NewsletterSettingsPage::class)
            ->fillForm(['api_key' => self::KEY])
            ->call('testConnection')
            ->fillForm(['list_id' => self::LIST, 'consent_text' => 'Acepto.'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $settings = $this->settings();
        $this->assertSame(self::LIST, $settings->listId);
        $this->assertFalse($settings->lastCheck?->ok());
        $this->assertSame(ConnectionFailure::InvalidApiKey, $settings->lastCheck->failure);
        $this->assertFalse($this->app->make(NewsletterAvailabilityInterface::class)->isAvailable());
    }

    public function test_the_stored_key_is_never_shown_and_an_empty_field_keeps_it(): void
    {
        $this->storeConfigured();
        $this->fakeLists();
        $this->actingAsPanelAdmin();

        $component = Livewire::test(NewsletterSettingsPage::class)
            ->assertFormSet(['api_key' => null, 'server' => 'us21', 'list_id' => self::LIST])
            ->assertDontSee('fakemailchimpkey');

        $component->fillForm(['consent_text' => 'Texto nuevo.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = $this->settings();
        $this->assertSame(self::KEY, $settings->apiKey?->value());
        $this->assertSame('Texto nuevo.', $settings->consentText);
        $this->assertTrue($settings->lastCheck?->ok());
        // The check ran with the stored key
        Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Basic '.base64_encode('guildforge:'.self::KEY)]);
    }

    public function test_a_new_key_replaces_the_stored_one_and_is_checked_on_its_own_server(): void
    {
        $this->storeConfigured();
        Http::fake(['https://us6.api.mailchimp.com/3.0/lists*' => Http::response(['lists' => [['id' => self::LIST, 'name' => 'Socios']]], 200)]);
        $this->actingAsPanelAdmin();

        Livewire::test(NewsletterSettingsPage::class)
            ->fillForm(['api_key' => self::OTHER_KEY])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(self::OTHER_KEY, $this->settings()->apiKey?->value());
        $this->assertTrue($this->settings()->lastCheck?->ok());
    }

    public function test_a_key_is_required_the_first_time(): void
    {
        Http::fake();
        $this->actingAsPanelAdmin();

        Livewire::test(NewsletterSettingsPage::class)
            ->fillForm(['consent_text' => 'Acepto.'])
            ->call('save')
            ->assertHasErrors(['data.api_key']);

        $this->assertNull(DB::table('settings')->where('key', 'module_settings:newsletter')->first());
    }

    public function test_a_list_the_account_did_not_offer_is_refused(): void
    {
        $this->fakeLists();
        $this->actingAsPanelAdmin();

        Livewire::test(NewsletterSettingsPage::class)
            ->fillForm(['api_key' => self::KEY])
            ->call('testConnection')
            ->fillForm(['list_id' => 'forged1234', 'consent_text' => 'Acepto.'])
            ->call('save')
            ->assertHasFormErrors(['list_id']);
    }

    public function test_the_lists_cannot_be_set_from_the_browser(): void
    {
        $this->actingAsPanelAdmin();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(NewsletterSettingsPage::class)->set('lists', ['forged1234' => 'Falsa']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeLinks(): iterable
    {
        yield 'script' => ['javascript:alert(1)'];
        yield 'protocol relative' => ['//evil.example.com/privacy'];
        yield 'data' => ['data:text/html,hi'];
        yield 'not a link' => ['privacidad'];
    }

    #[DataProvider('unsafeLinks')]
    public function test_the_privacy_link_must_be_a_web_address_or_a_path_of_the_site(string $link): void
    {
        $this->storeConfigured();
        $this->fakeLists();
        $this->actingAsPanelAdmin();

        Livewire::test(NewsletterSettingsPage::class)
            ->fillForm(['privacy_url' => $link])
            ->call('save')
            ->assertHasFormErrors(['privacy_url']);
    }

    public function test_nothing_reaches_mailchimp_during_an_impersonation(): void
    {
        Http::fake();
        $this->actingAsPanelAdmin();
        // "simulate" lets every panel action run (the core's safety net undoes them), so only the
        // module itself keeps Mailchimp out of it
        $this->mock(ImpersonationContextInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isActive')->andReturnTrue();
            $mock->shouldReceive('mode')->andReturn(ImpersonationMode::Simulate);
        });

        Livewire::test(NewsletterSettingsPage::class)
            ->fillForm(['api_key' => self::KEY])
            ->call('testConnection')
            ->assertNotified()
            ->fillForm(['consent_text' => 'Acepto.', 'list_id' => null])
            ->call('save');

        Http::assertNothingSent();
        $this->assertFalse($this->app->make(NewsletterAvailabilityInterface::class)->isAvailable());
    }

    public function test_the_last_check_is_shown(): void
    {
        $this->storeConfigured(new ConnectionCheck(new DateTimeImmutable('2026-11-14 09:30:00 UTC'), ConnectionFailure::Unreachable));
        $this->actingAsPanelAdmin();

        Livewire::test(NewsletterSettingsPage::class)
            ->assertSee('Mailchimp no responde');
    }

    private function fakeLists(): void
    {
        Http::fake(['https://us21.api.mailchimp.com/3.0/lists*' => Http::response(['lists' => [
            ['id' => self::LIST, 'name' => 'Socios'],
            ['id' => 'zz99yy88xx', 'name' => 'Otra'],
        ]], 200)]);
    }

    private function storeConfigured(?ConnectionCheck $check = null): void
    {
        $this->app->make(NewsletterSettingsRepositoryInterface::class)->save(new NewsletterSettings(
            apiKey: MailchimpApiKey::fromString(self::KEY),
            listId: self::LIST,
            listName: 'Socios',
            consentText: 'Quiero recibir el boletín.',
            privacyUrl: '/privacidad',
            lastCheck: $check ?? new ConnectionCheck(new DateTimeImmutable, null),
        ));
    }

    private function settings(): NewsletterSettings
    {
        return $this->app->make(NewsletterSettingsRepositoryInterface::class)->get();
    }

    private function userWith(string $permissionKey): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'tester_'.uniqid(), 'display_name' => 'Permissions tester']);
        $permission = PermissionModel::query()->firstOrCreate(
            ['key' => $permissionKey],
            ['label' => $permissionKey, 'resource' => 'newsletter', 'action' => 'test', 'module' => 'newsletter'],
        );
        $role->permissions()->attach($permission->id);
        $user = UserModel::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
