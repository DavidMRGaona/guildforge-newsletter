<?php

declare(strict_types=1);

namespace Modules\Newsletter\Tests\Feature\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Newsletter\Application\Services\NewsletterProviderInterface;
use Modules\Newsletter\Application\Services\SubscribeFailureCounterInterface;
use Modules\Newsletter\Domain\Enums\ConnectionFailure;
use Modules\Newsletter\Domain\Exceptions\NewsletterProviderException;
use Modules\Newsletter\Infrastructure\Jobs\SubscribeToNewsletterJob;
use Modules\Newsletter\Tests\Support\ConfiguresNewsletter;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;

final class SubscribeToNewsletterJobTest extends ModuleTestCase
{
    use ConfiguresNewsletter;

    private const string EMAIL = 'Ana.Example@Example.com';

    protected ?string $moduleName = 'newsletter';

    protected bool $autoEnableModule = true;

    /** JobFailed events the worker raised during the test */
    private int $failedJobEvents = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->configureNewsletter();
    }

    public function test_it_is_queued_encrypted_and_retried_with_waits(): void
    {
        $job = new SubscribeToNewsletterJob(self::EMAIL, 'Ana');

        $this->assertContains(ShouldQueue::class, class_implements($job));
        $this->assertContains(ShouldBeEncrypted::class, class_implements($job));
        $this->assertSame(4, $job->tries);
        $this->assertSame([30, 120, 600], $job->backoff());
    }

    public function test_it_never_carries_the_provider_or_the_key(): void
    {
        $serialized = serialize(new SubscribeToNewsletterJob(self::EMAIL, 'Ana'));

        $this->assertStringNotContainsString('fakemailchimpkey', $serialized);
        $this->assertStringNotContainsString('Provider', $serialized);
        $this->assertStringNotContainsString('ApiKey', $serialized);
    }

    public function test_the_queued_payload_never_shows_the_address_or_the_name(): void
    {
        config(['queue.default' => 'database']);

        dispatch(new SubscribeToNewsletterJob(self::EMAIL, 'Anabel Secreta'));

        $payload = (string) DB::table('jobs')->value('payload');

        $this->assertNotSame('', $payload);
        $this->assertStringNotContainsStringIgnoringCase('Ana.Example', $payload);
        $this->assertStringNotContainsString('Anabel', $payload);
    }

    public function test_a_new_address_is_put_in_pending_and_the_failure_count_starts_again(): void
    {
        $this->counter()->recordFailure();
        $this->counter()->recordFailure();
        Http::fake([
            $this->memberUrl(self::EMAIL) => Http::sequence()
                ->push(['status' => 404], 404)
                ->push(['status' => 'pending'], 200),
        ]);

        dispatch_sync(new SubscribeToNewsletterJob(self::EMAIL, 'Ana'));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request['status_if_new'] === 'pending'
            && $request['email_address'] === self::EMAIL
            && $request['merge_fields'] === ['FNAME' => 'Ana']);
        $this->assertSame(0, $this->counter()->current());
    }

    #[DataProvider('answersThatWriteNothing')]
    public function test_an_answer_that_writes_nothing_leaves_the_failure_count_as_it_is(string $status): void
    {
        $this->counter()->recordFailure();
        $this->counter()->recordFailure();
        Http::fake([$this->memberUrl(self::EMAIL) => Http::response(['status' => $status], 200)]);

        dispatch_sync(new SubscribeToNewsletterJob(self::EMAIL));

        // People already on the list joining again must not hide a provider that refuses new ones
        $this->assertSame(2, $this->counter()->current());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function answersThatWriteNothing(): array
    {
        return [
            'already subscribed' => ['subscribed'],
            'waiting for confirmation' => ['pending'],
            'bounced' => ['cleaned'],
        ];
    }

    public function test_an_address_the_provider_rejects_counts_as_a_failure_without_retrying(): void
    {
        Http::fake([
            $this->memberUrl(self::EMAIL) => Http::sequence()
                ->push(['status' => 404], 404)
                ->push(['title' => 'Invalid Resource'], 400),
        ]);
        $job = (new SubscribeToNewsletterJob(self::EMAIL))->withFakeQueueInteractions();

        $job->handle($this->app->make(NewsletterProviderInterface::class), $this->counter());

        $job->assertNotFailed();
        $job->assertNotReleased();
        $this->assertSame(1, $this->counter()->current());
    }

    public function test_an_unreachable_provider_is_left_to_the_retries_without_counting_yet(): void
    {
        Http::fake([$this->memberUrl(self::EMAIL) => Http::response(['status' => 503], 503)]);
        $job = (new SubscribeToNewsletterJob(self::EMAIL))->withFakeQueueInteractions();

        try {
            $job->handle($this->app->make(NewsletterProviderInterface::class), $this->counter());
            $this->fail('The exception should reach the worker, which tries again later');
        } catch (NewsletterProviderException $e) {
            $this->assertSame(ConnectionFailure::Unreachable, $e->failure);
        }

        $job->assertNotFailed();
        $this->assertSame(0, $this->counter()->current());
    }

    #[DataProvider('failuresRetriesCannotFix')]
    public function test_a_failure_retries_cannot_fix_gives_up_at_once_without_failing_the_job(int $status): void
    {
        $log = Log::spy();
        Http::fake([$this->memberUrl(self::EMAIL) => Http::response(['status' => $status], $status)]);
        $job = (new SubscribeToNewsletterJob(self::EMAIL))->withFakeQueueInteractions();

        $job->handle($this->app->make(NewsletterProviderInterface::class), $this->counter());

        $job->assertNotFailed();
        $job->assertNotReleased();
        $this->assertSame(1, $this->counter()->current());
        $log->shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'could not be sent to the provider')
            && ($context['failure'] ?? null) === 'invalid_api_key'
            && ($context['subscriber_hash'] ?? null) === md5(mb_strtolower(self::EMAIL)))->once();
    }

    /**
     * @return array<string, array{int}>
     */
    public static function failuresRetriesCannotFix(): array
    {
        return [
            'key refused' => [401],
            'account disabled' => [403],
        ];
    }

    public function test_a_job_that_fails_logs_only_the_hash_and_counts_one_failure(): void
    {
        $log = Log::spy();
        $job = new SubscribeToNewsletterJob(self::EMAIL, 'Anabel Secreta');

        $job->failed(NewsletterProviderException::because(ConnectionFailure::Unreachable, 'subscribe', 503));

        $this->assertSame(1, $this->counter()->current());
        $log->shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            $logged = $message.json_encode($context);

            return $context['subscriber_hash'] === md5(mb_strtolower(self::EMAIL))
                && $context['failure'] === 'unreachable'
                && ! str_contains(mb_strtolower($logged), 'ana.example')
                && ! str_contains($logged, 'Anabel');
        });
    }

    public function test_an_unexpected_error_is_logged_by_its_class_only(): void
    {
        $log = Log::spy();
        $job = new SubscribeToNewsletterJob(self::EMAIL);

        $job->failed(new RuntimeException('details of '.self::EMAIL));

        $log->shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['failure'] === RuntimeException::class
            && ! str_contains(mb_strtolower(json_encode($context) ?: ''), 'ana.example'));
    }

    public function test_after_the_last_retry_the_job_gives_up_without_the_worker_seeing_a_failure(): void
    {
        config(['queue.default' => 'database']);
        Log::spy();
        $this->listenForFailedJobs();
        Http::fake([$this->memberUrl(self::EMAIL) => Http::response(['status' => 503], 503)]);

        dispatch(new SubscribeToNewsletterJob(self::EMAIL, 'Anabel Secreta'));

        // Four attempts, waiting out each backoff in between
        foreach ([0, 31, 121, 601] as $wait) {
            $this->travel($wait)->seconds();
            $this->work();
        }

        $this->assertSame(0, $this->failedJobEvents);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, $this->counter()->current());
    }

    public function test_a_refused_key_gives_up_on_the_first_attempt_without_the_worker_seeing_a_failure(): void
    {
        config(['queue.default' => 'database']);
        Log::spy();
        $this->listenForFailedJobs();
        Http::fake([$this->memberUrl(self::EMAIL) => Http::response(['status' => 401], 401)]);

        dispatch(new SubscribeToNewsletterJob(self::EMAIL));
        $this->work();

        $this->assertSame(0, $this->failedJobEvents);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, $this->counter()->current());
        Http::assertSentCount(1);
    }

    public function test_a_job_the_worker_fails_without_running_it_leaves_no_copy_either(): void
    {
        config(['queue.default' => 'database']);
        Log::spy();
        Http::fake();

        // As if a worker had been killed in the middle of every attempt: the next one is over the limit
        dispatch(new SubscribeToNewsletterJob(self::EMAIL, 'Anabel Secreta'));
        DB::table('jobs')->update(['attempts' => 4]);
        $this->work();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, $this->counter()->current());
    }

    private function work(): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
    }

    private function listenForFailedJobs(): void
    {
        Event::listen(JobFailed::class, function (): void {
            $this->failedJobEvents++;
        });
    }

    private function counter(): SubscribeFailureCounterInterface
    {
        return $this->app->make(SubscribeFailureCounterInterface::class);
    }
}
