<?php

declare(strict_types=1);

namespace Modules\Newsletter\Console\Commands;

use Illuminate\Console\Command;
use Modules\Newsletter\Application\Services\ConnectionHealthInterface;
use Modules\Newsletter\Domain\Enums\ConnectionCheckStatus;
use Modules\Newsletter\Domain\Enums\ConnectionFailure;
use Modules\Newsletter\Domain\ValueObjects\ConnectionCheck;

/**
 * The check of the Mailchimp connection: daily, and every 30 minutes with --recheck while the
 * newsletter is off (or an unreachable provider awaits confirmation), both scheduled by the
 * module. A failure switches the newsletter off and tells whoever configures it; a good check
 * switches it on again. Exits with an error when the check failed or could not run.
 */
final class CheckConnectionCommand extends Command
{
    protected $signature = 'newsletter:check-connection {--recheck : Only check when the last check failed or an unreachable provider awaits confirmation}';

    protected $description = 'Check that Mailchimp still accepts the saved key and list';

    public function handle(ConnectionHealthInterface $health): int
    {
        if ($this->option('recheck') === true && ! $health->needsRecheck()) {
            return self::SUCCESS;
        }

        $run = $health->check();

        if ($run->status === ConnectionCheckStatus::Error) {
            $this->error('The check could not run (see the log).');

            return self::FAILURE;
        }

        if ($run->status === ConnectionCheckStatus::Skipped) {
            $this->info('Nothing was checked (nothing configured, another check running or the settings changed meanwhile).');

            return self::SUCCESS;
        }

        if ($run->status === ConnectionCheckStatus::Pending) {
            $this->warn('Mailchimp could not be reached; it is checked again before the newsletter is hidden.');

            return self::SUCCESS;
        }

        $check = $run->check;

        if (! $check instanceof ConnectionCheck || $check->ok()) {
            $this->info('The connection works.');

            return self::SUCCESS;
        }

        $this->error('The connection failed: '.($check->failure ?? ConnectionFailure::RequestFailed)->value.'. The newsletter is hidden until a check goes well.');

        return self::FAILURE;
    }
}
