export default {
    newsletter: {
        title: 'Boletín',
        profile: {
            status: {
                loading: 'Checking your subscription…',
                subscribed: 'You are subscribed to the newsletter with your account email.',
                pending:
                    'We have sent you an email to confirm the subscription. You will not receive the newsletter until you open the link.',
                unsubscribed: 'You are not subscribed to the newsletter.',
                blocked:
                    'That address cannot receive the newsletter: the email service has marked it as invalid. Change your account email if you want to receive it.',
                unavailable: 'We could not check your subscription right now.',
            },
            verify: 'To manage the newsletter from here, verify your account email first.',
            verify_link: 'Verify my email',
            tabLabel: 'Newsletter',
            title: 'Newsletter',
            subscribe: 'Subscribe',
            unsubscribe: 'Unsubscribe',
            retry: 'Try again',
            confirmation_note:
                'We use your account email. When you subscribe we will send you a link to confirm.',
            errors: {
                failed: 'It could not be completed. Try again in a few minutes.',
                too_many: 'Too many attempts. Wait a few minutes and try again.',
                expired: 'The page has been open for too long. Reload it and try again.',
                verify_email: 'Verify your account email to manage the subscription.',
            },
        },
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
