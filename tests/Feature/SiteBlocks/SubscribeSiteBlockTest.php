<?php

declare(strict_types=1);

namespace Modules\Newsletter\Tests\Feature\SiteBlocks;

use App\Application\SiteBlocks\DTOs\ResolvedSiteBlockDTO;
use App\Application\SiteBlocks\Services\SiteBlockRegistryInterface;
use App\Application\SiteBlocks\Services\SiteBlockRendererInterface;
use App\Domain\SiteBlocks\SiteBlockPlacement;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use App\Infrastructure\SiteBlocks\FlatSettingsSchemaRule;
use Illuminate\Support\Facades\Http;
use Modules\Newsletter\Filament\SiteBlocks\SubscribeSiteBlock;
use Modules\Newsletter\Tests\Support\ConfiguresNewsletter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Modules\ModuleTestCase;

final class SubscribeSiteBlockTest extends ModuleTestCase
{
    use ConfiguresNewsletter;

    protected ?string $moduleName = 'newsletter';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_the_module_offers_the_block_for_the_footer_the_popups_and_the_link_page(): void
    {
        $registry = $this->app->make(SiteBlockRegistryInterface::class);
        $block = $registry->find('newsletter.subscribe');

        $this->assertInstanceOf(SubscribeSiteBlock::class, $block);
        $this->assertSame([SiteBlockPlacement::Footer, SiteBlockPlacement::Popup, SiteBlockPlacement::LinkPage], $block->placements());
        $this->assertSame('components/SubscribeBlock.vue', $block->component());
        $this->assertSame('Suscripción al boletín', $block->label());
        $this->assertContains($block, $registry->forPlacement(SiteBlockPlacement::Footer));
        $this->assertContains($block, $registry->forPlacement(SiteBlockPlacement::Popup));
        $this->assertContains($block, $registry->forPlacement(SiteBlockPlacement::LinkPage));
    }

    public function test_its_settings_are_flat(): void
    {
        $block = $this->app->make(SubscribeSiteBlock::class);

        $this->assertTrue((new FlatSettingsSchemaRule)->allows($block->settingsSchema()));
        $this->assertSame(['intro', 'button_label', 'show_name'], $this->app->make(SiteBlockRegistryInterface::class)->settingNames('newsletter.subscribe'));
    }

    #[DataProvider('placements')]
    public function test_a_guest_gets_an_empty_form_with_the_consent_of_the_settings(SiteBlockPlacement $placement): void
    {
        $this->configureNewsletter();

        $block = $this->resolve(['intro' => 'Novedades cada mes', 'button_label' => 'Apuntarme', 'show_name' => false], $placement);

        $this->assertNotNull($block);
        $this->assertSame('newsletter', $block->module);
        $this->assertSame('components/SubscribeBlock.vue', $block->component);
        $this->assertSame([
            'action' => '/newsletter',
            'intro' => 'Novedades cada mes',
            'buttonLabel' => 'Apuntarme',
            'showName' => false,
            'consentText' => 'Quiero recibir el boletín de la asociación.',
            'privacyUrl' => '/privacidad',
            'email' => null,
            'name' => null,
        ], $block->props);
        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{SiteBlockPlacement}>
     */
    public static function placements(): array
    {
        return [
            'footer' => [SiteBlockPlacement::Footer],
            'popup' => [SiteBlockPlacement::Popup],
            'link page' => [SiteBlockPlacement::LinkPage],
        ];
    }

    public function test_someone_signed_in_gets_their_address_and_name_already_filled_in(): void
    {
        $this->configureNewsletter();
        $user = UserModel::factory()->create(['name' => 'Ana Ejemplo', 'email' => 'ana@example.com']);

        $block = $this->resolve([], SiteBlockPlacement::Footer, $user);

        $this->assertNotNull($block);
        $this->assertSame('ana@example.com', $block->props['email']);
        $this->assertSame('Ana Ejemplo', $block->props['name']);
    }

    public function test_without_settings_for_the_block_it_uses_the_defaults(): void
    {
        $this->configureNewsletter(consentText: null, privacyUrl: null);

        $props = $this->resolve([], SiteBlockPlacement::Footer)?->props;
        $this->assertIsArray($props);
        $this->assertSame(['action', 'intro', 'buttonLabel', 'showName', 'consentText', 'privacyUrl', 'email', 'name'], array_keys($props));

        $this->assertNull($props['intro']);
        $this->assertNull($props['buttonLabel']);
        $this->assertTrue($props['showName']);
        $this->assertSame(__('newsletter::messages.settings.consent_default'), $props['consentText']);
        $this->assertNull($props['privacyUrl']);
    }

    public function test_blank_texts_count_as_not_set(): void
    {
        $this->configureNewsletter();

        $props = $this->resolve(['intro' => '   ', 'button_label' => ''], SiteBlockPlacement::Footer)?->props;
        $this->assertIsArray($props);
        $this->assertSame(['action', 'intro', 'buttonLabel', 'showName', 'consentText', 'privacyUrl', 'email', 'name'], array_keys($props));

        $this->assertNull($props['intro']);
        $this->assertNull($props['buttonLabel']);
    }

    public function test_it_is_not_drawn_while_the_newsletter_is_not_available(): void
    {
        $this->configureNewsletter(lastCheckOk: false);

        $this->assertNull($this->resolve([], SiteBlockPlacement::Footer));
        $this->assertFalse($this->app->make(SubscribeSiteBlock::class)->isAvailable());
    }

    public function test_it_is_not_drawn_without_settings(): void
    {
        $this->assertNull($this->resolve([], SiteBlockPlacement::Popup));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function resolve(array $settings, SiteBlockPlacement $placement, ?UserModel $user = null): ?ResolvedSiteBlockDTO
    {
        return $this->app->make(SiteBlockRendererInterface::class)
            ->resolveBlock('newsletter.subscribe', $settings, $placement, $user);
    }
}
