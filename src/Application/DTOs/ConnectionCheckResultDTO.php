<?php

declare(strict_types=1);

namespace Modules\Newsletter\Application\DTOs;

use Modules\Newsletter\Domain\Enums\ConnectionFailure;

/**
 * What checkConnection() found: whether the key and the chosen list work and, whenever the key
 * does, the lists of the account to choose from.
 */
final readonly class ConnectionCheckResultDTO
{
    public bool $ok;

    /**
     * @param  array<string, string>  $lists  id => name
     */
    public function __construct(
        public ?ConnectionFailure $failure,
        public array $lists = [],
    ) {
        $this->ok = ! $failure instanceof ConnectionFailure;
    }
}
