<?php

declare(strict_types=1);

namespace Modules\Newsletter\Application\Services;

use Modules\Newsletter\Application\DTOs\CheckRecording;
use Modules\Newsletter\Application\DTOs\NewsletterSettings;
use Modules\Newsletter\Domain\ValueObjects\ConnectionCheck;
use Modules\Newsletter\Domain\ValueObjects\MailchimpApiKey;

interface NewsletterSettingsRepositoryInterface
{
    /**
     * What is saved; missing or unreadable values come back as null (a key that cannot be
     * decrypted any more included).
     */
    public function get(): NewsletterSettings;

    /**
     * Replace every saved value (the key is encrypted).
     */
    public function save(NewsletterSettings $settings): void;

    /**
     * Write only the last check, as a compare-and-set, and only while it still makes sense: the
     * saved key and list must be the ones that were checked and the saved last check must still be
     * the one the check started from. A check that began before someone saved other settings, or
     * before a newer check (the panel's, saving with a good connection) was written, must not put
     * its answer over them. The saved row is read again under the write lock, straight from its
     * table and never from a cache.
     *
     * @param  ConnectionCheck|null  $startedFrom  The saved last check when the check began
     * @return CheckRecording What was written and which check it replaced (the one that decides
     *                        whether this is a new outage), or a refusal
     *
     * @throws \Throwable When the write lock cannot be taken or the row cannot be read: nothing is written
     */
    public function recordCheck(
        ConnectionCheck $check,
        #[\SensitiveParameter] MailchimpApiKey $checkedKey,
        string $checkedListId,
        ?ConnectionCheck $startedFrom,
    ): CheckRecording;
}
