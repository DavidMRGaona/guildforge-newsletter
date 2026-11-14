/**
 * What the server sends for the subscription form (SubscribeFormProps), the same for the footer,
 * a popup and the /newsletter page.
 */
export interface SubscribeFormProps {
    /** Where the form is sent (`/newsletter`) */
    action: string;
    /** A sentence above the form, chosen with the block */
    intro: string | null;
    /** Null: the default text */
    buttonLabel: string | null;
    showName: boolean;
    /** What the person agrees to by ticking the box */
    consentText: string;
    /** `https://…` or a path of this site */
    privacyUrl: string | null;
    /** The address of whoever is signed in */
    email: string | null;
    name: string | null;
}

/** The fields whose errors the server sends back */
export type SubscribeField = 'email' | 'name' | 'consent';

export type SubscribeResult =
    | { kind: 'success'; message: string | null }
    | { kind: 'invalid'; errors: Partial<Record<SubscribeField, string>> }
    | {
          kind: 'error';
          reason: 'too_many' | 'expired' | 'verify_email' | 'failed';
          message: string | null;
      };
