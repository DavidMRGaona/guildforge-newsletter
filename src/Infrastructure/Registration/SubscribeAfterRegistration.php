<?php

declare(strict_types=1);

namespace Modules\Newsletter\Infrastructure\Registration;

use App\Domain\Entities\User;
use App\Domain\Events\RegistrationFieldsSubmitted;
use App\Domain\Repositories\UserRepositoryInterface;
use App\Domain\ValueObjects\UserId;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Modules\Newsletter\Application\Services\NewsletterAvailabilityInterface;
use Modules\Newsletter\Application\Services\SubscriptionRequesterInterface;
use Throwable;

/**
 * Asks to join the list for the account that ticked «Quiero recibir el boletín».
 *
 * Queued and after the commit, as the registration contract asks (core#699). The event carries
 * only the account id: the address is read here, when the work runs, and goes on to the encrypted
 * subscription job, never into this listener's own payload. Whether the newsletter is still
 * available is asked again: it may have stopped being so while the listener waited.
 */
final class SubscribeAfterRegistration implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly SubscriptionRequesterInterface $subscriptions,
        private readonly NewsletterAvailabilityInterface $availability,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function handle(RegistrationFieldsSubmitted $event): void
    {
        if (! $event->ticked(SubscribeOnRegistrationField::KEY) || ! $this->availability->isAvailable()) {
            return;
        }

        try {
            $user = $this->users->findById(new UserId($event->userId));
        } catch (Throwable $e) {
            Log::warning('Newsletter: the account of a registration could not be read', ['exception' => $e::class]);

            return;
        }

        if (! $user instanceof User || $user->isAnonymized()) {
            return;
        }

        $this->subscriptions->request($user->email(), $user->name());
    }
}
