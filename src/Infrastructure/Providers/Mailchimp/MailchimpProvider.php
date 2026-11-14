<?php

declare(strict_types=1);

namespace Modules\Newsletter\Infrastructure\Providers\Mailchimp;

use App\Application\Impersonation\Services\ImpersonationContextInterface;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Newsletter\Application\DTOs\ConnectionCheckResultDTO;
use Modules\Newsletter\Application\Services\NewsletterProviderInterface;
use Modules\Newsletter\Domain\Enums\ConnectionFailure;
use Modules\Newsletter\Domain\Enums\SubscribeOutcome;
use Modules\Newsletter\Domain\Enums\SubscriptionStatus;
use Modules\Newsletter\Domain\Exceptions\NewsletterProviderException;
use Modules\Newsletter\Domain\ValueObjects\MailchimpApiKey;
use Modules\Newsletter\Domain\ValueObjects\MailchimpListId;
use Modules\Newsletter\Domain\ValueObjects\SubscriberHash;
use Throwable;

/**
 * The newsletter list in Mailchimp, through the Marketing API v3 and Laravel's HTTP client.
 *
 * Members are addressed by the MD5 of their lowercase address. Joining reads the member first and
 * then writes it with PUT, so each previous state gets what it needs: new → pending (Mailchimp
 * emails the confirmation), unsubscribed or archived → pending again (the confirmation is sent
 * again), subscribed or pending → nothing, cleaned (bounced) → nothing, since the API cannot
 * bring it back.
 *
 * Neither addresses nor response bodies (Mailchimp repeats the address in its error details) are
 * ever logged: only the subscriber hash, the operation, the HTTP status and Mailchimp's title.
 */
final readonly class MailchimpProvider implements NewsletterProviderInterface
{
    public const int TIMEOUT_SECONDS = 5;

    public const int CONNECT_TIMEOUT_SECONDS = 3;

    /** Mailchimp ignores the user name of basic authentication; the key is the password */
    private const string AUTH_USER = 'guildforge';

    /** The most lists Mailchimp returns in one page */
    private const int LIST_PAGE_SIZE = 1000;

    /** Statuses a member can be put back to pending from */
    private const array REACTIVABLE = ['unsubscribed', 'archived', 'transactional'];

    public function __construct(
        private ImpersonationContextInterface $impersonation,
        private MailchimpApiKey $apiKey,
        private ?string $listId,
    ) {}

    public function subscribe(string $email, ?string $name = null): SubscribeOutcome
    {
        $listId = $this->listOrFail('subscribe');
        $hash = SubscriberHash::fromEmail($email);
        $current = $this->memberStatus($listId, $hash, 'subscribe');

        if ($current === 'subscribed' || $current === 'pending') {
            return SubscribeOutcome::AlreadyActive;
        }

        if ($current === 'cleaned') {
            Log::info('Newsletter: a bounced address asked to join again; Mailchimp does not let it back', [
                'subscriber_hash' => $hash->value(),
            ]);

            return SubscribeOutcome::NotReactivable;
        }

        // A status this module does not know: better nothing than a wrong change
        if ($current !== null && ! in_array($current, self::REACTIVABLE, true)) {
            throw $this->failure(ConnectionFailure::RequestFailed, 'subscribe', $hash, title: 'Unknown member status');
        }

        $body = ['email_address' => trim($email), 'status_if_new' => 'pending'];

        // Only for a member that exists: on a new one, "status" would also put back to pending a
        // member created in the meantime
        if ($current !== null) {
            $body['status'] = 'pending';
        }

        $name = trim($name ?? '');

        if ($name !== '') {
            $body['merge_fields'] = ['FNAME' => mb_substr($name, 0, 100)];
        }

        $response = $this->send('subscribe', $hash, fn (PendingRequest $request): Response => $request->put($this->memberPath($listId, $hash), $body));

        if ($response->status() === 400) {
            Log::warning('Newsletter: Mailchimp refused the address', [
                'subscriber_hash' => $hash->value(),
                'title' => $this->title($response),
            ]);

            return SubscribeOutcome::Rejected;
        }

        $this->assertSuccessful($response, 'subscribe', $hash);

        return SubscribeOutcome::ConfirmationRequested;
    }

    public function status(string $email): SubscriptionStatus
    {
        $listId = $this->listOrFail('status');

        return match ($this->memberStatus($listId, SubscriberHash::fromEmail($email), 'status')) {
            null, 'transactional' => SubscriptionStatus::NotFound,
            'subscribed' => SubscriptionStatus::Subscribed,
            'pending' => SubscriptionStatus::Pending,
            default => SubscriptionStatus::Unsubscribed,
        };
    }

    public function unsubscribe(string $email): void
    {
        $listId = $this->listOrFail('unsubscribe');
        $hash = SubscriberHash::fromEmail($email);
        $current = $this->memberStatus($listId, $hash, 'unsubscribe');

        if ($current !== 'subscribed' && $current !== 'pending') {
            return;
        }

        $response = $this->send('unsubscribe', $hash, fn (PendingRequest $request): Response => $request->patch(
            $this->memberPath($listId, $hash),
            ['status' => 'unsubscribed'],
        ));

        // Deleted in the meantime: nothing left to unsubscribe
        if ($response->status() !== 404) {
            $this->assertSuccessful($response, 'unsubscribe', $hash);
        }
    }

    public function checkConnection(): ConnectionCheckResultDTO
    {
        try {
            $this->assertNotImpersonating('check connection');

            $response = $this->send('check connection', null, fn (PendingRequest $request): Response => $request->get('/lists', [
                'fields' => 'lists.id,lists.name',
                'count' => self::LIST_PAGE_SIZE,
            ]));

            // A 404 on the lists themselves is not a missing list: something else is wrong
            if ($response->status() === 404) {
                throw $this->failure(ConnectionFailure::RequestFailed, 'check connection', null, 404);
            }

            $this->assertSuccessful($response, 'check connection', null);
        } catch (NewsletterProviderException $e) {
            return new ConnectionCheckResultDTO($e->failure);
        }

        $lists = $this->lists($response);

        if ($this->listId === null || ! array_key_exists($this->listId, $lists)) {
            return new ConnectionCheckResultDTO(ConnectionFailure::ListNotFound, $lists);
        }

        return new ConnectionCheckResultDTO(null, $lists);
    }

    /**
     * The member's status, or null when the list has no such member.
     */
    private function memberStatus(string $listId, SubscriberHash $hash, string $operation): ?string
    {
        $response = $this->send($operation, $hash, fn (PendingRequest $request): Response => $request->get($this->memberPath($listId, $hash)));

        if ($response->status() === 404) {
            return null;
        }

        $this->assertSuccessful($response, $operation, $hash);

        $status = $response->json('status');

        if (! is_string($status) || $status === '') {
            throw $this->failure(ConnectionFailure::RequestFailed, $operation, $hash, $response->status());
        }

        return $status;
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     */
    private function send(string $operation, ?SubscriberHash $hash, Closure $call): Response
    {
        $this->assertNotImpersonating($operation);

        try {
            return $call(
                Http::baseUrl($this->apiKey->baseUrl())
                    ->withBasicAuth(self::AUTH_USER, $this->apiKey->value())
                    ->acceptJson()
                    ->asJson()
                    ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                    ->timeout(self::TIMEOUT_SECONDS),
            );
        } catch (ConnectionException $e) {
            throw $this->failure(ConnectionFailure::Unreachable, $operation, $hash, null, $e);
        }
    }

    private function assertSuccessful(Response $response, string $operation, ?SubscriberHash $hash): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        $failure = match (true) {
            $status === 401, $status === 403 => ConnectionFailure::InvalidApiKey,
            $status === 404 => ConnectionFailure::ListNotFound,
            $status === 429, $status >= 500 => ConnectionFailure::Unreachable,
            default => ConnectionFailure::RequestFailed,
        };

        throw $this->failure($failure, $operation, $hash, $status, title: $this->title($response));
    }

    private function failure(
        ConnectionFailure $failure,
        string $operation,
        ?SubscriberHash $hash,
        ?int $status = null,
        ?Throwable $previous = null,
        ?string $title = null,
    ): NewsletterProviderException {
        Log::warning('Newsletter: Mailchimp request failed', array_filter([
            'operation' => $operation,
            'failure' => $failure->value,
            'status' => $status,
            'title' => $title,
            'subscriber_hash' => $hash?->value(),
        ], static fn (mixed $value): bool => $value !== null));

        return NewsletterProviderException::because($failure, $operation, $status, $previous);
    }

    private function assertNotImpersonating(string $operation): void
    {
        // Mailchimp is outside the rolled back transaction of an impersonation (core#569)
        if ($this->impersonation->isActive()) {
            throw NewsletterProviderException::because(ConnectionFailure::Impersonation, $operation);
        }
    }

    private function listOrFail(string $operation): string
    {
        $this->assertNotImpersonating($operation);

        if ($this->listId === null) {
            throw NewsletterProviderException::because(ConnectionFailure::NotConfigured, $operation);
        }

        return $this->listId;
    }

    private function memberPath(string $listId, SubscriberHash $hash): string
    {
        return '/lists/'.$listId.'/members/'.$hash->value();
    }

    /**
     * Mailchimp's short error title ("Member Exists", "API Key Invalid"). The detail is never
     * read: it often repeats the address.
     */
    private function title(Response $response): ?string
    {
        $title = $response->json('title');

        return is_string($title) ? mb_substr($title, 0, 100) : null;
    }

    /**
     * @return array<string, string> id => name
     */
    private function lists(Response $response): array
    {
        $lists = [];
        $items = $response->json('lists');

        foreach (is_array($items) ? $items : [] as $item) {
            if (! is_array($item) || ! MailchimpListId::isWellFormed($item['id'] ?? null)) {
                continue;
            }

            $name = $item['name'] ?? null;
            $lists[$item['id']] = is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 255) : $item['id'];
        }

        return $lists;
    }
}
