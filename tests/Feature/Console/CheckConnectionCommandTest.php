<?php

declare(strict_types=1);

namespace Modules\Newsletter\Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Modules\Newsletter\Application\Services\NewsletterAvailabilityInterface;
use Modules\Newsletter\Application\Services\NewsletterSettingsRepositoryInterface;
use Modules\Newsletter\Tests\Support\ConfiguresNewsletter;
use Tests\Support\Modules\ModuleTestCase;

final class CheckConnectionCommandTest extends ModuleTestCase
{
    use ConfiguresNewsletter;

    protected ?string $moduleName = 'newsletter';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Notification::fake();
    }

    public function test_it_is_scheduled_once_a_day(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'newsletter:check-connection') && ! str_contains((string) $event->command, '--recheck'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertNotNull($event);
        $this->assertMatchesRegularExpression('/^\d+ 6 \* \* \*$/', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_a_working_connection_exits_cleanly_and_keeps_the_newsletter_on(): void
    {
        $this->configureNewsletter();
        Http::fake(['https://us21.api.mailchimp.com/3.0/lists*' => Http::response(['lists' => [['id' => self::LIST_ID, 'name' => 'Socios']]], 200)]);

        $this->assertSame(0, Artisan::call('newsletter:check-connection'));
        $this->assertStringContainsString('The connection works.', Artisan::output());

        $this->assertTrue($this->app->make(NewsletterAvailabilityInterface::class)->isAvailable());
    }

    public function test_a_failing_connection_exits_with_an_error_and_hides_the_newsletter(): void
    {
        $this->configureNewsletter();
        Http::fake(['https://us21.api.mailchimp.com/3.0/lists*' => Http::response(['title' => 'API Key Invalid'], 401)]);

        $this->assertSame(1, Artisan::call('newsletter:check-connection'));
        $this->assertStringContainsString('invalid_api_key', Artisan::output());

        $this->app->forgetScopedInstances();
        $this->assertFalse($this->app->make(NewsletterAvailabilityInterface::class)->isAvailable());
    }

    public function test_it_exits_with_an_error_when_the_check_could_not_run(): void
    {
        $this->configureNewsletter();
        $this->mock(NewsletterSettingsRepositoryInterface::class)
            ->shouldReceive('get')->andThrow(new \RuntimeException('database down'));

        $this->assertSame(1, Artisan::call('newsletter:check-connection'));
        $this->assertStringContainsString('could not run', Artisan::output());
    }

    public function test_a_first_unreachable_answer_exits_cleanly_and_keeps_the_newsletter_on(): void
    {
        $this->configureNewsletter();
        Http::fake(['https://us21.api.mailchimp.com/3.0/lists*' => Http::response([], 503)]);

        $this->assertSame(0, Artisan::call('newsletter:check-connection'));

        $this->app->forgetScopedInstances();
        $this->assertTrue($this->app->make(NewsletterAvailabilityInterface::class)->isAvailable());
    }

    public function test_the_recheck_is_scheduled_every_thirty_minutes(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'newsletter:check-connection --recheck'));

        $this->assertCount(1, $events);
        $this->assertSame('*/30 * * * *', $events->first()?->expression);
    }

    public function test_the_recheck_does_nothing_while_everything_works(): void
    {
        $this->configureNewsletter();
        Http::fake();

        $this->assertSame(0, Artisan::call('newsletter:check-connection', ['--recheck' => true]));

        Http::assertNothingSent();
    }

    public function test_the_recheck_restores_the_newsletter_when_mailchimp_is_back(): void
    {
        $this->configureNewsletter(lastCheckOk: false);
        Http::fake(['https://us21.api.mailchimp.com/3.0/lists*' => Http::response(['lists' => [['id' => self::LIST_ID, 'name' => 'Socios']]], 200)]);

        $this->assertSame(0, Artisan::call('newsletter:check-connection', ['--recheck' => true]));

        $this->app->forgetScopedInstances();
        $this->assertTrue($this->app->make(NewsletterAvailabilityInterface::class)->isAvailable());
    }

    public function test_it_does_nothing_when_nothing_is_configured(): void
    {
        Http::fake();

        $this->assertSame(0, Artisan::call('newsletter:check-connection'));

        Http::assertNothingSent();
    }
}
