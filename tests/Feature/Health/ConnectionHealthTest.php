<?php

declare(strict_types=1);

namespace Modules\Newsletter\Tests\Feature\Health;

use App\Application\Impersonation\Services\ImpersonationContextInterface;
use App\Domain\Impersonation\Enums\ImpersonationMode;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Modules\Newsletter\Application\DTOs\NewsletterSettings;
use Modules\Newsletter\Application\Services\ConnectionHealthInterface;
use Modules\Newsletter\Application\Services\NewsletterAvailabilityInterface;
use Modules\Newsletter\Application\Services\NewsletterSettingsRepositoryInterface;
use Modules\Newsletter\Application\Services\SubscribeFailureCounterInterface;
use Modules\Newsletter\Domain\Enums\ConnectionCheckStatus;
use Modules\Newsletter\Domain\Enums\ConnectionFailure;
use Modules\Newsletter\Domain\ValueObjects\ConnectionCheck;
use Modules\Newsletter\Domain\ValueObjects\MailchimpApiKey;
use Modules\Newsletter\Infrastructure\Jobs\CheckNewsletterConnectionJob;
use Modules\Newsletter\Notifications\ProviderDownNotification;
use Modules\Newsletter\Tests\Support\ConfiguresNewsletter;
use Tests\Support\Modules\ModuleTestCase;

final class ConnectionHealthTest extends ModuleTestCase
{
    use ConfiguresNewsletter;

    private const string LISTS_URL = 'https://us21.api.mailchimp.com/3.0/lists*';

    protected ?string $moduleName = 'newsletter';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(new DateTimeImmutable('2026-11-15 06:00:00'));
        Http::preventStrayRequests();
        Notification::fake();
    }

    public function test_a_good_check_is_recorded_and_tells_nobody(): void
    {
        $this->configureNewsletter();
        $this->holder();
        Http::fake([self::LISTS_URL => $this->lists()]);

        $check = $this->check();

        $this->assertTrue($check?->ok());
        $this->assertTrue($this->saved()->lastCheck?->ok());
        $this->assertSame('2026-11-15', $this->saved()->lastCheck->checkedAt->format('Y-m-d'));
        Notification::assertNothingSent();
    }

    public function test_the_first_failure_switches_the_module_off_and_tells_whoever_can_configure_it_once(): void
    {
        $this->configureNewsletter();
        $holder = $this->holder();
        $someoneElse = UserModel::factory()->create();
        Http::fake([self::LISTS_URL => Http::response(['title' => 'API Key Invalid'], 401)]);

        $check = $this->check();

        $this->assertSame(ConnectionFailure::InvalidApiKey, $check?->failure);
        $this->assertFalse($this->app->make(NewsletterAvailabilityInterface::class)->isAvailable());
        Notification::assertSentTo($holder, ProviderDownNotification::class, fn (ProviderDownNotification $notice): bool => $notice->failure() === ConnectionFailure::InvalidApiKey);
        Notification::assertNotSentTo($someoneElse, ProviderDownNotification::class);
        Notification::assertSentTimes(ProviderDownNotification::class, 1);
    }

    public function test_a_second_failing_check_of_the_same_outage_tells_nobody_again(): void
    {
        $this->configureNewsletter();
        $this->holder();
        Http::fake([self::LISTS_URL => Http::response(['title' => 'API Key Invalid'], 401)]);

        $this->check();
        $this->travel(1)->days();
        $this->check();

        Notification::assertSentTimes(ProviderDownNotification::class, 1);
    }

    public function test_a_good_check_restores_the_module_and_the_next_outage_tells_again(): void
    {
        $this->configureNewsletter();
        $this->holder();
        Http::fake([self::LISTS_URL => Http::sequence()
            ->push(['title' => 'API Key Invalid'], 401)
            ->push($this->listsBody())
            ->push(['title' => 'API Key Invalid'], 401)]);

        $this->check();
        $this->assertFalse($this->availability());

        $this->travel(1)->days();
        $this->check();
        $this->assertTrue($this->availability());

        $this->travel(1)->days();
        $this->check();
        $this->assertFalse($this->availability());
        Notification::assertSentTimes(ProviderDownNotification::class, 2);
    }

    public function test_an_outage_found_by_saving_the_settings_is_not_announced_again_by_the_daily_check(): void
    {
        $this->configureNewsletter(lastCheckOk: false);
        $this->holder();
        Http::fake([self::LISTS_URL => Http::response(['title' => 'API Key Invalid'], 401)]);

        $this->check();

        Notification::assertNothingSent();
    }

    public function test_a_module_never_checked_is_not_announced_as_down(): void
    {
        $this->app->make(NewsletterSettingsRepositoryInterface::class)->save(new NewsletterSettings(
            apiKey: new MailchimpApiKey('fakemailchimpkeyfakemailchimpkey', 'us21'),
            listId: self::LIST_ID,
        ));
        $this->holder();
        Http::fake([self::LISTS_URL => Http::response(['title' => 'API Key Invalid'], 401)]);

        $check = $this->check();

        $this->assertFalse($check?->ok());
        $this->assertNotNull($this->saved()->lastCheck);
        Notification::assertNothingSent();
    }

    public function test_nothing_is_checked_while_nothing_is_configured(): void
    {
        Http::fake();

        $this->assertNull($this->check());

        Http::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_a_check_started_before_the_settings_changed_neither_records_nor_tells(): void
    {
        $this->configureNewsletter();
        $this->holder();
        // While Mailchimp answers, someone saves another key
        Http::fake([self::LISTS_URL => function (Request $request) {
            $this->app->make(NewsletterSettingsRepositoryInterface::class)->save(new NewsletterSettings(
                apiKey: new MailchimpApiKey('anotherfakekeyanotherfakekey00', 'us6'),
                listId: self::LIST_ID,
                lastCheck: new ConnectionCheck(new DateTimeImmutable('2026-11-15 05:59:59'), null),
            ));

            return Http::response(['title' => 'API Key Invalid'], 401);
        }]);

        $check = $this->check();

        $this->assertNull($check);
        $saved = $this->saved();
        $this->assertSame('anotherfakekeyanotherfakekey00-us6', $saved->apiKey?->value());
        $this->assertTrue($saved->lastCheck?->ok());
        Notification::assertNothingSent();
    }

    public function test_nothing_is_checked_nor_announced_during_an_impersonation(): void
    {
        $this->configureNewsletter();
        $this->holder();
        Http::fake();
        $this->mock(ImpersonationContextInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isActive')->andReturnTrue();
            $mock->shouldReceive('mode')->andReturn(ImpersonationMode::Simulate);
        });

        $this->assertNull($this->check());
        $this->assertFalse($this->health()->checkAfterFailures(5));

        Http::assertNothingSent();
        Notification::assertNothingSent();
        $this->assertTrue($this->saved()->lastCheck?->ok());
    }

    public function test_two_checks_at_once_run_one_after_the_other_never_together(): void
    {
        $this->configureNewsletter();
        Http::fake([self::LISTS_URL => $this->lists()]);
        $lock = Cache::lock('newsletter:connection-check', 120);
        $this->assertTrue($lock->get());

        $this->assertNull($this->check());

        Http::assertNothingSent();
        $lock->release();
    }

    public function test_a_check_that_blows_up_counts_as_failed_not_as_ok(): void
    {
        $this->configureNewsletter();
        Http::fake([self::LISTS_URL => fn () => throw new \RuntimeException('boom')]);

        $check = $this->check();

        $this->assertFalse($check?->ok());
        $this->assertFalse($this->saved()->lastCheck?->ok());
    }

    public function test_the_third_failure_in_a_row_asks_for_a_check(): void
    {
        Queue::fake();
        $this->configureNewsletter();

        $this->assertFalse($this->health()->checkAfterFailures(1));
        $this->assertFalse($this->health()->checkAfterFailures(2));
        Queue::assertNothingPushed();

        $this->assertTrue($this->health()->checkAfterFailures(3));
        Queue::assertPushed(CheckNewsletterConnectionJob::class, 1);
    }

    public function test_asking_for_a_check_is_limited_to_one_every_fifteen_minutes(): void
    {
        Queue::fake();
        $this->configureNewsletter();

        $this->assertTrue($this->health()->checkAfterFailures(3));
        $this->assertFalse($this->health()->checkAfterFailures(4));
        $this->travel(14)->minutes();
        $this->assertFalse($this->health()->checkAfterFailures(9));
        Queue::assertPushed(CheckNewsletterConnectionJob::class, 1);

        $this->travel(2)->minutes();
        $this->assertTrue($this->health()->checkAfterFailures(10));
        Queue::assertPushed(CheckNewsletterConnectionJob::class, 2);
    }

    public function test_asking_for_a_check_never_switches_the_module_off_by_itself(): void
    {
        Queue::fake();
        $this->configureNewsletter();

        $this->health()->checkAfterFailures(50);

        $this->assertTrue($this->availability());
        $this->assertTrue($this->saved()->lastCheck?->ok());
        Notification::assertNothingSent();
    }

    public function test_the_queued_check_runs_the_check(): void
    {
        $this->configureNewsletter();
        $holder = $this->holder();
        Http::fake([self::LISTS_URL => Http::response(['title' => 'API Key Invalid'], 401)]);

        dispatch_sync(new CheckNewsletterConnectionJob);

        $this->assertFalse($this->availability());
        Notification::assertSentTo($holder, ProviderDownNotification::class);
    }

    public function test_an_unreachable_provider_is_not_an_outage_at_the_first_answer(): void
    {
        $this->configureNewsletter();
        $this->holder();
        Http::fake([self::LISTS_URL => Http::response([], 503)]);

        $run = $this->health()->check();

        $this->assertSame(ConnectionCheckStatus::Pending, $run->status);
        $this->assertTrue($this->availability());
        $this->assertTrue($this->saved()->lastCheck?->ok());
        Notification::assertNothingSent();
        $this->assertTrue($this->health()->needsRecheck());
    }

    public function test_two_unreachable_answers_less_than_ten_minutes_apart_are_still_not_an_outage(): void
    {
        $this->configureNewsletter();
        $this->holder();
        Http::fake([self::LISTS_URL => Http::response([], 429)]);

        $this->health()->check();
        $this->travel(9)->minutes();
        $run = $this->health()->check();

        $this->assertSame(ConnectionCheckStatus::Pending, $run->status);
        $this->assertTrue($this->availability());
        Notification::assertNothingSent();
    }

    public function test_two_unreachable_answers_ten_minutes_apart_are_an_outage_told_once(): void
    {
        $this->configureNewsletter();
        $holder = $this->holder();
        Http::fake([self::LISTS_URL => Http::response([], 500)]);

        $this->health()->check();
        $this->travel(10)->minutes();
        $run = $this->health()->check();

        $this->assertSame(ConnectionCheckStatus::Recorded, $run->status);
        $this->assertSame(ConnectionFailure::Unreachable, $run->check?->failure);
        $this->assertFalse($this->availability());
        Notification::assertSentTo($holder, ProviderDownNotification::class);

        // The outage goes on: no second notice, and it is rechecked soon
        $this->assertTrue($this->health()->needsRecheck());
        $this->travel(30)->minutes();
        $this->health()->check();
        Notification::assertSentTimes(ProviderDownNotification::class, 1);
    }

    public function test_an_answer_in_between_forgets_the_first_unreachable_one(): void
    {
        $this->configureNewsletter();
        $this->holder();
        Http::fake([self::LISTS_URL => Http::sequence()
            ->push([], 503)
            ->push($this->listsBody())
            ->push([], 503)]);

        $this->health()->check();
        $this->travel(15)->minutes();
        $this->health()->check();
        $this->travel(15)->minutes();
        $run = $this->health()->check();

        $this->assertSame(ConnectionCheckStatus::Pending, $run->status);
        $this->assertTrue($this->availability());
        Notification::assertNothingSent();
    }

    public function test_a_failure_that_is_not_unreachable_is_an_outage_at_once(): void
    {
        $this->configureNewsletter();
        $holder = $this->holder();
        Http::fake([self::LISTS_URL => Http::response(['title' => 'API Key Invalid'], 401)]);

        $this->assertSame(ConnectionCheckStatus::Recorded, $this->health()->check()->status);

        Notification::assertSentTo($holder, ProviderDownNotification::class);
    }

    public function test_the_provider_coming_back_restores_the_module_at_the_next_recheck(): void
    {
        $this->configureNewsletter(lastCheckOk: false);
        Http::fake([self::LISTS_URL => $this->lists()]);

        $this->assertTrue($this->health()->needsRecheck());
        $this->health()->check();

        $this->assertTrue($this->availability());
        $this->assertFalse($this->health()->needsRecheck());
    }

    public function test_a_slow_failing_check_never_overwrites_a_newer_good_one_saved_with_the_same_key(): void
    {
        $this->configureNewsletter(lastCheckOk: false);
        $this->holder();
        // While Mailchimp answers, the panel saves the same key with a good check
        Http::fake([self::LISTS_URL => function () {
            $this->app->make(NewsletterSettingsRepositoryInterface::class)->save(new NewsletterSettings(
                apiKey: new MailchimpApiKey('fakemailchimpkeyfakemailchimpkey', 'us21'),
                listId: self::LIST_ID,
                listName: 'Socios',
                lastCheck: new ConnectionCheck(new DateTimeImmutable('2026-11-15 05:59:59'), null),
            ));

            return Http::response(['title' => 'API Key Invalid'], 401);
        }]);

        $run = $this->health()->check();

        $this->assertSame(ConnectionCheckStatus::Skipped, $run->status);
        $this->assertTrue($this->availability());
        Notification::assertNothingSent();
    }

    public function test_nothing_to_recheck_while_everything_works(): void
    {
        $this->configureNewsletter();

        $this->assertFalse($this->health()->needsRecheck());
    }

    public function test_a_check_that_cannot_run_is_reported_as_an_error(): void
    {
        $this->configureNewsletter();
        $this->mock(NewsletterSettingsRepositoryInterface::class)
            ->shouldReceive('get')->andThrow(new \RuntimeException('database down'));

        $this->assertSame(ConnectionCheckStatus::Error, $this->health()->check()->status);
    }

    public function test_five_refused_sign_ups_with_a_working_connection_tell_whoever_configures_once_and_keep_the_newsletter_on(): void
    {
        $this->configureNewsletter();
        $holder = $this->holder();
        Http::fake([self::LISTS_URL => $this->lists()]);
        $counter = $this->app->make(SubscribeFailureCounterInterface::class);

        for ($i = 0; $i < 4; $i++) {
            $counter->recordFailure();
        }
        $this->health()->check();
        Notification::assertNothingSent();

        $counter->recordFailure();
        $this->health()->check();
        $this->health()->check();

        Notification::assertSentToTimes($holder, ProviderDownNotification::class, 1);
        Notification::assertSentTo($holder, ProviderDownNotification::class, fn (ProviderDownNotification $notice): bool => ! $notice->failure() instanceof ConnectionFailure);
        $this->assertTrue($this->availability());
    }

    public function test_a_sign_up_that_goes_through_lets_the_next_refused_streak_be_told(): void
    {
        $this->configureNewsletter();
        $this->holder();
        Http::fake([self::LISTS_URL => $this->lists()]);
        $counter = $this->app->make(SubscribeFailureCounterInterface::class);

        for ($i = 0; $i < 5; $i++) {
            $counter->recordFailure();
        }
        $this->health()->check();
        $counter->reset();
        $this->health()->subscriptionsWorking();
        for ($i = 0; $i < 5; $i++) {
            $counter->recordFailure();
        }
        $this->health()->check();

        Notification::assertSentTimes(ProviderDownNotification::class, 2);
    }

    private function check(): ?ConnectionCheck
    {
        return $this->health()->check()->check;
    }

    private function health(): ConnectionHealthInterface
    {
        return $this->app->make(ConnectionHealthInterface::class);
    }

    private function availability(): bool
    {
        // Scoped: a fresh copy, as the next request would have
        $this->app->forgetScopedInstances();

        return $this->app->make(NewsletterAvailabilityInterface::class)->isAvailable();
    }

    private function saved(): NewsletterSettings
    {
        $this->app->forgetScopedInstances();

        return $this->app->make(NewsletterSettingsRepositoryInterface::class)->get();
    }

    private function lists(): Response|PromiseInterface
    {
        return Http::response($this->listsBody(), 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function listsBody(): array
    {
        return ['lists' => [['id' => self::LIST_ID, 'name' => 'Socios']]];
    }

    private function holder(): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'boletin_'.uniqid(), 'display_name' => 'Boletín']);
        $permission = PermissionModel::query()->firstOrCreate(
            ['key' => 'newsletter:newsletter.configure'],
            ['label' => 'Configurar', 'resource' => 'newsletter', 'action' => 'configure', 'module' => 'newsletter'],
        );
        $role->permissions()->attach($permission->id);
        $user = UserModel::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
