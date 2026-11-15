<?php

declare(strict_types=1);

namespace Modules\Newsletter\Infrastructure\Services;

use App\Application\Impersonation\Services\ImpersonationContextInterface;
use App\Infrastructure\Authorization\Services\PermissionHolderFinder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\Newsletter\Application\DTOs\ConnectionCheckResultDTO;
use Modules\Newsletter\Application\DTOs\ConnectionCheckRun;
use Modules\Newsletter\Application\Services\ConnectionHealthInterface;
use Modules\Newsletter\Application\Services\NewsletterProviderFactoryInterface;
use Modules\Newsletter\Application\Services\NewsletterSettingsRepositoryInterface;
use Modules\Newsletter\Application\Services\SubscribeFailureCounterInterface;
use Modules\Newsletter\Domain\Enums\ConnectionFailure;
use Modules\Newsletter\Domain\ValueObjects\ConnectionCheck;
use Modules\Newsletter\Domain\ValueObjects\MailchimpApiKey;
use Modules\Newsletter\Infrastructure\Jobs\CheckNewsletterConnectionJob;
use Modules\Newsletter\Notifications\ProviderDownNotification;
use Throwable;

/**
 * The outage is read from the saved last check instead of a flag of its own: a check that fails
 * when the one before went well starts an outage (and tells), a check that fails after another
 * failure is the same outage, and a good one ends it. There is nothing to forget or to get stuck.
 * The check that is replaced is the one the repository hands back from inside its write lock, so
 * two checks never both think they started the outage.
 */
final readonly class ConnectionHealth implements ConnectionHealthInterface
{
    /** Failures in a row (see SubscribeFailureCounterInterface) from which a check is asked for */
    public const int FAILURES_BEFORE_CHECK = 3;

    /** Failures in a row, with the connection working, from which whoever configures is told */
    public const int FAILURES_BEFORE_REJECTION_NOTICE = 5;

    public const int MINUTES_BETWEEN_REQUESTED_CHECKS = 15;

    /** Minutes an unreachable provider must stay unreachable (two answers apart) to count as down */
    public const int MINUTES_TO_CONFIRM_UNREACHABLE = 10;

    public const string REQUEST_KEY = 'newsletter:connection-check-requested';

    /** When the provider was first found unreachable, until confirmed or answering again */
    public const string UNREACHABLE_SINCE_KEY = 'newsletter:unreachable-since';

    /** Set once a streak of refused sign-ups has been told about */
    public const string REJECTIONS_NOTIFIED_KEY = 'newsletter:rejections-notified';

    private const string RUNNING_LOCK = 'newsletter:connection-check';

    private const string CONFIGURE_PERMISSION = 'newsletter:newsletter.configure';

    public function __construct(
        private NewsletterSettingsRepositoryInterface $settings,
        private NewsletterProviderFactoryInterface $factory,
        private ImpersonationContextInterface $impersonation,
        private PermissionHolderFinder $holders,
        private SubscribeFailureCounterInterface $failures,
    ) {}

    public function check(): ConnectionCheckRun
    {
        // Nothing leaves the site, and nobody is told, while an administrator is looking as someone else
        if ($this->impersonation->isActive()) {
            return ConnectionCheckRun::skipped();
        }

        try {
            // The daily check and one asked for by failures can meet: one runs, the other leaves
            $run = Cache::lock(self::RUNNING_LOCK, 120)->get(fn (): ConnectionCheckRun => $this->run());

            // false: the lock is taken by another check
            return $run instanceof ConnectionCheckRun ? $run : ConnectionCheckRun::skipped();
        } catch (Throwable $e) {
            Log::warning('Newsletter: the connection check could not run', ['exception' => $e::class]);

            return ConnectionCheckRun::error();
        }
    }

    public function needsRecheck(): bool
    {
        try {
            return $this->settings->get()->lastCheck?->ok() === false || Cache::has(self::UNREACHABLE_SINCE_KEY);
        } catch (Throwable $e) {
            Log::warning('Newsletter: it could not be decided whether to check again', ['exception' => $e::class]);

            return false;
        }
    }

    public function checkAfterFailures(int $consecutiveFailures): bool
    {
        if ($consecutiveFailures < self::FAILURES_BEFORE_CHECK || $this->impersonation->isActive()) {
            return false;
        }

        try {
            // One request per window, whoever caused the failures
            if (! Cache::add(self::REQUEST_KEY, 1, now()->addMinutes(self::MINUTES_BETWEEN_REQUESTED_CHECKS))) {
                return false;
            }

            CheckNewsletterConnectionJob::dispatch();

            return true;
        } catch (Throwable $e) {
            Log::warning('Newsletter: a connection check could not be requested', ['exception' => $e::class]);

            return false;
        }
    }

    public function subscriptionsWorking(): void
    {
        try {
            Cache::forget(self::REJECTIONS_NOTIFIED_KEY);
        } catch (Throwable $e) {
            Log::warning('Newsletter: the refused sign-ups notice could not be rearmed', ['exception' => $e::class]);
        }
    }

    private function run(): ConnectionCheckRun
    {
        $saved = $this->settings->get();
        $apiKey = $saved->apiKey;
        $listId = $saved->listId;
        $startedFrom = $saved->lastCheck;

        if (! $apiKey instanceof MailchimpApiKey || $listId === null) {
            return ConnectionCheckRun::skipped();
        }

        $failure = $this->ask($apiKey, $listId)->failure;

        // Not an answer of the provider: never recorded as one
        if ($failure === ConnectionFailure::Impersonation) {
            return ConnectionCheckRun::skipped();
        }

        if ($failure === ConnectionFailure::Unreachable && $startedFrom?->ok() !== false && ! $this->unreachableConfirmed()) {
            return ConnectionCheckRun::pending();
        }

        $check = new ConnectionCheck(now()->toImmutable(), $failure);
        $recording = $this->settings->recordCheck($check, $apiKey, $listId, $startedFrom);

        // The key, the list or the last check changed while Mailchimp answered: that answer is
        // about something else and is dropped, together with the notice it would have caused
        if (! $recording->written) {
            return ConnectionCheckRun::skipped();
        }

        Cache::forget(self::UNREACHABLE_SINCE_KEY);

        if (! $check->ok()) {
            // A new outage only when the check it replaced had gone well
            if ($recording->replaced?->ok() === true) {
                $this->tell(ProviderDownNotification::forFailure($failure ?? ConnectionFailure::RequestFailed));
            }
        } else {
            $this->tellAboutRefusedSignUps();
        }

        return ConnectionCheckRun::recorded($check);
    }

    /**
     * The first unreachable answer is remembered and not recorded; a later one counts only when it
     * comes at least ten minutes after the first.
     */
    private function unreachableConfirmed(): bool
    {
        $since = Cache::get(self::UNREACHABLE_SINCE_KEY);

        if (! is_int($since)) {
            Cache::put(self::UNREACHABLE_SINCE_KEY, now()->getTimestamp(), now()->addDays(2));

            return false;
        }

        return now()->getTimestamp() - $since >= self::MINUTES_TO_CONFIRM_UNREACHABLE * 60;
    }

    /**
     * The connection works but the list refuses every address (a required field of the audience
     * the form does not have): tell once per streak. The newsletter stays on.
     */
    private function tellAboutRefusedSignUps(): void
    {
        if ($this->failures->current() >= self::FAILURES_BEFORE_REJECTION_NOTICE
            && Cache::add(self::REJECTIONS_NOTIFIED_KEY, 1, now()->addDay())) {
            $this->tell(ProviderDownNotification::refusingSignUps());
        }
    }

    private function ask(MailchimpApiKey $apiKey, string $listId): ConnectionCheckResultDTO
    {
        try {
            return $this->factory->mailchimp($apiKey, $listId)->checkConnection();
        } catch (Throwable $e) {
            // A check that cannot be made is a failed one, never the previous "ok" kept by default
            Log::warning('Newsletter: the connection check failed unexpectedly', ['exception' => $e::class]);

            return new ConnectionCheckResultDTO(ConnectionFailure::RequestFailed);
        }
    }

    private function tell(ProviderDownNotification $notice): void
    {
        try {
            $people = $this->holders->usersWhoCan(self::CONFIGURE_PERMISSION);

            if ($people->isNotEmpty()) {
                Notification::send($people, $notice);
            }
        } catch (Throwable $e) {
            // The panel already says so: a notice that cannot go out is logged
            Log::error('Newsletter: nobody could be told about the newsletter problem', ['exception' => $e::class]);
        }
    }
}
