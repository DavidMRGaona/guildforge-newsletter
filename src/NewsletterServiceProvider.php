<?php

declare(strict_types=1);

namespace Modules\Newsletter;

use App\Application\Modules\DTOs\PagePrefixDTO;
use App\Application\Modules\DTOs\PermissionDTO;
use App\Modules\ModuleServiceProvider;
use Filament\Pages\Page;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Newsletter\Application\Services\NewsletterAvailabilityInterface;
use Modules\Newsletter\Application\Services\NewsletterProviderFactoryInterface;
use Modules\Newsletter\Application\Services\NewsletterProviderInterface;
use Modules\Newsletter\Application\Services\NewsletterSettingsRepositoryInterface;
use Modules\Newsletter\Application\Services\SubscribeFailureCounterInterface;
use Modules\Newsletter\Application\Services\SubscriptionRequesterInterface;
use Modules\Newsletter\Filament\Pages\NewsletterSettingsPage;
use Modules\Newsletter\Filament\SiteBlocks\SubscribeSiteBlock;
use Modules\Newsletter\Infrastructure\Providers\ConfiguredNewsletterProvider;
use Modules\Newsletter\Infrastructure\Providers\NewsletterProviderFactory;
use Modules\Newsletter\Infrastructure\Services\CacheSubscribeFailureCounter;
use Modules\Newsletter\Infrastructure\Services\NewsletterAvailability;
use Modules\Newsletter\Infrastructure\Services\QueuedSubscriptionRequester;
use Modules\Newsletter\Infrastructure\Settings\ModuleNewsletterSettingsRepository;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

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

        // Scoped: read and decrypted once per request or queued job (save() drops the copy)
        $this->app->scoped(NewsletterSettingsRepositoryInterface::class, ModuleNewsletterSettingsRepository::class);
        $this->app->bind(NewsletterProviderFactoryInterface::class, NewsletterProviderFactory::class);
        // Asks the repository on every call, so a long-running worker never keeps an old key
        $this->app->bind(NewsletterProviderInterface::class, ConfiguredNewsletterProvider::class);
        $this->app->scoped(NewsletterAvailabilityInterface::class, NewsletterAvailability::class);
        $this->app->bind(SubscriptionRequesterInterface::class, QueuedSubscriptionRequester::class);
        // The default cache store, shared by every worker
        $this->app->bind(SubscribeFailureCounterInterface::class, static fn ($app): CacheSubscribeFailureCounter => new CacheSubscribeFailureCounter(
            $app->make(CacheFactory::class)->store(),
        ));
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerRateLimiters();
    }

    /**
     * @return array<class-string<SubscribeSiteBlock>>
     */
    public function registerSiteBlocks(): array
    {
        return [SubscribeSiteBlock::class];
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

    /**
     * Subscriptions per IP: enough for a household sharing a connection, too few to fill the list
     * with made-up addresses or to flood someone's inbox with confirmations. Over the limit, a
     * translated message, never an error page.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('newsletter-subscribe', static function (Request $request): array {
            $ip = $request->ip() ?? 'unknown';
            $tooMany = static function (Request $request, array $headers): HttpResponse {
                $message = (string) __('newsletter::messages.subscribe.errors.too_many_attempts');

                if ($request->header('X-Inertia') === null && $request->expectsJson()) {
                    return response()->json(['message' => $message], 429, $headers);
                }

                return back()->withErrors(['email' => $message]);
            };

            // Distinct keys: limits sharing a key would share one counter
            return [
                Limit::perMinute(5)->by('newsletter-minute|'.$ip)->response($tooMany),
                Limit::perHour(20)->by('newsletter-hour|'.$ip)->response($tooMany),
            ];
        });
    }
}
