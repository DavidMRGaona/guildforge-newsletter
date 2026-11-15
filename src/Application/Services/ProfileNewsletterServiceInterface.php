<?php

declare(strict_types=1);

namespace Modules\Newsletter\Application\Services;

use Modules\Newsletter\Domain\Enums\ProfileNewsletterState;
use SensitiveParameter;

/**
 * The newsletter as the profile shows it: where the account's address stands on the list, and
 * joining or leaving with it. The status is asked to the provider and remembered for a few minutes
 * per account; changes go through the queue.
 *
 * Nothing is asked or written while an administrator is impersonating someone, and a provider that
 * fails never throws here: the answer is ProfileNewsletterState::Unavailable.
 */
interface ProfileNewsletterServiceInterface
{
    public function state(string $userId, #[SensitiveParameter] string $email): ProfileNewsletterState;

    /**
     * Asks to join, only for an address that is not on the list; the confirmation email is the
     * provider's. Returns the state to show afterwards.
     */
    public function subscribe(string $userId, #[SensitiveParameter] string $email, #[SensitiveParameter] ?string $name): ProfileNewsletterState;

    /**
     * Leaves the list, only for an address that is subscribed or waiting for confirmation.
     */
    public function unsubscribe(string $userId, #[SensitiveParameter] string $email): ProfileNewsletterState;
}
