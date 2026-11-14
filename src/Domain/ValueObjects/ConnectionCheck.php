<?php

declare(strict_types=1);

namespace Modules\Newsletter\Domain\ValueObjects;

use DateTimeImmutable;
use Modules\Newsletter\Domain\Enums\ConnectionFailure;

/**
 * The last time the module asked the provider whether the saved key and list work, and the
 * answer. The newsletter is only offered while the last check went well.
 */
final readonly class ConnectionCheck
{
    public function __construct(
        public DateTimeImmutable $checkedAt,
        public ?ConnectionFailure $failure,
    ) {}

    public function ok(): bool
    {
        return ! $this->failure instanceof ConnectionFailure;
    }
}
