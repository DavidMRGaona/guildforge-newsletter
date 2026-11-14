<?php

declare(strict_types=1);

namespace Modules\Newsletter\Domain\Enums;

/**
 * The services the newsletter can use. Only Mailchimp for now.
 */
enum NewsletterProviderName: string
{
    case Mailchimp = 'mailchimp';
}
