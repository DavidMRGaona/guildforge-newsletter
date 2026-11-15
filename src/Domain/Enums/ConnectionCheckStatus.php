<?php

declare(strict_types=1);

namespace Modules\Newsletter\Domain\Enums;

/**
 * What became of an attempt to check the connection.
 */
enum ConnectionCheckStatus: string
{
    /** The provider answered and the answer was saved */
    case Recorded = 'recorded';

    /** The provider could not be reached for the first time: it is confirmed later, nothing was saved */
    case Pending = 'pending';

    /** Nothing to do: nothing configured, an impersonation, another check running, or the settings changed meanwhile */
    case Skipped = 'skipped';

    /** The check could not run (an error was caught: cache, database, lock) */
    case Error = 'error';
}
