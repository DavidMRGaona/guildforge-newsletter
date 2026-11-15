<?php

declare(strict_types=1);

namespace Modules\Newsletter\Tests\Feature\Http;

use App\Application\Impersonation\Services\ImpersonationContextInterface;
use App\Application\Services\SettingsServiceInterface;
use App\Domain\Impersonation\Enums\ImpersonationMode;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Newsletter\Infrastructure\Jobs\SubscribeToNewsletterJob;
use Modules\Newsletter\Infrastructure\Jobs\UnsubscribeFromNewsletterJob;
use Modules\Newsletter\NewsletterServiceProvider;
use Modules\Newsletter\Tests\Support\ConfiguresNewsletter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Modules\ModuleTestCase;

final class ProfileNewsletterTest extends ModuleTestCase
{
    use ConfiguresNewsletter;

    private const string EMAIL = 'ana@example.com';

    protected ?string $moduleName = 'newsletter';

    protected bool $autoEnableModule = true;

    private UserModel $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        $this->configureNewsletter();
        $this->user = UserModel::factory()->create(['email' => self::EMAIL]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function memberStatuses(): array
    {
        return [
            'subscribed' => ['subscribed', 'subscribed'],
            'pending' => ['pending', 'pending'],
            'unsubscribed' => ['unsubscribed', 'unsubscribed'],
            'archived counts as unsubscribed' => ['archived', 'unsubscribed'],
            'bounced address' => ['cleaned', 'blocked'],
        ];
    }

    #[DataProvider('memberStatuses')]
    public function test_the_status_is_asked_to_the_provider_for_the_account_email(string $mailchimp, string $expected): void
    {
        $this->fakeMember($mailchimp);

        $this->actingAs($this->user)->getJson('/perfil/boletin')
            ->assertOk()
            ->assertExactJson(['status' => $expected]);
    }

    public function test_an_address_that_is_not_on_the_list_can_subscribe(): void
    {
        Http::fake([$this->memberUrl(self::EMAIL) => Http::response([], 404)]);

        $this->actingAs($this->user)->getJson('/perfil/boletin')
            ->assertExactJson(['status' => 'unsubscribed']);
    }

    public function test_the_status_is_cached_per_account_and_the_key_never_holds_the_address(): void
    {
        $this->fakeMember('subscribed');

        $this->actingAs($this->user)->getJson('/perfil/boletin')->assertOk();
        $this->actingAs($this->user)->getJson('/perfil/boletin')->assertOk();

        Http::assertSentCount(1);

        $keys = array_keys($this->cacheContents());
        $this->assertNotEmpty($keys);

        foreach ($keys as $key) {
            $this->assertStringNotContainsString('ana', (string) $key);
            $this->assertStringNotContainsString('example.com', (string) $key);
        }
    }

    public function test_another_account_does_not_read_the_cached_status_of_this_one(): void
    {
        $this->fakeMember('subscribed');
        $this->actingAs($this->user)->getJson('/perfil/boletin')->assertOk();

        $other = UserModel::factory()->create(['email' => 'luis@example.com']);
        Http::fake([$this->memberUrl('luis@example.com') => Http::response([], 404)]);

        $this->actingAs($other)->getJson('/perfil/boletin')->assertExactJson(['status' => 'unsubscribed']);
    }

    public function test_the_status_is_asked_again_after_the_cache_expires(): void
    {
        $this->fakeMember('pending');
        $this->actingAs($this->user)->getJson('/perfil/boletin')->assertOk();

        $this->travel(6)->minutes();
        $this->actingAs($this->user)->getJson('/perfil/boletin')->assertOk();

        Http::assertSentCount(2);
    }

    public function test_a_provider_failure_answers_unavailable_and_is_not_cached(): void
    {
        Http::fake([$this->memberUrl(self::EMAIL) => Http::sequence()
            ->push([], 500)
            ->push(['status' => 'subscribed'], 200)]);

        $this->actingAs($this->user)->getJson('/perfil/boletin')
            ->assertOk()
            ->assertExactJson(['status' => 'unavailable']);

        $this->actingAs($this->user)->getJson('/perfil/boletin')->assertExactJson(['status' => 'subscribed']);
    }

    public function test_subscribing_queues_the_request_and_shows_the_confirmation_as_pending(): void
    {
        $this->fakeMember('unsubscribed');

        $this->actingAs($this->user)->postJson('/perfil/boletin')
            ->assertOk()
            ->assertExactJson(['status' => 'pending']);

        Queue::assertPushed(SubscribeToNewsletterJob::class, fn (SubscribeToNewsletterJob $job): bool => $job->email === self::EMAIL);

        $this->actingAs($this->user)->getJson('/perfil/boletin')->assertExactJson(['status' => 'pending']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function statusesThatCannotSubscribe(): array
    {
        return [
            'subscribed' => ['subscribed'],
            'pending' => ['pending'],
            'bounced' => ['cleaned'],
        ];
    }

    #[DataProvider('statusesThatCannotSubscribe')]
    public function test_subscribing_does_nothing_when_the_address_cannot_join(string $mailchimp): void
    {
        $this->fakeMember($mailchimp);

        $this->actingAs($this->user)->postJson('/perfil/boletin')->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_a_bounced_address_is_never_offered_the_subscription(): void
    {
        $this->fakeMember('cleaned');

        $this->actingAs($this->user)->postJson('/perfil/boletin')
            ->assertOk()
            ->assertExactJson(['status' => 'blocked']);
    }

    public function test_unsubscribing_queues_the_request_and_shows_the_address_as_unsubscribed(): void
    {
        $this->fakeMember('subscribed');

        $this->actingAs($this->user)->deleteJson('/perfil/boletin')
            ->assertOk()
            ->assertExactJson(['status' => 'unsubscribed']);

        Queue::assertPushed(UnsubscribeFromNewsletterJob::class, fn (UnsubscribeFromNewsletterJob $job): bool => $job->email === self::EMAIL);

        $this->actingAs($this->user)->getJson('/perfil/boletin')->assertExactJson(['status' => 'unsubscribed']);
    }

    public function test_unsubscribing_does_nothing_for_an_address_that_is_not_on_the_list(): void
    {
        $this->fakeMember('unsubscribed');

        $this->actingAs($this->user)->deleteJson('/perfil/boletin')
            ->assertOk()
            ->assertExactJson(['status' => 'unsubscribed']);

        Queue::assertNothingPushed();
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->getJson('/perfil/boletin')->assertUnauthorized();
        $this->postJson('/perfil/boletin')->assertUnauthorized();
        $this->deleteJson('/perfil/boletin')->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_someone_who_must_verify_their_email_first_is_stopped(): void
    {
        $this->app->make(SettingsServiceInterface::class)->set('auth_email_verification_required', '1');
        $user = UserModel::factory()->unverified()->create();

        foreach (['getJson', 'postJson', 'deleteJson'] as $method) {
            $this->actingAs($user)->{$method}('/perfil/boletin')
                ->assertForbidden()
                ->assertJsonPath('error', 'email_verification_required');
        }

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_an_unverified_address_is_refused_even_when_the_site_does_not_require_verification(): void
    {
        $user = UserModel::factory()->unverified()->create(['email' => 'luis@example.com']);

        foreach (['getJson', 'postJson', 'deleteJson'] as $method) {
            $this->actingAs($user)->{$method}('/perfil/boletin')
                ->assertForbidden()
                ->assertJsonPath('error', 'email_verification_required');
        }

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_the_routes_do_not_exist_while_the_newsletter_is_not_available(): void
    {
        $this->configureNewsletter(lastCheckOk: false);

        $this->actingAs($this->user)->getJson('/perfil/boletin')->assertNotFound();
        $this->actingAs($this->user)->postJson('/perfil/boletin')->assertNotFound();
        $this->actingAs($this->user)->deleteJson('/perfil/boletin')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_nothing_is_asked_or_written_during_an_impersonation(): void
    {
        $this->app->instance(ImpersonationContextInterface::class, $this->impersonating($this->user));

        $this->actingAs($this->user)->getJson('/perfil/boletin')->assertExactJson(['status' => 'unavailable']);
        $this->actingAs($this->user)->postJson('/perfil/boletin')->assertExactJson(['status' => 'unavailable']);
        $this->actingAs($this->user)->deleteJson('/perfil/boletin')->assertExactJson(['status' => 'unavailable']);

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_writes_are_limited_per_account(): void
    {
        $this->fakeMember('subscribed');

        foreach (range(1, 6) as $attempt) {
            $this->actingAs($this->user)->deleteJson('/perfil/boletin')->assertOk();
        }

        $this->actingAs($this->user)->deleteJson('/perfil/boletin')->assertTooManyRequests();
    }

    public function test_the_profile_shows_the_section_without_asking_the_provider(): void
    {
        $this->actingAs($this->user)->get('/perfil')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('profileNewsletterVisible', true)
                ->where('profileNewsletter.endpoint', '/perfil/boletin'));

        Http::assertNothingSent();
    }

    public function test_the_profile_hides_the_section_while_the_newsletter_is_not_available(): void
    {
        $this->configureNewsletter(lastCheckOk: false);

        $this->actingAs($this->user)->get('/perfil')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('profileNewsletterVisible', false)
                ->where('profileNewsletter', null));
    }

    public function test_the_profile_hides_the_section_during_an_impersonation(): void
    {
        $this->app->instance(ImpersonationContextInterface::class, $this->impersonating($this->user));

        $this->actingAs($this->user)->get('/perfil')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('profileNewsletterVisible', false));

        Http::assertNothingSent();
    }

    public function test_the_section_is_registered_as_a_profile_tab(): void
    {
        $slots = array_map(
            static fn ($slot): array => $slot->toArray(),
            (new NewsletterServiceProvider($this->app))->registerSlots(),
        );

        $this->assertCount(1, $slots);
        $this->assertSame('profile-sections', $slots[0]['slot']);
        $this->assertSame('components/profile/ProfileNewsletterSection.vue', $slots[0]['component']);
        $this->assertSame('profileNewsletterVisible', $slots[0]['profileTab']['visibleKey']);
    }

    private function fakeMember(string $status): void
    {
        Http::fake([$this->memberUrl(self::EMAIL) => Http::response(['status' => $status], 200)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function cacheContents(): array
    {
        // The array store used by the tests keeps its values in memory
        $store = Cache::getStore();

        return (fn (): array => $this->storage ?? [])->call($store);
    }

    private function impersonating(Authenticatable $target): ImpersonationContextInterface
    {
        return new readonly class($target) implements ImpersonationContextInterface
        {
            public function __construct(private Authenticatable $target) {}

            public function isActive(): bool
            {
                return true;
            }

            public function impersonator(): ?Authenticatable
            {
                return null;
            }

            public function target(): Authenticatable
            {
                return $this->target;
            }

            public function mode(): ImpersonationMode
            {
                return ImpersonationMode::Simulate;
            }
        };
    }
}
