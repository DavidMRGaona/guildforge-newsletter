<?php

declare(strict_types=1);

namespace Modules\Newsletter;

use App\Application\Modules\DTOs\PagePrefixDTO;
use App\Application\Modules\DTOs\PermissionDTO;
use App\Modules\ModuleServiceProvider;
use Filament\Pages\Page;
use Modules\Newsletter\Application\Services\NewsletterAvailabilityInterface;
use Modules\Newsletter\Application\Services\NewsletterProviderFactoryInterface;
use Modules\Newsletter\Application\Services\NewsletterProviderInterface;
use Modules\Newsletter\Application\Services\NewsletterSettingsRepositoryInterface;
use Modules\Newsletter\Filament\Pages\NewsletterSettingsPage;
use Modules\Newsletter\Infrastructure\Providers\ConfiguredNewsletterProvider;
use Modules\Newsletter\Infrastructure\Providers\NewsletterProviderFactory;
use Modules\Newsletter\Infrastructure\Services\NewsletterAvailability;
use Modules\Newsletter\Infrastructure\Settings\ModuleNewsletterSettingsRepository;

/**
 * Newsletter of the association: subscription to a Mailchimp list from the web.
 *
 * The site never stores subscribers: every question goes to the provider (NewsletterProviderInterface)
 * and every surface asks NewsletterAvailabilityInterface before showing itself.
 */
final class NewsletterServiceProvider extends ModuleServiceProvider
{
    public function moduleName(): string
    {
        return 'newsletter';
    }

    public function register(): void
    {
        parent::register();

        $this->app->bind(NewsletterSettingsRepositoryInterface::class, ModuleNewsletterSettingsRepository::class);
        $this->app->bind(NewsletterProviderFactoryInterface::class, NewsletterProviderFactory::class);
        // Reads the saved settings on every call, so it never keeps an old key
        $this->app->bind(NewsletterProviderInterface::class, ConfiguredNewsletterProvider::class);
        $this->app->bind(NewsletterAvailabilityInterface::class, NewsletterAvailability::class);
    }

    public function getSettingsPage(): string
    {
        return NewsletterSettingsPage::class;
    }

    /**
     * @return array<class-string<Page>>
     */
    public function registerFilamentPages(): array
    {
        return [NewsletterSettingsPage::class];
    }

    /**
     * Stored as "newsletter:newsletter.configure". No role gets it by default: an admin ticks it
     * in the role editor. Leave out "editor": module permissions go to the roles each association
     * creates (tests/Unit/PermissionSuggestionsTest.php).
     *
     * @return array<PermissionDTO>
     */
    public function registerPermissions(): array
    {
        return [
            new PermissionDTO(
                name: 'newsletter.configure',
                label: __('newsletter::messages.permissions.configure'),
                group: __('newsletter::messages.title'),
                module: $this->moduleName(),
            ),
        ];
    }

    /**
     * Module Vue pages are written to resources/js/pages/Newsletter/ and rendered as
     * "Newsletter/<Page>", inside the core's layout.
     *
     * @return array<PagePrefixDTO>
     */
    public function registerPagePrefixes(): array
    {
        return [
            new PagePrefixDTO(prefix: 'Newsletter', module: $this->moduleName(), usesCoreLayout: true),
        ];
    }
}
