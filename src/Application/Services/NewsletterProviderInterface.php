<?php

declare(strict_types=1);

namespace Modules\Newsletter\Application\Services;

use Modules\Newsletter\Application\DTOs\ConnectionCheckResultDTO;
use Modules\Newsletter\Domain\Enums\SubscribeOutcome;
use Modules\Newsletter\Domain\Enums\SubscriptionStatus;
use Modules\Newsletter\Domain\Exceptions\NewsletterProviderException;

/**
 * The service that keeps the newsletter list (Mailchimp). The site never stores the addresses:
 * every question goes to the provider.
 *
 * Implementations mark the address and the name #[\SensitiveParameter], so they never show in a
 * stack trace. Nothing is sent while an administrator is impersonating someone: every method fails with
 * ConnectionFailure::Impersonation instead.
 */
interface NewsletterProviderInterface
{
    /**
     * Ask to join the list with double opt-in: the provider emails a confirmation link.
     *
     * @throws NewsletterProviderException when the provider cannot be reached or refuses the request
     */
    public function subscribe(string $email, ?string $name = null): SubscribeOutcome;

    /**
     * @throws NewsletterProviderException
     */
    public function status(string $email): SubscriptionStatus;

    /**
     * Leave the list. An address that is not subscribed or pending is left as it is.
     *
     * @throws NewsletterProviderException
     */
    public function unsubscribe(string $email): void;

    /**
     * Whether the key and the chosen list work. Never throws: a failure comes in the result.
     */
    public function checkConnection(): ConnectionCheckResultDTO;
}
