<?php

declare(strict_types=1);

namespace Modules\Newsletter\Infrastructure\Jobs;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * For jobs that carry an address in their (encrypted) payload: call it from failed() so the copy
 * the worker writes to failed_jobs afterwards does not stay. Needs InteractsWithQueue.
 */
trait ForgetsFailedJobCopy
{
    /**
     * The worker writes the failed job to failed_jobs after failed() returns (when it hears
     * JobFailed), so the copy is deleted from a listener that runs after the worker's own.
     */
    protected function forgetFailedCopy(): void
    {
        $uuid = $this->job?->uuid();

        if (! is_string($uuid) || $uuid === '') {
            return;
        }

        $forget = static function () use ($uuid): void {
            try {
                app('queue.failer')->forget($uuid);
            } catch (Throwable $e) {
                Log::warning('Newsletter: a failed subscription could not be removed from the failed jobs', ['exception' => $e::class]);
            }
        };

        // In case the copy is already there
        $forget();

        $handled = false;
        app(Dispatcher::class)->listen(JobFailed::class, static function (JobFailed $event) use ($uuid, $forget, &$handled): void {
            if ($handled || $event->job->uuid() !== $uuid) {
                return;
            }

            $handled = true;
            $forget();
        });
    }
}
