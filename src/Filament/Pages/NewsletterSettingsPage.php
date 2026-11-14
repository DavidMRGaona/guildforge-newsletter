<?php

declare(strict_types=1);

namespace Modules\Newsletter\Filament\Pages;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Application\Services\TimezoneServiceInterface;
use App\Filament\Clusters\SettingsCluster;
use Closure;
use DateTimeImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Modules\Newsletter\Application\DTOs\ConnectionCheckResultDTO;
use Modules\Newsletter\Application\DTOs\NewsletterSettings;
use Modules\Newsletter\Application\Services\NewsletterProviderFactoryInterface;
use Modules\Newsletter\Application\Services\NewsletterSettingsRepositoryInterface;
use Modules\Newsletter\Domain\Enums\ConnectionFailure;
use Modules\Newsletter\Domain\Enums\NewsletterProviderName;
use Modules\Newsletter\Domain\ValueObjects\ConnectionCheck;
use Modules\Newsletter\Domain\ValueObjects\MailchimpApiKey;

/**
 * «Sistema → Configuración → Boletín»: the Mailchimp connection and the consent text.
 *
 * The saved API key is never sent back to the browser: the field starts empty and leaving it
 * empty keeps the saved one. Saving always checks the connection and stores the answer; the
 * newsletter is only offered while that check went well (NewsletterAvailabilityInterface).
 *
 * @property Schema $form
 */
final class NewsletterSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    public const string PERMISSION = 'newsletter:newsletter.configure';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope-open';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = 115;

    protected static ?string $slug = 'boletin';

    protected string $view = 'filament.pages.simple-settings';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /**
     * The lists the account offered on the last «Probar conexión» (plus the saved one). Locked:
     * the list select only accepts these.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $lists = [];

    #[Locked]
    public bool $hasStoredKey = false;

    public static function getNavigationLabel(): string
    {
        return __('newsletter::messages.settings.navigation');
    }

    public function getTitle(): string
    {
        return __('newsletter::messages.settings.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && app(AuthorizationServiceInterface::class)->can($user, self::PERMISSION);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);

        $settings = $this->repository()->get();

        $this->hasStoredKey = $settings->apiKey instanceof MailchimpApiKey;
        $this->lists = $settings->listId === null ? [] : [$settings->listId => $settings->listName ?? $settings->listId];

        $this->form->fill([
            'provider' => $settings->provider->value,
            'api_key' => null,
            'server' => $settings->apiKey?->server(),
            'list_id' => $settings->listId,
            'consent_text' => $settings->consentText ?? __('newsletter::messages.settings.consent_default'),
            'privacy_url' => $settings->privacyUrl,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make(fn (): string => $this->lastCheckHeading())
                    ->description(fn (): ?string => $this->lastCheckDescription())
                    ->status(fn (): string => match ($this->repository()->get()->lastCheck?->ok()) {
                        true => 'success',
                        false => 'danger',
                        null => 'warning',
                    }),

                Section::make(__('newsletter::messages.settings.connection'))
                    ->description(__('newsletter::messages.settings.connection_description'))
                    ->schema([
                        Select::make('provider')
                            ->label(__('newsletter::messages.settings.fields.provider'))
                            ->options([NewsletterProviderName::Mailchimp->value => 'Mailchimp'])
                            ->default(NewsletterProviderName::Mailchimp->value)
                            ->selectablePlaceholder(false)
                            ->native(false)
                            ->required(),

                        TextInput::make('api_key')
                            ->label(__('newsletter::messages.settings.fields.api_key'))
                            ->helperText(fn (): string => $this->hasStoredKey
                                ? __('newsletter::messages.settings.helpers.api_key_stored')
                                : __('newsletter::messages.settings.helpers.api_key'))
                            ->password()
                            ->revealable(false)
                            ->autocomplete('new-password')
                            ->required(fn (): bool => ! $this->hasStoredKey)
                            ->maxLength(100)
                            ->rule(fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                                if (is_string($value) && trim($value) !== '' && ! MailchimpApiKey::isWellFormed($value)) {
                                    $fail(__('newsletter::messages.settings.errors.api_key_malformed'));
                                }
                            })
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                $set('server', $this->serverFor($state));
                            }),

                        TextInput::make('server')
                            ->label(__('newsletter::messages.settings.fields.server'))
                            ->helperText(__('newsletter::messages.settings.helpers.server'))
                            ->disabled()
                            ->dehydrated(false),

                        Select::make('list_id')
                            ->label(__('newsletter::messages.settings.fields.list'))
                            ->helperText(__('newsletter::messages.settings.helpers.list'))
                            ->options(fn (): array => $this->lists)
                            ->native(false)
                            ->required(),

                        Actions::make([
                            Action::make('testConnection')
                                ->label(__('newsletter::messages.settings.test_connection'))
                                ->icon('heroicon-o-signal')
                                ->color('gray')
                                ->action(fn () => $this->testConnection()),
                        ])->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('newsletter::messages.settings.consent'))
                    ->description(__('newsletter::messages.settings.consent_description'))
                    ->schema([
                        Textarea::make('consent_text')
                            ->label(__('newsletter::messages.settings.fields.consent_text'))
                            ->helperText(__('newsletter::messages.settings.helpers.consent_text'))
                            ->rows(3)
                            ->maxLength(500)
                            ->required(),

                        TextInput::make('privacy_url')
                            ->label(__('newsletter::messages.settings.fields.privacy_url'))
                            ->helperText(__('newsletter::messages.settings.helpers.privacy_url'))
                            ->maxLength(2048)
                            ->rule(fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                                if (is_string($value) && trim($value) !== '' && ! self::isSafeLink(trim($value))) {
                                    $fail(__('newsletter::messages.settings.errors.privacy_url'));
                                }
                            }),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * «Probar conexión»: asks Mailchimp with what is typed (or the saved key) and loads the lists
     * of the account. Saves nothing.
     */
    public function testConnection(): void
    {
        // Livewire methods can be called without the page's own checks
        abort_unless(self::canAccess(), 403);

        $apiKey = $this->apiKeyToUse();
        $listId = $this->data['list_id'] ?? null;
        $result = $this->check($apiKey, is_string($listId) ? $listId : null);

        $this->data['server'] = $apiKey->server();

        if ($result->ok || ($result->failure === ConnectionFailure::ListNotFound && $result->lists !== [])) {
            Notification::make()
                ->title(__('newsletter::messages.settings.notifications.connected'))
                ->body($result->ok ? null : $this->text('newsletter::messages.settings.notifications.choose_list'))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('newsletter::messages.settings.notifications.not_connected'))
            ->body($this->failureText($result->failure))
            ->danger()
            ->send();
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);

        $state = $this->form->getState();
        $apiKey = $this->apiKeyToUse();
        $stored = $this->repository()->get();
        $listId = is_string($state['list_id'] ?? null) ? $state['list_id'] : null;
        $result = $this->check($apiKey, $listId);

        $this->repository()->save(new NewsletterSettings(
            provider: NewsletterProviderName::tryFrom((string) ($state['provider'] ?? '')) ?? NewsletterProviderName::Mailchimp,
            apiKey: $apiKey,
            listId: $listId,
            listName: $listId === null ? null : ($this->lists[$listId] ?? ($stored->listId === $listId ? $stored->listName : null)),
            consentText: $this->trimmed($state['consent_text'] ?? null),
            privacyUrl: $this->trimmed($state['privacy_url'] ?? null),
            lastCheck: new ConnectionCheck(new DateTimeImmutable('now'), $result->failure),
        ));

        // The key goes back to an empty field: it is never shown again
        $this->hasStoredKey = true;
        $this->data['api_key'] = null;
        $this->data['server'] = $apiKey->server();

        if ($result->ok) {
            Notification::make()
                ->title(__('newsletter::messages.settings.notifications.saved'))
                ->body(__('newsletter::messages.settings.notifications.saved_available'))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('newsletter::messages.settings.notifications.saved_unavailable'))
            ->body($this->failureText($result->failure).' '.__('newsletter::messages.settings.notifications.hidden_until_connected'))
            ->warning()
            ->persistent()
            ->send();
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('newsletter::messages.settings.save'))
                ->submit('save'),
        ];
    }

    /**
     * A full http(s) address or a path of this site. Never "javascript:", "data:" or "//host".
     */
    public static function isSafeLink(string $value): bool
    {
        if (str_starts_with($value, '/')) {
            return ! str_starts_with($value, '//') && ! str_contains($value, '\\') && preg_match('/\s/', $value) !== 1;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        return in_array(is_string($scheme) ? strtolower($scheme) : null, ['http', 'https'], true)
            && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * The typed key, or the saved one when the field is empty.
     *
     * @throws ValidationException when there is neither or the typed one is malformed
     */
    private function apiKeyToUse(): MailchimpApiKey
    {
        $typed = $this->data['api_key'] ?? null;
        $typed = is_string($typed) ? trim($typed) : '';

        if ($typed !== '') {
            if (! MailchimpApiKey::isWellFormed($typed)) {
                throw ValidationException::withMessages(['data.api_key' => __('newsletter::messages.settings.errors.api_key_malformed')]);
            }

            return MailchimpApiKey::fromString($typed);
        }

        return $this->repository()->get()->apiKey
            ?? throw ValidationException::withMessages(['data.api_key' => __('newsletter::messages.settings.errors.api_key_required')]);
    }

    private function check(MailchimpApiKey $apiKey, ?string $listId): ConnectionCheckResultDTO
    {
        $result = app(NewsletterProviderFactoryInterface::class)->mailchimp($apiKey, $listId)->checkConnection();

        if ($result->lists !== []) {
            $this->lists = $result->lists;
        }

        return $result;
    }

    private function serverFor(?string $typed): ?string
    {
        $typed = trim($typed ?? '');

        if ($typed !== '') {
            return MailchimpApiKey::isWellFormed($typed) ? MailchimpApiKey::fromString($typed)->server() : null;
        }

        return $this->repository()->get()->apiKey?->server();
    }

    private function lastCheckHeading(): string
    {
        $check = $this->repository()->get()->lastCheck;

        if (! $check instanceof ConnectionCheck) {
            return __('newsletter::messages.settings.last_check.never');
        }

        $when = app(TimezoneServiceInterface::class)->format($check->checkedAt);

        return $check->ok()
            ? __('newsletter::messages.settings.last_check.ok', ['date' => $when])
            : __('newsletter::messages.settings.last_check.failed', ['date' => $when]);
    }

    private function lastCheckDescription(): ?string
    {
        $check = $this->repository()->get()->lastCheck;

        if (! $check instanceof ConnectionCheck) {
            return __('newsletter::messages.settings.last_check.never_description');
        }

        return $check->ok() ? null : $this->failureText($check->failure);
    }

    private function failureText(?ConnectionFailure $failure): string
    {
        return __('newsletter::messages.settings.failures.'.($failure ?? ConnectionFailure::RequestFailed)->value);
    }

    private function repository(): NewsletterSettingsRepositoryInterface
    {
        return app(NewsletterSettingsRepositoryInterface::class);
    }

    private function text(string $key): string
    {
        $text = __($key);

        return is_string($text) ? $text : $key;
    }

    private function trimmed(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
