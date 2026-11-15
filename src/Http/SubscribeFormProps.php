<?php

declare(strict_types=1);

namespace Modules\Newsletter\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Newsletter\Application\Services\NewsletterSettingsRepositoryInterface;

/**
 * What the subscription form needs in the browser, the same for the footer, a popup and the
 * /newsletter page. Someone signed in finds their own address and name already filled in.
 */
final readonly class SubscribeFormProps
{
    public const int NAME_MAX_LENGTH = 100;

    public function __construct(
        private NewsletterSettingsRepositoryInterface $settings,
    ) {}

    /**
     * @return array{action: string, intro: ?string, buttonLabel: ?string, showName: bool, consentText: string, privacyUrl: ?string, email: ?string, name: ?string}
     */
    public function for(?Authenticatable $user, ?string $intro = null, ?string $buttonLabel = null, bool $showName = true): array
    {
        $settings = $this->settings->get();

        return [
            'action' => route('newsletter.store', absolute: false),
            'intro' => $this->text($intro),
            'buttonLabel' => $this->text($buttonLabel),
            'showName' => $showName,
            'consentText' => $this->text($settings->consentText) ?? (string) __('newsletter::messages.settings.consent_default'),
            'privacyUrl' => $this->text($settings->privacyUrl),
            'email' => $this->text(data_get($user, 'email')),
            'name' => $showName ? $this->text(mb_substr((string) data_get($user, 'name'), 0, self::NAME_MAX_LENGTH)) : null,
        ];
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
