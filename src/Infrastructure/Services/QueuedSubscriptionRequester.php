<?php

declare(strict_types=1);

namespace Modules\Newsletter\Infrastructure\Services;

use App\Application\Impersonation\Services\ImpersonationContextInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Newsletter\Application\Services\SubscriptionRequesterInterface;
use Modules\Newsletter\Infrastructure\Jobs\SubscribeToNewsletterJob;
use SensitiveParameter;

final readonly class QueuedSubscriptionRequester implements SubscriptionRequesterInterface
{
    public function __construct(
        private Dispatcher $bus,
        private ImpersonationContextInterface $impersonation,
    ) {}

    public function request(#[SensitiveParameter] string $email, #[SensitiveParameter] ?string $name): void
    {
        // The safety net neutralises the queue during an impersonation (core#569); do not rely on it
        if ($this->impersonation->isActive()) {
            return;
        }

        $this->bus->dispatch(new SubscribeToNewsletterJob($email, $name));
    }
}
