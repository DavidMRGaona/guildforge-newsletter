<?php

declare(strict_types=1);

namespace Modules\Newsletter\Infrastructure\Services;

use App\Application\Modules\Services\ModuleManagerServiceInterface;
use App\Domain\Modules\ValueObjects\ModuleName;
use Illuminate\Support\Facades\Log;
use Modules\Newsletter\Application\Services\NewsletterAvailabilityInterface;
use Modules\Newsletter\Application\Services\NewsletterSettingsRepositoryInterface;
use Modules\Newsletter\Domain\ValueObjects\MailchimpApiKey;
use Throwable;

final readonly class NewsletterAvailability implements NewsletterAvailabilityInterface
{
    public function __construct(
        private ModuleManagerServiceInterface $modules,
        private NewsletterSettingsRepositoryInterface $settings,
    ) {}

    public function isAvailable(): bool
    {
        try {
            if ($this->modules->find(new ModuleName('newsletter'))?->isEnabled() !== true) {
                return false;
            }

            $settings = $this->settings->get();

            // Mailchimp is the only provider: a key and a list are all it needs
            return $settings->apiKey instanceof MailchimpApiKey
                && $settings->listId !== null
                && $settings->lastCheck?->ok() === true;
        } catch (Throwable $e) {
            Log::warning('Newsletter: availability could not be decided, so the newsletter is hidden', [
                'exception' => $e::class,
            ]);

            return false;
        }
    }
}
