<?php

declare(strict_types=1);

namespace Modules\Newsletter\Tests\Integration\Infrastructure;

use App\Application\Impersonation\Services\ImpersonationContextInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Modules\Newsletter\Application\Services\NewsletterProviderFactoryInterface;
use Modules\Newsletter\Application\Services\NewsletterProviderInterface;
use Modules\Newsletter\Domain\Enums\ConnectionFailure;
use Modules\Newsletter\Domain\Enums\SubscribeOutcome;
use Modules\Newsletter\Domain\Enums\SubscriptionStatus;
use Modules\Newsletter\Domain\Exceptions\NewsletterProviderException;
use Modules\Newsletter\Domain\ValueObjects\MailchimpApiKey;
use Modules\Newsletter\Infrastructure\Providers\Mailchimp\MailchimpProvider;
use Modules\Newsletter\NewsletterServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MailchimpProviderTest extends TestCase
{
    private const string KEY = 'fakemailchimpkeyfakemailchimpkey-us21';

    private const string LIST = 'a1b2c3d4e5';

    private const string EMAIL = 'Ana.Example@Example.com';

    private const string BASE = 'https://us21.api.mailchimp.com/3.0';

    /** @var list<string> */
    private array $logged = [];

    private bool $impersonating = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(NewsletterServiceProvider::class);
        Http::preventStrayRequests();

        $this->mock(ImpersonationContextInterface::class)
            ->shouldReceive('isActive')
            ->andReturnUsing(fn (): bool => $this->impersonating);

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->message.' '.json_encode($event->context, JSON_THROW_ON_ERROR);
        });
    }

    public function test_a_new_address_is_added_as_pending_so_mailchimp_asks_for_confirmation(): void
    {
        Http::fake([
            $this->memberUrl() => Http::sequence()
                ->push(['status' => 404, 'title' => 'Resource Not Found'], 404)
                ->push(['status' => 'pending'], 200),
        ]);

        $outcome = $this->provider()->subscribe(self::EMAIL, ' Ana ');

        $this->assertSame(SubscribeOutcome::ConfirmationRequested, $outcome);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === $this->memberUrl()
            && $request['email_address'] === self::EMAIL
            && $request['status_if_new'] === 'pending'
            // Never "status" for a new address: a member created meanwhile would be put back to pending
            && ! isset($request['status'])
            && $request['merge_fields'] === ['FNAME' => 'Ana']);
    }

    public function test_without_a_name_no_merge_field_is_sent(): void
    {
        Http::fake([
            $this->memberUrl() => Http::sequence()
                ->push(['status' => 404], 404)
                ->push(['status' => 'pending'], 200),
        ]);

        $this->provider()->subscribe(self::EMAIL, '  ');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && ! isset($request['merge_fields']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reactivableStatuses(): iterable
    {
        yield 'unsubscribed' => ['unsubscribed'];
        yield 'archived' => ['archived'];
        yield 'transactional' => ['transactional'];
    }

    #[DataProvider('reactivableStatuses')]
    public function test_a_former_member_goes_back_to_pending_and_gets_the_confirmation_again(string $previous): void
    {
        Http::fake([
            $this->memberUrl() => Http::sequence()
                ->push(['status' => $previous], 200)
                ->push(['status' => 'pending'], 200),
        ]);

        $outcome = $this->provider()->subscribe(self::EMAIL);

        $this->assertSame(SubscribeOutcome::ConfirmationRequested, $outcome);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request['status'] === 'pending'
            && $request['status_if_new'] === 'pending');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function activeStatuses(): iterable
    {
        yield 'subscribed' => ['subscribed'];
        yield 'pending' => ['pending'];
    }

    #[DataProvider('activeStatuses')]
    public function test_a_subscribed_or_pending_address_is_left_alone(string $status): void
    {
        Http::fake([$this->memberUrl() => Http::response(['status' => $status], 200)]);

        $outcome = $this->provider()->subscribe(self::EMAIL, 'Ana');

        $this->assertSame(SubscribeOutcome::AlreadyActive, $outcome);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_a_cleaned_address_cannot_be_reactivated_and_only_its_hash_is_logged(): void
    {
        Http::fake([$this->memberUrl() => Http::response(['status' => 'cleaned', 'email_address' => self::EMAIL], 200)]);

        $outcome = $this->provider()->subscribe(self::EMAIL, 'Ana');

        $this->assertSame(SubscribeOutcome::NotReactivable, $outcome);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
        $this->assertLoggedOnlyTheHash();
    }

    public function test_an_address_mailchimp_rejects_is_reported_without_the_address(): void
    {
        Http::fake([
            $this->memberUrl() => Http::sequence()
                ->push(['status' => 404], 404)
                ->push([
                    'status' => 400,
                    'title' => 'Forgotten Email Not Subscribed',
                    'detail' => self::EMAIL.' was permanently deleted and cannot be re-imported.',
                ], 400),
        ]);

        $outcome = $this->provider()->subscribe(self::EMAIL);

        $this->assertSame(SubscribeOutcome::Rejected, $outcome);
        $this->assertLoggedOnlyTheHash();
        $this->assertStringContainsString('Forgotten Email Not Subscribed', implode("\n", $this->logged));
    }

    public function test_an_unreachable_server_is_a_typed_error(): void
    {
        Http::fake([$this->memberUrl() => Http::failedConnection()]);

        $this->assertFailure(ConnectionFailure::Unreachable, fn () => $this->provider()->subscribe(self::EMAIL));
    }

    /**
     * @return iterable<string, array{int, ConnectionFailure}>
     */
    public static function errorAnswers(): iterable
    {
        yield 'server error' => [500, ConnectionFailure::Unreachable];
        yield 'unavailable' => [503, ConnectionFailure::Unreachable];
        yield 'too many requests' => [429, ConnectionFailure::Unreachable];
        yield 'invalid key' => [401, ConnectionFailure::InvalidApiKey];
        yield 'forbidden' => [403, ConnectionFailure::InvalidApiKey];
        yield 'unexpected' => [405, ConnectionFailure::RequestFailed];
    }

    #[DataProvider('errorAnswers')]
    public function test_error_answers_are_typed(int $status, ConnectionFailure $failure): void
    {
        Http::fake([$this->memberUrl() => Http::response(['status' => $status, 'detail' => self::EMAIL], $status)]);

        $this->assertFailure($failure, fn () => $this->provider()->subscribe(self::EMAIL));
        $this->assertLoggedOnlyTheHash();
    }

    public function test_a_missing_list_is_reported_when_adding(): void
    {
        Http::fake([$this->memberUrl() => Http::response(['status' => 404], 404)]);

        $this->assertFailure(ConnectionFailure::ListNotFound, fn () => $this->provider()->subscribe(self::EMAIL));
    }

    public function test_every_request_is_authenticated_with_the_key_on_its_own_server_and_short_timeouts(): void
    {
        Http::fake([$this->memberUrl() => Http::response(['status' => 'subscribed'], 200)]);

        $this->provider()->status(self::EMAIL);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), self::BASE.'/')
            && $request->header('Authorization') === ['Basic '.base64_encode('guildforge:'.self::KEY)]);
    }

    public function test_every_request_waits_only_a_few_seconds(): void
    {
        $sent = [];
        Http::fake(function (Request $request, array $options) use (&$sent) {
            $sent[] = $options;

            return Http::response(['status' => 'subscribed'], 200);
        });

        $this->provider()->status(self::EMAIL);
        $this->provider()->checkConnection();

        $this->assertCount(2, $sent);

        foreach ($sent as $options) {
            $this->assertSame(MailchimpProvider::TIMEOUT_SECONDS, $options['timeout']);
            $this->assertSame(MailchimpProvider::CONNECT_TIMEOUT_SECONDS, $options['connect_timeout']);
        }

        $this->assertLessThanOrEqual(5, MailchimpProvider::TIMEOUT_SECONDS);
        $this->assertLessThanOrEqual(3, MailchimpProvider::CONNECT_TIMEOUT_SECONDS);
    }

    /**
     * Production keeps zend.exception_ignore_args off, so stack traces print up to 15 characters
     * of every argument: the address and the key must never be one of them.
     *
     * @return iterable<string, array{string}>
     */
    public static function failingOperations(): iterable
    {
        yield 'subscribe' => ['subscribe'];
        yield 'status' => ['status'];
        yield 'unsubscribe' => ['unsubscribe'];
    }

    #[DataProvider('failingOperations')]
    public function test_stack_traces_never_show_the_address(string $operation): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            foreach ([Http::response(['status' => 500], 500), Http::failedConnection()] as $answer) {
                Http::fake([$this->memberUrl() => $answer]);

                try {
                    $provider = $this->provider();
                    $operation === 'subscribe'
                        ? $provider->subscribe(self::EMAIL, 'Anabel Secreta')
                        : $provider->{$operation}(self::EMAIL);
                    $this->fail('A NewsletterProviderException was expected');
                } catch (NewsletterProviderException $e) {
                    $trace = (string) $e;

                    $this->assertStringContainsString('SensitiveParameterValue', $trace);
                    $this->assertStringNotContainsStringIgnoringCase('Ana.Example', $trace);
                    $this->assertStringNotContainsString('Anabel', $trace);
                    $this->assertStringNotContainsString('fakemailchimp', $trace);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    public function test_an_unexpected_error_during_the_check_is_a_failed_check_logged_by_class(): void
    {
        Http::fake(fn () => throw new \LogicException('details of '.self::EMAIL));

        $result = $this->provider()->checkConnection();

        $this->assertFalse($result->ok);
        $this->assertSame(ConnectionFailure::RequestFailed, $result->failure);
        $log = implode("\n", $this->logged);
        $this->assertStringContainsString('LogicException', $log);
        $this->assertStringNotContainsStringIgnoringCase(self::EMAIL, $log);
    }

    /**
     * @return iterable<string, array{int, array<string, string>, SubscriptionStatus}>
     */
    public static function memberStatuses(): iterable
    {
        yield 'subscribed' => [200, ['status' => 'subscribed'], SubscriptionStatus::Subscribed];
        yield 'pending' => [200, ['status' => 'pending'], SubscriptionStatus::Pending];
        yield 'unsubscribed' => [200, ['status' => 'unsubscribed'], SubscriptionStatus::Unsubscribed];
        yield 'cleaned (bounced, cannot join again)' => [200, ['status' => 'cleaned'], SubscriptionStatus::Blocked];
        yield 'archived' => [200, ['status' => 'archived'], SubscriptionStatus::Unsubscribed];
        yield 'transactional' => [200, ['status' => 'transactional'], SubscriptionStatus::NotFound];
        yield 'unknown' => [404, ['title' => 'Resource Not Found'], SubscriptionStatus::NotFound];
    }

    /**
     * @param  array<string, string>  $body
     */
    #[DataProvider('memberStatuses')]
    public function test_the_status_of_an_address(int $code, array $body, SubscriptionStatus $expected): void
    {
        Http::fake([$this->memberUrl().'*' => Http::response($body, $code)]);

        $this->assertSame($expected, $this->provider()->status(self::EMAIL));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
    }

    public function test_unsubscribing_a_member_marks_it_unsubscribed(): void
    {
        Http::fake([
            $this->memberUrl().'*' => Http::sequence()
                ->push(['status' => 'subscribed'], 200)
                ->push(['status' => 'unsubscribed'], 200),
        ]);

        $this->provider()->unsubscribe(self::EMAIL);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === $this->memberUrl()
            && $request['status'] === 'unsubscribed');
    }

    /**
     * @return iterable<string, array{int, array<string, string>}>
     */
    public static function nothingToUnsubscribe(): iterable
    {
        yield 'not a member' => [404, []];
        yield 'already unsubscribed' => [200, ['status' => 'unsubscribed']];
        yield 'cleaned' => [200, ['status' => 'cleaned']];
    }

    /**
     * @param  array<string, string>  $body
     */
    #[DataProvider('nothingToUnsubscribe')]
    public function test_unsubscribing_someone_who_is_not_on_the_list_changes_nothing(int $code, array $body): void
    {
        Http::fake([$this->memberUrl().'*' => Http::response($body, $code)]);

        $this->provider()->unsubscribe(self::EMAIL);

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
    }

    public function test_the_connection_check_lists_the_audiences_and_finds_the_chosen_one(): void
    {
        Http::fake([self::BASE.'/lists*' => Http::response(['lists' => [
            ['id' => 'zz99yy88xx', 'name' => 'Otra'],
            ['id' => self::LIST, 'name' => 'Socios'],
        ]], 200)]);

        $result = $this->provider()->checkConnection();

        $this->assertTrue($result->ok);
        $this->assertNull($result->failure);
        $this->assertSame(['zz99yy88xx' => 'Otra', self::LIST => 'Socios'], $result->lists);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), self::BASE.'/lists?'));
    }

    public function test_the_check_fails_when_the_chosen_list_is_not_in_the_account(): void
    {
        Http::fake([self::BASE.'/lists*' => Http::response(['lists' => [['id' => 'zz99yy88xx', 'name' => 'Otra']]], 200)]);

        $result = $this->provider()->checkConnection();

        $this->assertFalse($result->ok);
        $this->assertSame(ConnectionFailure::ListNotFound, $result->failure);
        $this->assertSame(['zz99yy88xx' => 'Otra'], $result->lists);
    }

    public function test_the_check_without_a_list_still_returns_the_lists_to_choose_from(): void
    {
        Http::fake([self::BASE.'/lists*' => Http::response(['lists' => [['id' => self::LIST, 'name' => 'Socios']]], 200)]);

        $result = $this->provider(listId: null)->checkConnection();

        $this->assertFalse($result->ok);
        $this->assertSame(ConnectionFailure::ListNotFound, $result->failure);
        $this->assertSame([self::LIST => 'Socios'], $result->lists);
    }

    public function test_the_check_reports_an_invalid_key_and_never_throws(): void
    {
        Http::fake([self::BASE.'/lists*' => Http::response(['title' => 'API Key Invalid'], 401)]);

        $result = $this->provider()->checkConnection();

        $this->assertFalse($result->ok);
        $this->assertSame(ConnectionFailure::InvalidApiKey, $result->failure);
        $this->assertSame([], $result->lists);
    }

    public function test_the_check_reports_a_network_error(): void
    {
        Http::fake([self::BASE.'/lists*' => Http::failedConnection()]);

        $this->assertSame(ConnectionFailure::Unreachable, $this->provider()->checkConnection()->failure);
    }

    public function test_the_check_ignores_malformed_lists(): void
    {
        Http::fake([self::BASE.'/lists*' => Http::response(['lists' => [
            ['id' => self::LIST, 'name' => 'Socios'],
            ['id' => '../x', 'name' => 'Rara'],
            ['name' => 'Sin id'],
            'nada',
        ]], 200)]);

        $this->assertSame([self::LIST => 'Socios'], $this->provider()->checkConnection()->lists);
    }

    public function test_nothing_reaches_mailchimp_during_an_impersonation(): void
    {
        Http::fake();
        $this->impersonating = true;
        $provider = $this->provider();

        $this->assertSame(ConnectionFailure::Impersonation, $provider->checkConnection()->failure);
        $this->assertFailure(ConnectionFailure::Impersonation, fn () => $provider->subscribe(self::EMAIL));
        $this->assertFailure(ConnectionFailure::Impersonation, fn () => $provider->status(self::EMAIL));
        $this->assertFailure(ConnectionFailure::Impersonation, fn () => $provider->unsubscribe(self::EMAIL));

        Http::assertNothingSent();
    }

    public function test_without_a_list_nothing_can_be_subscribed(): void
    {
        Http::fake();

        $this->assertFailure(ConnectionFailure::NotConfigured, fn () => $this->provider(listId: null)->subscribe(self::EMAIL));
        Http::assertNothingSent();
    }

    private function provider(?string $listId = self::LIST): NewsletterProviderInterface
    {
        return $this->app->make(NewsletterProviderFactoryInterface::class)
            ->mailchimp(MailchimpApiKey::fromString(self::KEY), $listId);
    }

    private function memberUrl(): string
    {
        return self::BASE.'/lists/'.self::LIST.'/members/'.md5(strtolower(self::EMAIL));
    }

    private function assertFailure(ConnectionFailure $expected, callable $call): void
    {
        try {
            $call();
            $this->fail('A NewsletterProviderException was expected');
        } catch (NewsletterProviderException $e) {
            $this->assertSame($expected, $e->failure);
            $this->assertStringNotContainsStringIgnoringCase(self::EMAIL, $e->getMessage());
        }
    }

    private function assertLoggedOnlyTheHash(): void
    {
        $log = implode("\n", $this->logged);

        $this->assertNotSame('', $log, 'Something should have been logged');
        $this->assertStringContainsString(md5(strtolower(self::EMAIL)), $log);
        $this->assertStringNotContainsStringIgnoringCase(self::EMAIL, $log);
        $this->assertStringNotContainsString('fakemailchimpkey', $log);
    }
}
