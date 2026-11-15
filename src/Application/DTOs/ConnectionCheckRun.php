<?php

declare(strict_types=1);

namespace Modules\Newsletter\Application\DTOs;

use Modules\Newsletter\Domain\Enums\ConnectionCheckStatus;
use Modules\Newsletter\Domain\ValueObjects\ConnectionCheck;

final readonly class ConnectionCheckRun
{
    private function __construct(
        public ConnectionCheckStatus $status,
        public ?ConnectionCheck $check = null,
    ) {}

    public static function recorded(ConnectionCheck $check): self
    {
        return new self(ConnectionCheckStatus::Recorded, $check);
    }

    public static function pending(): self
    {
        return new self(ConnectionCheckStatus::Pending);
    }

    public static function skipped(): self
    {
        return new self(ConnectionCheckStatus::Skipped);
    }

    public static function error(): self
    {
        return new self(ConnectionCheckStatus::Error);
    }
}
