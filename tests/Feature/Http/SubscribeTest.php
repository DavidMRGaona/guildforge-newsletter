<?php

declare(strict_types=1);

namespace Modules\Newsletter\Tests\Feature\Http;

use App\Application\Impersonation\Services\ImpersonationContextInterface;
use App\Application\Services\SettingsServiceInterface;
use App\Domain\Impersonation\Enums\ImpersonationMode;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Newsletter\Infrastructure\Jobs\SubscribeToNewsletterJob;
use Modules\Newsletter\Tests\Support\ConfiguresNewsletter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Modules\ModuleTestCase;

final class SubscribeTest extends ModuleTestCase
{
    use ConfiguresNewsletter;

    private const string CHECK_YOUR_EMAIL = 'Revisa tu correo: te hemos enviado un enlace para confirmar la suscripción.';

    protected ?string $moduleName = 'newsletter';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Queue::fake();
        $this->configureNewsletter();
    }

    public function test_the_page_shows_the_form(): void
    {
        // Module pages live outside the core's page folder, so Inertia cannot check the file
        $this->get('/newsletter')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Newsletter/Subscribe', false)
                ->where('form.action', '/newsletter')
                ->where('form.consentText', 'Quiero recibir el boletín de la asociación.')
                ->where('form.privacyUrl', '/privacidad')
                ->where('form.showName', true)
                ->where('form.email', null)
                ->where('seo.title', fn (string $title): bool => str_contains($title, 'Boletín')));
        Http::assertNothingSent();
    }

    public function test_the_page_fills_in_the_address_of_whoever_is_signed_in(): void
    {
        $user = UserModel::factory()->create(['name' => 'Ana Ejemplo', 'email' => 'ana@example.com']);

        $this->actingAs($user)->get('/newsletter')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('form.email', 'ana@example.com')
                ->where('form.name', 'Ana Ejemplo'));
    }

    public function test_the_page_does_not_exist_while_the_newsletter_is_not_available(): void
    {
        $this->configureNewsletter(lastCheckOk: false);

        $this->get('/newsletter')->assertNotFound();
    }

    public function test_the_page_does_not_exist_when_the_module_is_disabled(): void
    {
        $this->disableModule('newsletter');

        $this->get('/newsletter')->assertNotFound();
    }

    public function test_asking_to_join_queues_the_subscription_and_says_to_check_the_email(): void
    {
        $this->postJson('/newsletter', $this->form(['name' => '  Ana  ']))
            ->assertOk()
            ->assertExactJson(['message' => self::CHECK_YOUR_EMAIL]);

        Queue::assertPushed(SubscribeToNewsletterJob::class, fn (SubscribeToNewsletterJob $job): bool => $job->email === 'ana@example.com' && $job->name === 'Ana');
        Http::assertNothingSent();
    }

    public function test_an_empty_name_is_sent_as_no_name(): void
    {
        $this->postJson('/newsletter', $this->form(['name' => '']))->assertOk();

        Queue::assertPushed(SubscribeToNewsletterJob::class, fn (SubscribeToNewsletterJob $job): bool => $job->name === null);
    }

    public function test_a_form_sent_without_javascript_goes_back_with_the_message(): void
    {
        $this->from('/eventos')
            ->post('/newsletter', $this->form())
            ->assertRedirect('/eventos')
            ->assertSessionHas('success', self::CHECK_YOUR_EMAIL);

        Queue::assertPushed(SubscribeToNewsletterJob::class);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    #[DataProvider('invalidForms')]
    public function test_an_invalid_form_is_refused_with_the_error_on_its_field(array $changes, string $field): void
    {
        $this->postJson('/newsletter', $this->form($changes))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        Queue::assertNothingPushed();
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidForms(): array
    {
        return [
            'no address' => [['email' => ''], 'email'],
            'not an address' => [['email' => 'ana-at-example'], 'email'],
            'address too long' => [['email' => str_repeat('a', 250).'@example.com'], 'email'],
            'name too long' => [['name' => str_repeat('a', 101)], 'name'],
            'consent missing' => [['consent' => false], 'consent'],
        ];
    }

    public function test_the_consent_error_is_in_spanish(): void
    {
        $this->postJson('/newsletter', $this->form(['consent' => false]))
            ->assertJsonValidationErrors(['consent' => 'Marca la casilla para aceptar recibir el boletín.']);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    #[DataProvider('trappedForms')]
    public function test_anything_in_the_trap_field_gets_the_same_answer_and_nothing_is_queued(array $changes): void
    {
        $this->postJson('/newsletter', $this->form($changes))
            ->assertOk()
            ->assertExactJson(['message' => self::CHECK_YOUR_EMAIL]);

        Queue::assertNothingPushed();
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function trappedForms(): array
    {
        return [
            'a link' => [['website' => 'https://spam.example']],
            'a long text' => [['website' => str_repeat('a', 5000)]],
            'a list' => [['website' => ['spam', 'more spam']]],
            'a number' => [['website' => 0]],
            'with the rest invalid too' => [['website' => 'x', 'email' => 'not-an-address', 'consent' => false]],
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    #[DataProvider('anyForm')]
    public function test_while_the_newsletter_is_not_available_every_request_gets_the_same_404(array $changes): void
    {
        $this->configureNewsletter(lastCheckOk: false);

        $this->postJson('/newsletter', $this->form($changes))->assertNotFound();

        Queue::assertNothingPushed();
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function anyForm(): array
    {
        return [
            'valid' => [[]],
            'invalid' => [['email' => '', 'consent' => false]],
            'trapped' => [['website' => 'https://spam.example']],
        ];
    }

    public function test_the_limit_never_answers_before_the_404(): void
    {
        $this->configureNewsletter(lastCheckOk: false);

        foreach (range(1, 7) as $attempt) {
            $this->postJson('/newsletter', $this->form())->assertNotFound();
        }
    }

    public function test_nothing_is_queued_during_an_impersonation_and_the_answer_is_the_same(): void
    {
        $target = UserModel::factory()->create();
        $this->app->instance(ImpersonationContextInterface::class, $this->impersonating($target));

        $this->actingAs($target)
            ->postJson('/newsletter', $this->form())
            ->assertOk()
            ->assertExactJson(['message' => self::CHECK_YOUR_EMAIL]);

        Queue::assertNothingPushed();
    }

    public function test_someone_who_must_verify_their_email_first_is_stopped(): void
    {
        $this->app->make(SettingsServiceInterface::class)->set('auth_email_verification_required', '1');
        $user = UserModel::factory()->unverified()->create();

        $this->actingAs($user)
            ->postJson('/newsletter', $this->form())
            ->assertForbidden()
            ->assertJsonPath('error', 'email_verification_required');

        Queue::assertNothingPushed();
    }

    public function test_guests_are_not_asked_to_verify_anything(): void
    {
        $this->app->make(SettingsServiceInterface::class)->set('auth_email_verification_required', '1');

        $this->postJson('/newsletter', $this->form())->assertOk();
    }

    public function test_too_many_requests_from_one_address_are_refused_with_a_message(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/newsletter', $this->form(['email' => "ana{$attempt}@example.com"]))->assertOk();
        }

        $this->postJson('/newsletter', $this->form(['email' => 'ana6@example.com']))
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Has hecho demasiados intentos. Espera unos minutos y vuelve a probar.');

        Queue::assertPushed(SubscribeToNewsletterJob::class, 5);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        return [...['email' => 'ana@example.com', 'name' => 'Ana', 'consent' => true, 'website' => ''], ...$changes];
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
