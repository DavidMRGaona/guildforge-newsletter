<?php

declare(strict_types=1);

namespace Modules\Newsletter\Domain\Enums;

/**
 * Where an address stands on the newsletter list, as the provider tells it.
 */
enum SubscriptionStatus: string
{
    case Subscribed = 'subscribed';

    /** Asked to join and not confirmed yet: the provider sent the confirmation email */
    case Pending = 'pending';

    case Unsubscribed = 'unsubscribed';

    /**
     * Cannot join again through the site: the provider stopped sending to it (Mailchimp "cleaned",
     * a bounced address). Never offer «subscribe» for it
     */
    case Blocked = 'blocked';

    case NotFound = 'not_found';
}
