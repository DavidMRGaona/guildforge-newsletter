<?php

declare(strict_types=1);

namespace Modules\Newsletter\Infrastructure\Knowledge;

use App\Application\Knowledge\Contracts\KnowledgeCapabilityInterface;
use Illuminate\Contracts\Container\Container;
use Modules\Newsletter\Application\Services\NewsletterAvailabilityInterface;

/**
 * The newsletter is on offer right now: the same rule that shows the form, the page, the profile
 * section and the registration checkbox. The site guide only shows while it holds. Asked each
 * time, because the availability is scoped to the request.
 */
final readonly class NewsletterAvailableCapability implements KnowledgeCapabilityInterface
{
    public function __construct(private Container $container) {}

    public function key(): string
    {
        return 'newsletter.available';
    }

    public function passes(object $user): bool
    {
        return $this->container->make(NewsletterAvailabilityInterface::class)->isAvailable();
    }
}
