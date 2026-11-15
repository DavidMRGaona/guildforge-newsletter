/** Where the account's address stands on the newsletter list (ProfileNewsletterState) */
export type ProfileNewsletterStatus =
    'subscribed' | 'pending' | 'unsubscribed' | 'blocked' | 'unavailable';

/** The shared page prop of the profile: null when the section is not shown. */
export interface ProfileNewsletter {
    /** Where the section asks and sends (`/perfil/boletin`) */
    endpoint: string;
    /** False: the account's email is not verified, so nothing is asked or offered */
    verified: boolean;
    /** Where to verify it */
    verifyUrl: string;
}

export type ProfileNewsletterResult =
    | { kind: 'status'; status: ProfileNewsletterStatus }
    | { kind: 'error'; reason: 'too_many' | 'expired' | 'verify_email' | 'failed' };
