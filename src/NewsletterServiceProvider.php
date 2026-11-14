<?php

declare(strict_types=1);

namespace Modules\Newsletter;

use App\Application\Modules\DTOs\PagePrefixDTO;
use App\Application\Modules\DTOs\PermissionDTO;
use App\Modules\ModuleServiceProvider;

/**
 * Newsletter of the association: subscription to a Mailchimp list from the web.
 *
 * This is the skeleton: it declares the permission and the page prefix and nothing else yet.
 */
final class NewsletterServiceProvider extends ModuleServiceProvider
{
    public function moduleName(): string
    {
        return 'newsletter';
    }

    /**
     * Stored as "newsletter:newsletter.configure". No role gets it by default: an admin ticks it
     * in the role editor. Leave out "editor": module permissions go to the roles each association
     * creates (tests/Unit/PermissionSuggestionsTest.php).
     *
     * @return array<PermissionDTO>
     */
    public function registerPermissions(): array
    {
        return [
            new PermissionDTO(
                name: 'newsletter.configure',
                label: __('newsletter::messages.permissions.configure'),
                group: __('newsletter::messages.title'),
                module: $this->moduleName(),
            ),
        ];
    }

    /**
     * Module Vue pages are written to resources/js/pages/Newsletter/ and rendered as
     * "Newsletter/<Page>", inside the core's layout.
     *
     * @return array<PagePrefixDTO>
     */
    public function registerPagePrefixes(): array
    {
        return [
            new PagePrefixDTO(prefix: 'Newsletter', module: $this->moduleName(), usesCoreLayout: true),
        ];
    }
}
