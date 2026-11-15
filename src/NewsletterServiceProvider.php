<?php

declare(strict_types=1);

namespace Modules\Newsletter;

use App\Application\Impersonation\Services\ImpersonationContextInterface;
use App\Application\Modules\DTOs\PagePrefixDTO;
use App\Application\Modules\DTOs\PermissionDTO;
use App\Application\Modules\DTOs\SlotRegistrationDTO;
use App\Domain\Events\RegistrationFieldsSubmitted;
use App\Modules\ModuleServiceProvider;
use Filament\Pages\Page;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Modules\Newsletter\Application\Services\NewsletterAvailabilityInterface;
use Modules\Newsletter\Application\Services\NewsletterProviderFactoryInterface;
use Modules\Newsletter\Application\Services\NewsletterProviderInterface;
use Modules\Newsletter\Application\Services\NewsletterSettingsRepositoryInterface;
use Modules\Newsletter\Application\Services\ProfileNewsletterServiceInterface;
use Modules\Newsletter\Application\Services\SubscribeFailureCounterInterface;
use Modules\Newsletter\Application\Services\SubscriptionRequesterInterface;
use Modules\Newsletter\Filament\Pages\NewsletterSettingsPage;
use Modules\Newsletter\Filament\SiteBlocks\SubscribeSiteBlock;
use Modules\Newsletter\Infrastructure\Providers\ConfiguredNewsletterProvider;
use Modules\Newsletter\Infrastructure\Providers\NewsletterProviderFactory;
use Modules\Newsletter\Infrastructure\Registration\SubscribeAfterRegistration;
use Modules\Newsletter\Infrastructure\Registration\SubscribeOnRegistrationField;
use Modules\Newsletter\Infrastructure\Services\CacheSubscribeFailureCounter;
use Modules\Newsletter\Infrastructure\Services\NewsletterAvailability;
use Modules\Newsletter\Infrastructure\Services\ProfileNewsletterService;
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
        $this->app->bind(ProfileNewsletterServiceInterface::class, ProfileNewsletterService::class);
        // The default cache store, shared by every worker
        $this->app->bind(SubscribeFailureCounterInterface::class, static fn ($app): CacheSubscribeFailureCounter => new CacheSubscribeFailureCounter(
            $app->make(CacheFactory::class)->store(),
        ));
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerRateLimiters();
        $this->shareProfileSection();

        // Queued and after the commit (core#699); the listener never runs inside the registration
        Event::listen(RegistrationFieldsSubmitted::class, SubscribeAfterRegistration::class);
    }

    /**
     * @return array<class-string<SubscribeOnRegistrationField>>
     */
    public function registerRegistrationFields(): array
    {
        return [SubscribeOnRegistrationField::class];
    }

    /**
     * The «Boletín» tab of the profile. The status is not part of the page: the section asks for it
     * when it is opened, so the profile never waits for the provider.
     *
     * @return array<SlotRegistrationDTO>
     */
    public function registerSlots(): array
    {
        return [
            new SlotRegistrationDTO(
                slot: 'profile-sections',
                component: 'components/profile/ProfileNewsletterSection.vue',
                module: $this->moduleName(),
                order: 60,
                // visibleKey is read from the slot's props, so it is listed too
                dataKeys: ['profileNewsletter', 'profileNewsletterVisible'],
                profileTab: [
                    'tabId' => 'newsletter',
                    'icon' => 'envelope',
                    'labelKey' => 'newsletter.profile.tabLabel',
                    'visibleKey' => 'profileNewsletterVisible',
                ],
            ),
        ];
    }

    /**
     * What the profile page needs to show the tab: where to ask, and whether to show it at all.
     * Both only on the profile, for a signed-in account, while the newsletter is available and
     * nobody is impersonating (nothing is asked or written then).
     */
    private function shareProfileSection(): void
    {
        $visible = static function (): bool {
            $request = request();

            if ($request->route()?->getName() !== 'profile.show' || auth()->user() === null) {
                return false;
            }

            return ! app(ImpersonationContextInterface::class)->isActive()
                && app(NewsletterAvailabilityInterface::class)->isAvailable();
        };

        Inertia::share('profileNewsletterVisible', static fn (): ?bool => request()->route()?->getName() === 'profile.show' ? $visible() : null);
        Inertia::share('profileNewsletter', static fn (): ?array => $visible() ? [
            'endpoint' => route('newsletter.profile', absolute: false),
            // An address nobody proved theirs is never read or changed (ProfileNewsletterController)
            'verified' => auth()->user()?->hasVerifiedEmail() === true,
            'verifyUrl' => route('verification.notice', absolute: false),
        ] : null);
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
        RateLimiter::for('newsletter-subscribe', static function (Request $request): Limit|array {
            // Laravel runs the limit before the route's other middleware: while the newsletter is
            // not available, let EnsureNewsletterIsAvailable answer the same 404 to everyone
            if (! app(NewsletterAvailabilityInterface::class)->isAvailable()) {
                return Limit::none();
            }

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

        // The profile section, per account: reading is cheap and remembered, changing is not
        RateLimiter::for('newsletter-profile', static function (Request $request): Limit {
            if (! app(NewsletterAvailabilityInterface::class)->isAvailable()) {
                return Limit::none();
            }

            $who = (string) ($request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown');
            $tooMany = static fn (Request $request, array $headers): HttpResponse => response()->json([
                'message' => (string) __('newsletter::messages.profile.errors.too_many_attempts'),
            ], 429, $headers);

            return $request->isMethodSafe()
                ? Limit::perMinute(30)->by('newsletter-profile-read|'.$who)->response($tooMany)
                : Limit::perMinute(6)->by('newsletter-profile-write|'.$who)->response($tooMany);
        });
    }
}
