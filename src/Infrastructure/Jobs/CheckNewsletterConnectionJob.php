<?php

declare(strict_types=1);

namespace Modules\Newsletter\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Newsletter\Application\Services\ConnectionHealthInterface;

/**
 * The connection check asked for by failed subscriptions. It carries nothing: key and list are
 * read when it runs, so a worker never keeps old ones. Tried once; the daily check is the net.
 */
final class CheckNewsletterConnectionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function handle(ConnectionHealthInterface $health): void
    {
        $health->check();
    }
}
