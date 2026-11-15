export default {
    newsletter: {
        title: 'Boletín',
        subscribe: {
            page_title: 'Newsletter',
            page_intro:
                'Subscribe and we will email you the news of the association. We will send you a link to confirm the subscription.',
            fields: {
                email: 'Email address',
                name: 'Name',
                trap: 'Leave this field empty',
            },
            optional: 'optional',
            submit: 'Subscribe',
            sending: 'Sending…',
            privacy: 'Privacy policy',
            new_tab: '(opens in a new tab)',
            check_your_email:
                'Check your email: we have sent you a link to confirm the subscription.',
            errors: {
                failed: 'It could not be sent. Try again in a few minutes.',
                too_many: 'Too many attempts. Wait a few minutes and try again.',
                expired: 'The page was open for a long time. Reload it and try again.',
            },
        },
    },
};
