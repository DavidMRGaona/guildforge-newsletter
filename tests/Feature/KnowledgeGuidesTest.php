<?php

declare(strict_types=1);

namespace Modules\Newsletter\Tests\Feature;

use App\Application\Knowledge\Services\KnowledgeQueryServiceInterface;
use App\Domain\Knowledge\Enums\GuideAudience;
use App\Domain\Knowledge\ValueObjects\GuideId;
use App\Filament\Pages\KnowledgeBasePage;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Livewire\Livewire;
use Modules\Newsletter\Filament\Pages\NewsletterSettingsPage;
use Modules\Newsletter\Tests\Support\ConfiguresNewsletter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The module's help guides pass the knowledge base lint, show only to whoever they are for and
 * the settings page opens its guide.
 */
final class KnowledgeGuidesTest extends ModuleTestCase
{
    use ConfiguresNewsletter;
    use InteractsWithModulePanel;

    private const string SETTINGS_GUIDE = 'newsletter.panel.settings';

    private const string SUBSCRIBE_GUIDE = 'newsletter.site.subscribe';

    protected ?string $moduleName = 'newsletter';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        // The panel guide links to the settings page, routed only once it is on the panel
        $this->registerOnAdminPanel(pages: [NewsletterSettingsPage::class]);
    }

    public function test_guides_are_valid(): void
    {
        $this->assertModuleKnowledgeGuidesAreValid('newsletter');
    }

    /**
     * @return array<string, array{string, GuideAudience}>
     */
    public static function guides(): array
    {
        return [
            'settings' => [self::SETTINGS_GUIDE, GuideAudience::Panel],
            'subscribe' => [self::SUBSCRIBE_GUIDE, GuideAudience::Site],
        ];
    }

    #[DataProvider('guides')]
    public function test_every_guide_renders_without_raw_markup(string $guideId, GuideAudience $audience): void
    {
        $this->configureNewsletter();
        $this->syncGuides();
        $admin = UserModel::factory()->admin()->create();

        $guide = $this->queries()->guide($admin, $audience, GuideId::fromString($guideId));

        $this->assertNotNull($guide);
        foreach (['[[', '{{', '[!'] as $raw) {
            $this->assertStringNotContainsString($raw, $guide->html);
        }
    }

    public function test_only_whoever_configures_the_newsletter_sees_the_settings_guide(): void
    {
        $this->syncGuides();

        $this->assertContains(self::SETTINGS_GUIDE, $this->queries()->visibleIds($this->userWith(NewsletterSettingsPage::PERMISSION), GuideAudience::Panel));
        $this->assertNotContains(self::SETTINGS_GUIDE, $this->queries()->visibleIds($this->userWith('newsletter:other.permission'), GuideAudience::Panel));
        $this->assertNotContains(self::SETTINGS_GUIDE, $this->queries()->visibleIds(UserModel::factory()->create(), GuideAudience::Panel));
    }

    public function test_the_subscribe_guide_shows_to_a_signed_in_person_while_the_newsletter_is_available(): void
    {
        $this->configureNewsletter();
        $this->syncGuides();

        $this->assertContains(self::SUBSCRIBE_GUIDE, $this->queries()->visibleIds(UserModel::factory()->create(), GuideAudience::Site));
    }

    public function test_the_subscribe_guide_is_hidden_while_the_newsletter_is_off(): void
    {
        $this->configureNewsletter(lastCheckOk: false);
        $this->syncGuides();

        $this->assertNotContains(self::SUBSCRIBE_GUIDE, $this->queries()->visibleIds(UserModel::factory()->create(), GuideAudience::Site));
    }

    public function test_the_subscribe_guide_is_hidden_when_nothing_is_configured(): void
    {
        $this->syncGuides();

        $this->assertNotContains(self::SUBSCRIBE_GUIDE, $this->queries()->visibleIds(UserModel::factory()->create(), GuideAudience::Site));
    }

    public function test_the_settings_page_opens_its_guide(): void
    {
        $this->syncGuides();
        $this->actingAs($this->userWith(NewsletterSettingsPage::PERMISSION));

        Livewire::test(NewsletterSettingsPage::class)
            ->assertActionVisible('contextualHelp')
            ->assertActionHasUrl('contextualHelp', KnowledgeBasePage::getUrl(['guia' => self::SETTINGS_GUIDE]));
    }

    private function syncGuides(): void
    {
        $this->artisanCommand('knowledge:sync')->assertSuccessful()->run();
    }

    private function queries(): KnowledgeQueryServiceInterface
    {
        return $this->app->make(KnowledgeQueryServiceInterface::class);
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
