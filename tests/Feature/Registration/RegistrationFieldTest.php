<?php

declare(strict_types=1);

namespace Modules\Newsletter\Tests\Feature\Registration;

use App\Application\Registration\Services\RegistrationFieldRegistryInterface;
use App\Domain\Events\RegistrationFieldsSubmitted;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Newsletter\Application\Services\NewsletterAvailabilityInterface;
use Modules\Newsletter\Application\Services\SubscriptionRequesterInterface;
use Modules\Newsletter\Infrastructure\Jobs\SubscribeToNewsletterJob;
use Modules\Newsletter\Infrastructure\Registration\SubscribeAfterRegistration;
use Modules\Newsletter\Infrastructure\Registration\SubscribeOnRegistrationField;
use Modules\Newsletter\NewsletterServiceProvider;
use Modules\Newsletter\Tests\Support\ConfiguresNewsletter;
use Tests\Support\Modules\ModuleTestCase;

final class RegistrationFieldTest extends ModuleTestCase
{
    use ConfiguresNewsletter;

    private const string KEY = 'newsletter.subscribe';

    protected ?string $moduleName = 'newsletter';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Queue::fake();
        $this->configureNewsletter();
    }

    public function test_the_provider_offers_the_field(): void
    {
        $provider = new NewsletterServiceProvider($this->app);

        $this->assertSame([SubscribeOnRegistrationField::class], $provider->registerRegistrationFields());
    }

    public function test_the_field_is_an_unticked_checkbox_with_its_texts(): void
    {
        $field = $this->app->make(SubscribeOnRegistrationField::class);

        $this->assertSame(self::KEY, $field->key());
        $this->assertSame('Quiero recibir el boletín', $field->label());
        $this->assertNotEmpty($field->description());
        $this->assertSame([], $field->rules());
    }

    public function test_the_field_is_available_only_while_the_newsletter_is_available(): void
    {
        $this->assertTrue($this->app->make(SubscribeOnRegistrationField::class)->isAvailable());

        $this->configureNewsletter(lastCheckOk: false);
        $this->app->forgetInstance(NewsletterAvailabilityInterface::class);

        $this->assertFalse($this->app->make(SubscribeOnRegistrationField::class)->isAvailable());
    }

    public function test_the_registration_page_draws_the_field_only_while_the_newsletter_is_available(): void
    {
        $this->get('/registro')->assertOk()->assertInertia(fn ($page) => $page
            ->where('registrationFields.0.key', self::KEY));

        $this->configureNewsletter(lastCheckOk: false);

        $this->get('/registro')->assertOk()->assertInertia(fn ($page) => $page
            ->where('registrationFields', []));
    }

    public function test_ticking_the_box_dispatches_the_event_with_the_choice(): void
    {
        $this->app->make(RegistrationFieldRegistryInterface::class)->register(SubscribeOnRegistrationField::class, 'newsletter');
        Event::fake([RegistrationFieldsSubmitted::class]);

        $this->post('/registro', $this->form(true))->assertRedirect();

        Event::assertDispatched(RegistrationFieldsSubmitted::class, fn (RegistrationFieldsSubmitted $event): bool => $event->ticked(self::KEY));
    }

    public function test_the_listener_is_queued_and_waits_for_the_commit(): void
    {
        $listener = $this->app->make(SubscribeAfterRegistration::class);

        $this->assertContains(ShouldQueue::class, class_implements($listener));
        $this->assertTrue($listener->afterCommit);
    }

    public function test_the_listener_is_registered_for_the_event(): void
    {
        $this->assertNotEmpty($this->app['events']->getListeners(RegistrationFieldsSubmitted::class));
    }

    public function test_a_ticked_box_asks_for_the_subscription_with_the_email_of_the_new_account(): void
    {
        $user = UserModel::factory()->create(['email' => 'ana@example.com', 'name' => 'Ana Ejemplo']);

        $this->app->make(SubscribeAfterRegistration::class)->handle(new RegistrationFieldsSubmitted($user->id, [self::KEY => true]));

        Queue::assertPushed(SubscribeToNewsletterJob::class, fn (SubscribeToNewsletterJob $job): bool => $job->email === 'ana@example.com' && $job->name === 'Ana Ejemplo');
    }

    public function test_an_unticked_box_asks_for_nothing(): void
    {
        $user = UserModel::factory()->create();

        $this->app->make(SubscribeAfterRegistration::class)->handle(new RegistrationFieldsSubmitted($user->id, [self::KEY => false]));
        $this->app->make(SubscribeAfterRegistration::class)->handle(new RegistrationFieldsSubmitted($user->id, []));

        Queue::assertNothingPushed();
    }

    public function test_nothing_is_asked_when_the_newsletter_stopped_being_available_in_the_meantime(): void
    {
        $user = UserModel::factory()->create();
        $this->configureNewsletter(lastCheckOk: false);
        $this->app->forgetInstance(NewsletterAvailabilityInterface::class);

        $this->app->make(SubscribeAfterRegistration::class)->handle(new RegistrationFieldsSubmitted($user->id, [self::KEY => true]));

        Queue::assertNothingPushed();
    }

    public function test_nothing_is_asked_for_an_account_that_no_longer_exists(): void
    {
        $this->app->make(SubscribeAfterRegistration::class)->handle(new RegistrationFieldsSubmitted('00000000-0000-7000-8000-000000000000', [self::KEY => true]));

        Queue::assertNothingPushed();
    }

    public function test_the_listener_uses_the_requester_that_skips_impersonations(): void
    {
        $requester = $this->createMock(SubscriptionRequesterInterface::class);
        $requester->expects($this->once())->method('request');
        $this->app->instance(SubscriptionRequesterInterface::class, $requester);
        $user = UserModel::factory()->create();

        $this->app->make(SubscribeAfterRegistration::class)->handle(new RegistrationFieldsSubmitted($user->id, [self::KEY => true]));
    }

    /**
     * @return array<string, mixed>
     */
    private function form(bool $ticked): array
    {
        return [
            'name' => 'Persona de ejemplo',
            'email' => 'persona@example.com',
            'password' => 'secret-password-123',
            'password_confirmation' => 'secret-password-123',
            'registration_fields' => [self::KEY => $ticked],
        ];
    }
}
