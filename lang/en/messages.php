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
            'ok_description' => 'The connection is checked again every day.',
            'failed_hidden' => 'The newsletter is hidden from the site while the connection does not work. It is checked again every day and when the settings are saved.',
            'refused_streak' => 'Mailchimp has refused or failed :count sign-ups in a row',
            'refused_streak_description' => 'The connection works, so the newsletter is still shown. Review the required fields of the audience in Mailchimp.',
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

    'provider_down' => [
        'section' => 'Newsletter',
        'label' => 'Notice that Mailchimp stopped working',
        'description' => 'Notice, for whoever configures the newsletter, when Mailchimp stops accepting the key or the list and the newsletter is hidden from the site.',
        'notice' => 'The newsletter is hidden: the connection with Mailchimp failed',
        'mail' => [
            'subject' => 'The newsletter is hidden: the connection with Mailchimp failed',
            'greeting' => 'Hello, :name',
            'body' => 'The check of the connection with Mailchimp failed and the newsletter no longer appears on the site.',
            'hint' => 'Review the key and the list in the newsletter settings and save them: if the connection works, the newsletter appears again. It is also checked again every day.',
            'action' => 'Open the newsletter settings',
        ],
        'refused_sign_ups' => [
            'notice' => 'Mailchimp is refusing newsletter sign-ups',
            'reason' => 'Mailchimp refuses sign-ups although the connection works: review the required fields of the audience.',
            'mail' => [
                'subject' => 'Mailchimp is refusing newsletter sign-ups',
                'body' => 'The connection with Mailchimp works, but several sign-ups in a row have been refused. The newsletter is still shown on the site.',
                'hint' => 'This usually happens when the Mailchimp list has required fields (in the audience) that the form does not ask for. Review those fields in Mailchimp and try a test sign-up.',
            ],
        ],
    ],

    'block' => [
        'label' => 'Newsletter sign-up',
        'button_default' => 'Subscribe',
        'fields' => [
            'intro' => 'Introduction',
            'button_label' => 'Button text',
            'show_name' => 'Ask for the name',
        ],
        'helpers' => [
            'intro' => 'A sentence about the newsletter, above the form. Optional.',
            'show_name' => 'Adds an optional field for the name.',
        ],
    ],

    'subscribe' => [
        'page_title' => 'Newsletter',
        'page_description' => 'Subscribe to the association newsletter and get the news by email.',
        'check_your_email' => 'Check your email: we have sent you a link to confirm the subscription.',
        'fields' => [
            'email' => 'email address',
            'name' => 'name',
        ],
        'errors' => [
            'consent' => 'Tick the box to agree to receive the newsletter.',
            'too_many_attempts' => 'Too many attempts. Wait a few minutes and try again.',
        ],
    ],

    'registration' => [
        'label' => 'I want to receive the newsletter',
        'description' => 'We will email you to confirm the subscription. You can unsubscribe whenever you like.',
    ],

    'profile' => [
        'errors' => [
            'verify_email' => 'Verify your account email to manage the subscription.',
            'too_many_attempts' => 'Too many attempts. Wait a few minutes and try again.',
        ],
    ],
];
