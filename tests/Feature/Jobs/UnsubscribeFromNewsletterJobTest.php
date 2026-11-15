<?php

declare(strict_types=1);

namespace Modules\Newsletter\Tests\Feature\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Newsletter\Domain\Enums\ProfileNewsletterState;
use Modules\Newsletter\Infrastructure\Jobs\ForgetsFailedJobCopy;
use Modules\Newsletter\Infrastructure\Jobs\SubscribeToNewsletterJob;
use Modules\Newsletter\Infrastructure\Jobs\UnsubscribeFromNewsletterJob;
use Modules\Newsletter\Infrastructure\Services\ProfileNewsletterService;
use Modules\Newsletter\Tests\Support\ConfiguresNewsletter;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;

final class UnsubscribeFromNewsletterJobTest extends ModuleTestCase
{
    use ConfiguresNewsletter;

    private const string EMAIL = 'ana@example.com';

    private const string USER = '0199c0de-0000-7000-8000-000000000001';

    protected ?string $moduleName = 'newsletter';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->configureNewsletter();
    }

    public function test_it_is_queued_encrypted_and_retried_with_waits(): void
    {
        $job = new UnsubscribeFromNewsletterJob(self::USER, self::EMAIL);

        $this->assertContains(ShouldQueue::class, class_implements($job));
        $this->assertContains(ShouldBeEncrypted::class, class_implements($job));
        $this->assertSame([30, 120, 600], $job->backoff());
    }

    public function test_it_takes_the_address_off_the_list(): void
    {
        Http::fake([
            $this->memberUrl(self::EMAIL) => Http::sequence()
                ->push(['status' => 'subscribed'], 200)
                ->push(['status' => 'unsubscribed'], 200),
        ]);

        UnsubscribeFromNewsletterJob::dispatchSync(self::USER, self::EMAIL);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && $request['status'] === 'unsubscribed');
    }

    public function test_a_failure_that_will_not_pass_is_logged_with_the_hash_only_and_forgets_the_remembered_status(): void
    {
        $log = Log::spy();
        $key = ProfileNewsletterService::key(self::USER, self::EMAIL);
        Cache::put($key, ProfileNewsletterState::Unsubscribed->value, 300);
        Http::fake([$this->memberUrl(self::EMAIL) => Http::response(['title' => 'API Key Invalid'], 401)]);

        UnsubscribeFromNewsletterJob::dispatchSync(self::USER, self::EMAIL);

        $this->assertNull(Cache::get($key));
        $log->shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => str_contains(json_encode($context, JSON_THROW_ON_ERROR), md5(self::EMAIL)))->atLeast()->once();
        $log->shouldNotHaveReceived('warning', [Mockery::on(fn (string $message): bool => str_contains($message, 'ana@')), Mockery::any()]);
    }

    public function test_the_safety_net_logs_the_hash_and_forgets_the_remembered_status(): void
    {
        $key = ProfileNewsletterService::key(self::USER, self::EMAIL);
        Cache::put($key, ProfileNewsletterState::Unsubscribed->value, 300);

        (new UnsubscribeFromNewsletterJob(self::USER, self::EMAIL))->failed(new RuntimeException('ana@example.com timed out'));

        $this->assertNull(Cache::get($key));
    }

    public function test_an_unsubscription_older_than_a_later_sign_up_does_nothing(): void
    {
        Cache::put(ProfileNewsletterService::joinedAgainKey(self::EMAIL), 2000, 300);

        UnsubscribeFromNewsletterJob::dispatchSync(self::USER, self::EMAIL, 1000);

        Http::assertNothingSent();
    }

    public function test_an_unsubscription_asked_after_the_last_sign_up_still_runs(): void
    {
        Cache::put(ProfileNewsletterService::joinedAgainKey(self::EMAIL), 1000, 300);
        Http::fake([$this->memberUrl(self::EMAIL) => Http::response(['status' => 'subscribed'], 200)]);

        UnsubscribeFromNewsletterJob::dispatchSync(self::USER, self::EMAIL, 2000);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH');
    }

    public function test_the_safety_net_removes_the_failed_job_copy_like_the_subscription_does(): void
    {
        $this->assertContains(
            ForgetsFailedJobCopy::class,
            class_uses(UnsubscribeFromNewsletterJob::class),
        );
        $this->assertContains(
            ForgetsFailedJobCopy::class,
            class_uses(SubscribeToNewsletterJob::class),
        );
    }
}
