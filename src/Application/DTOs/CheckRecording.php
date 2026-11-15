<?php

declare(strict_types=1);

namespace Modules\Newsletter\Application\DTOs;

use Modules\Newsletter\Domain\ValueObjects\ConnectionCheck;

/**
 * The answer of NewsletterSettingsRepositoryInterface::recordCheck().
 */
final readonly class CheckRecording
{
    private function __construct(
        public bool $written,
        public ?ConnectionCheck $replaced,
    ) {}

    /**
     * @param  ConnectionCheck|null  $replaced  The last check the saved settings held before this one
     */
    public static function written(?ConnectionCheck $replaced): self
    {
        return new self(true, $replaced);
    }

    public static function refused(): self
    {
        return new self(false, null);
    }
}
