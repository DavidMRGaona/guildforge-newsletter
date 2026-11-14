<?php

declare(strict_types=1);

namespace Modules\Newsletter\Domain\Enums;

/**
 * What asking to join did. The person is always told the same («check your email»), so none of
 * these reveals whether the address was already on the list.
 */
enum SubscribeOutcome: string
{
    /** New or former member put in pending: the provider sends the confirmation email */
    case ConfirmationRequested = 'confirmation_requested';

    /** Already subscribed or waiting for confirmation: nothing was changed */
    case AlreadyActive = 'already_active';

    /** Bounced address (Mailchimp "cleaned"): it cannot be put back through the API */
    case NotReactivable = 'not_reactivable';

    /** The provider refused the address itself (permanently deleted, looks fake…) */
    case Rejected = 'rejected';
}
