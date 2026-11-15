<?php

declare(strict_types=1);

namespace Modules\Newsletter\Notifications;

use App\Application\Mail\Contracts\HasMailCategory;
use App\Application\SiteNotifications\Contracts\HasSiteNotification;
use App\Application\SiteNotifications\DTOs\SiteNotificationDTO;
use App\Notifications\Support\EscapesMarkdown;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Newsletter\Domain\Enums\ConnectionFailure;
use Modules\Newsletter\Filament\Pages\NewsletterSettingsPage;
use Modules\Newsletter\NewsletterServiceProvider;

/**
 * Tells whoever configures the newsletter that Mailchimp stopped accepting the saved key or list,
 * so the newsletter is hidden from the web until a check goes well. Sent once per outage. It
 * carries only the kind of failure (the notification is queued), never a key or an address.
 */
final class ProviderDownNotification extends Notification implements HasMailCategory, HasSiteNotification, ShouldQueue
{
    use Queueable;

    private const string REFUSED_SIGN_UPS = 'refused_sign_ups';

    /**
     * @param  string  $reason  A ConnectionFailure value, or REFUSED_SIGN_UPS
     */
    private function __construct(private readonly string $reason) {}

    public static function forFailure(ConnectionFailure $failure): self
    {
        return new self($failure->value);
    }

    /**
     * The connection works, but the list refuses the sign-ups (a streak of them).
     */
    public static function refusingSignUps(): self
    {
        return new self(self::REFUSED_SIGN_UPS);
    }

    /**
     * Null for refused sign-ups: the connection itself works.
     */
    public function failure(): ?ConnectionFailure
    {
        return $this->reason === self::REFUSED_SIGN_UPS
            ? null
            : (ConnectionFailure::tryFrom($this->reason) ?? ConnectionFailure::RequestFailed);
    }

    public function mailCategory(): string
    {
        return NewsletterServiceProvider::MAIL_PROVIDER_DOWN;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', HasSiteNotification::CHANNEL];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = is_string($notifiable->name ?? null) ? $notifiable->name : '';

        return (new MailMessage)
            ->subject(__('newsletter::messages.'.$this->key().'.mail.subject'))
            ->greeting(__('newsletter::messages.provider_down.mail.greeting', ['name' => EscapesMarkdown::escape($name)]))
            ->line(__('newsletter::messages.'.$this->key().'.mail.body'))
            ->line($this->reason())
            ->line(__('newsletter::messages.'.$this->key().'.mail.hint'))
            ->action(__('newsletter::messages.provider_down.mail.action'), $this->url(absolute: true));
    }

    public function toSiteNotification(object $notifiable): SiteNotificationDTO
    {
        return new SiteNotificationDTO(
            title: __('newsletter::messages.'.$this->key().'.notice'),
            url: $this->url(absolute: false),
            body: $this->reason(),
            icon: 'mail',
        );
    }

    private function key(): string
    {
        return $this->reason === self::REFUSED_SIGN_UPS ? 'provider_down.refused_sign_ups' : 'provider_down';
    }

    private function reason(): string
    {
        $failure = $this->failure();

        return $failure instanceof ConnectionFailure
            ? __('newsletter::messages.settings.failures.'.$failure->value)
            : __('newsletter::messages.provider_down.refused_sign_ups.reason');
    }

    private function url(bool $absolute): string
    {
        return NewsletterSettingsPage::getUrl(isAbsolute: $absolute, panel: 'admin');
    }
}
