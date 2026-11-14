<?php

declare(strict_types=1);

namespace Modules\Newsletter\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Modules\Newsletter\Application\DTOs\NewsletterSettings;
use Modules\Newsletter\Application\Services\NewsletterAvailabilityInterface;
use Modules\Newsletter\Application\Services\NewsletterProviderInterface;
use Modules\Newsletter\Application\Services\NewsletterSettingsRepositoryInterface;
use Modules\Newsletter\Domain\Enums\ConnectionFailure;
use Modules\Newsletter\Domain\Exceptions\NewsletterProviderException;
use Modules\Newsletter\Domain\ValueObjects\ConnectionCheck;
use Modules\Newsletter\Domain\ValueObjects\MailchimpApiKey;
use Modules\Newsletter\Infrastructure\Providers\ConfiguredNewsletterProvider;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;

final class ConfiguredNewsletterProviderTest extends ModuleTestCase
{
    private const string KEY = 'fakemailchimpkeyfakemailchimpkey-us21';

    private const string EMAIL = 'Ana.Example@Example.com';

    protected ?string $moduleName = 'newsletter';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_the_container_gives_the_provider_of_the_saved_settings(): void
    {
        $this->store();
        Http::fake(['https://us21.api.mailchimp.com/3.0/lists/a1b2c3d4e5/members/*' => Http::response(['status' => 'subscribed'], 200)]);

        $provider = $this->app->make(NewsletterProviderInterface::class);

        $this->assertInstanceOf(ConfiguredNewsletterProvider::class, $provider);
        $this->assertSame('subscribed', $provider->status(self::EMAIL)->value);
    }

    public function test_without_a_saved_key_nothing_is_sent(): void
    {
        Http::fake();
        $provider = $this->app->make(NewsletterProviderInterface::class);

        try {
            $provider->subscribe(self::EMAIL);
            $this->fail('A NewsletterProviderException was expected');
        } catch (NewsletterProviderException $e) {
            $this->assertSame(ConnectionFailure::NotConfigured, $e->failure);
        }

        $this->assertSame(ConnectionFailure::NotConfigured, $provider->checkConnection()->failure);
        Http::assertNothingSent();
    }

    public function test_stack_traces_through_the_container_never_show_the_address(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        $this->store();
        Http::fake(['*' => Http::response(['status' => 500], 500)]);

        try {
            $this->app->make(NewsletterProviderInterface::class)->subscribe(self::EMAIL, 'Anabel Secreta');
            $this->fail('A NewsletterProviderException was expected');
        } catch (NewsletterProviderException $e) {
            $trace = (string) $e;

            $this->assertStringContainsString(ConfiguredNewsletterProvider::class, $trace);
            $this->assertStringNotContainsStringIgnoringCase('Ana.Example', $trace);
            $this->assertStringNotContainsString('Anabel', $trace);
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    public function test_settings_that_cannot_be_read_give_a_failed_check_instead_of_an_exception(): void
    {
        $this->mock(NewsletterSettingsRepositoryInterface::class)
            ->shouldReceive('get')
            ->andThrow(new RuntimeException('database down'));

        $result = $this->app->make(NewsletterProviderInterface::class)->checkConnection();

        $this->assertFalse($result->ok);
        $this->assertSame(ConnectionFailure::RequestFailed, $result->failure);
    }

    public function test_the_settings_are_read_once_per_request_and_saving_drops_the_copy(): void
    {
        $this->store();
        $repository = $this->app->make(NewsletterSettingsRepositoryInterface::class);

        $this->assertSame($repository, $this->app->make(NewsletterSettingsRepositoryInterface::class));
        $this->assertSame($repository->get(), $repository->get());

        $repository->save(new NewsletterSettings(listId: 'zz99yy88xx'));

        $this->assertSame('zz99yy88xx', $repository->get()->listId);
        $this->assertSame(
            $this->app->make(NewsletterAvailabilityInterface::class),
            $this->app->make(NewsletterAvailabilityInterface::class),
        );
    }

    private function store(): void
    {
        $this->app->make(NewsletterSettingsRepositoryInterface::class)->save(new NewsletterSettings(
            apiKey: MailchimpApiKey::fromString(self::KEY),
            listId: 'a1b2c3d4e5',
            lastCheck: new ConnectionCheck(new DateTimeImmutable, null),
        ));
    }
}
