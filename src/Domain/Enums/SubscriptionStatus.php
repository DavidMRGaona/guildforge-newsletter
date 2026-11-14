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

    case NotFound = 'not_found';
}
