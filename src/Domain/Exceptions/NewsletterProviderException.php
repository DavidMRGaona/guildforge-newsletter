<?php

declare(strict_types=1);

namespace Modules\Newsletter\Domain\Exceptions;

use Modules\Newsletter\Domain\Enums\ConnectionFailure;
use RuntimeException;
use Throwable;

/**
 * The provider could not do what was asked. The message never carries an address or a key: it
 * names the operation and the HTTP status at most.
 */
final class NewsletterProviderException extends RuntimeException
{
    private function __construct(
        public readonly ConnectionFailure $failure,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function because(ConnectionFailure $failure, string $operation, ?int $status = null, ?Throwable $previous = null): self
    {
        $detail = $status === null ? '' : " (HTTP {$status})";

        return new self($failure, "Newsletter provider: {$operation} failed: {$failure->value}{$detail}", $previous);
    }
}
