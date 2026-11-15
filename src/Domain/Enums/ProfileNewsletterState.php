<?php

declare(strict_types=1);

namespace Modules\Newsletter\Domain\Enums;

/**
 * What the profile tells a person about their own address and the newsletter.
 */
enum ProfileNewsletterState: string
{
    case Subscribed = 'subscribed';

    /** A confirmation email was sent and the link was not opened yet */
    case Pending = 'pending';

    /** Not on the list (never joined, left, or archived): the person may subscribe */
    case Unsubscribed = 'unsubscribed';

    /** A bounced address: the provider cannot send to it, so «subscribe» is never offered */
    case Blocked = 'blocked';

    /** The provider could not be asked now, or nothing is asked (an impersonation) */
    case Unavailable = 'unavailable';

    public static function fromStatus(SubscriptionStatus $status): self
    {
        return match ($status) {
            SubscriptionStatus::Subscribed => self::Subscribed,
            SubscriptionStatus::Pending => self::Pending,
            SubscriptionStatus::Blocked => self::Blocked,
            SubscriptionStatus::Unsubscribed, SubscriptionStatus::NotFound => self::Unsubscribed,
        };
    }
}
