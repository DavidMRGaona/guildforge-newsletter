<?php

declare(strict_types=1);

namespace Modules\Newsletter\Domain\Enums;

/**
 * Why talking to the provider did not work. Stored with the last connection check and carried by
 * NewsletterProviderException, so callers decide by case, never by message.
 */
enum ConnectionFailure: string
{
    /** Network error, timeout, rate limit or a server error on the provider's side */
    case Unreachable = 'unreachable';

    /** The provider refuses the API key (wrong, revoked or the account is disabled) */
    case InvalidApiKey = 'invalid_api_key';

    /** No list chosen, or the chosen one is not in the account */
    case ListNotFound = 'list_not_found';

    /** Any other answer the module does not expect */
    case RequestFailed = 'request_failed';

    /** No key or no list saved */
    case NotConfigured = 'not_configured';

    /** An administrator is impersonating someone: nothing leaves the site during the request */
    case Impersonation = 'impersonation';
}
