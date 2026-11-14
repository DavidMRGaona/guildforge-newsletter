<?php

declare(strict_types=1);

namespace Modules\Newsletter\Domain\ValueObjects;

/**
 * The id of a Mailchimp list (audience), e.g. "a1b2c3d4e5". It goes into request paths, so
 * anything but letters and digits is refused.
 */
final class MailchimpListId
{
    private const string PATTERN = '/^[A-Za-z0-9]{1,32}$/';

    public static function isWellFormed(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }
}
