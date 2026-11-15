<?php

declare(strict_types=1);

namespace Modules\Newsletter\Application\Services;

use SensitiveParameter;

/**
 * Asks the provider, in the background, to add an address to the list with double opt-in. The
 * person is always told to check their email: nothing here says whether the address was already
 * on the list.
 *
 * Nothing is asked while an administrator is impersonating someone.
 */
interface SubscriptionRequesterInterface
{
    public function request(#[SensitiveParameter] string $email, #[SensitiveParameter] ?string $name): void;
}
