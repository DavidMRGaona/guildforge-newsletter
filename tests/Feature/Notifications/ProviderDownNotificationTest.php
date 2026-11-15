<?php

declare(strict_types=1);

namespace Modules\Newsletter\Tests\Feature\Notifications;

use App\Application\Mail\Services\MailCategoryRegistryInterface;
use App\Application\SiteNotifications\Contracts\HasSiteNotification;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Newsletter\Domain\Enums\ConnectionFailure;
use Modules\Newsletter\Filament\Pages\NewsletterSettingsPage;
use Modules\Newsletter\Notifications\ProviderDownNotification;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

final class ProviderDownNotificationTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'newsletter';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel(pages: [NewsletterSettingsPage::class]);
    }

    public function test_the_category_is_registered_for_mail_and_the_bell_with_no_digest(): void
    {
        $category = app(MailCategoryRegistryInterface::class)->find('newsletter.provider-down');

        $this->assertNotNull($category);
        $this->assertTrue($category->site);
        $this->assertTrue($category->mail);
        $this->assertFalse($category->digestible);
        $this->assertNull($category->mandatoryReason);
    }

    public function test_only_people_who_can_configure_the_newsletter_are_offered_the_category(): void
    {
        $category = app(MailCategoryRegistryInterface::class)->find('newsletter.provider-down');
        $this->assertNotNull($category?->audience);

        $this->assertFalse(($category->audience)(UserModel::factory()->create()));
        $this->assertTrue(($category->audience)($this->holder()));
    }

    public function test_it_is_queued_and_goes_by_mail_and_the_bell(): void
    {
        $notice = ProviderDownNotification::forFailure(ConnectionFailure::InvalidApiKey);

        $this->assertContains(ShouldQueue::class, class_implements($notice));
        $this->assertSame('newsletter.provider-down', $notice->mailCategory());
        $this->assertSame(['mail', HasSiteNotification::CHANNEL], $notice->via(new UserModel));
    }

    public function test_it_carries_only_the_kind_of_failure(): void
    {
        $serialized = serialize(ProviderDownNotification::forFailure(ConnectionFailure::ListNotFound));

        $this->assertStringContainsString('list_not_found', $serialized);
        $this->assertStringNotContainsString('fakemailchimpkey', $serialized);
        $this->assertSame(ConnectionFailure::ListNotFound, unserialize($serialized)->failure());
    }

    public function test_the_bell_notice_says_what_failed_and_opens_the_settings_page(): void
    {
        $notice = (ProviderDownNotification::forFailure(ConnectionFailure::InvalidApiKey))->toSiteNotification(new UserModel);

        $this->assertSame('El boletín está oculto: la conexión con Mailchimp ha fallado', $notice->title);
        $this->assertSame('Mailchimp no acepta la clave de API.', $notice->body);
        $this->assertSame(NewsletterSettingsPage::getUrl(isAbsolute: false, panel: 'admin'), $notice->url);
        $this->assertStringContainsString('/boletin', $notice->url);
    }

    public function test_the_mail_names_the_person_and_links_to_the_settings_page(): void
    {
        $user = UserModel::factory()->create(['name' => '[Ana](https://evil.example)']);

        $mail = (ProviderDownNotification::forFailure(ConnectionFailure::Unreachable))->toMail($user);

        $this->assertSame('El boletín está oculto: la conexión con Mailchimp ha fallado', $mail->subject);
        $this->assertStringNotContainsString('[Ana](https://evil.example)', $mail->greeting);
        $this->assertSame(NewsletterSettingsPage::getUrl(isAbsolute: true, panel: 'admin'), $mail->actionUrl);
        $this->assertContains('Mailchimp no responde. Inténtalo de nuevo en unos minutos.', $mail->introLines);
    }

    private function holder(): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'boletin_'.uniqid(), 'display_name' => 'Boletín']);
        $permission = PermissionModel::query()->firstOrCreate(
            ['key' => NewsletterSettingsPage::PERMISSION],
            ['label' => 'Configurar', 'resource' => 'newsletter', 'action' => 'configure', 'module' => 'newsletter'],
        );
        $role->permissions()->attach($permission->id);
        $user = UserModel::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
