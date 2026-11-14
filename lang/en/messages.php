<?php

declare(strict_types=1);

return [
    'title' => 'Newsletter',

    'permissions' => [
        'configure' => 'Configure the newsletter',
    ],

    'settings' => [
        'navigation' => 'Newsletter',
        'title' => 'Newsletter',
        'save' => 'Save',
        'test_connection' => 'Test connection',

        'connection' => 'Mailchimp connection',
        'connection_description' => 'The site keeps no subscribers: every sign-up goes to the Mailchimp list, which sends the confirmation email. Campaigns are still prepared in Mailchimp.',
        'consent' => 'Consent',
        'consent_description' => 'What people accept when they subscribe on the site.',

        'fields' => [
            'provider' => 'Provider',
            'api_key' => 'API key',
            'server' => 'Server',
            'list' => 'List',
            'consent_text' => 'Consent text',
            'privacy_url' => 'Link to the privacy policy',
        ],

        'helpers' => [
            'api_key' => 'Created in Mailchimp, under Profile → Extras → API keys.',
            'api_key_stored' => 'A key is saved and is never shown again. Leave this empty to keep it or type another one to change it.',
            'server' => 'Taken from the key: whatever follows the last dash.',
            'list' => 'Press «Test connection» to load the lists of the account.',
            'consent_text' => 'Shown next to the box that must be ticked to subscribe.',
            'privacy_url' => 'A full address (https://…) or a path of this site (/privacy).',
        ],

        'consent_default' => 'I want to receive the newsletter by email. I can unsubscribe at any time from the link in every issue.',

        'errors' => [
            'api_key_required' => 'Type the API key.',
            'api_key_malformed' => 'This does not look like a Mailchimp key: it ends with a dash and the server (for example, -us21).',
            'privacy_url' => 'Type an address starting with https:// or a path of this site starting with /.',
        ],

        'last_check' => [
            'never' => 'The connection has not been checked yet',
            'never_description' => 'The newsletter does not appear on the site until the settings are saved with a working connection.',
            'ok' => 'Connection working (checked on :date)',
            'failed' => 'The last check failed (:date)',
        ],

        'failures' => [
            'unreachable' => 'Mailchimp does not answer. Try again in a few minutes.',
            'invalid_api_key' => 'Mailchimp does not accept the API key.',
            'list_not_found' => 'No list is chosen, or it no longer exists in the account.',
            'request_failed' => 'Mailchimp refused the request.',
            'not_configured' => 'The API key or the list is missing.',
            'impersonation' => 'Mailchimp is not called while you impersonate another account.',
        ],

        'notifications' => [
            'connected' => 'Connection working',
            'choose_list' => 'Choose the list and save.',
            'not_connected' => 'Could not connect',
            'saved' => 'Settings saved',
            'saved_available' => 'The connection works: the newsletter can now appear on the site.',
            'saved_unavailable' => 'Settings saved without a connection',
            'hidden_until_connected' => 'The newsletter does not appear on the site until the connection works.',
        ],
    ],
];
