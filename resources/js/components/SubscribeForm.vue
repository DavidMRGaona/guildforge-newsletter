<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, nextTick, reactive, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '@/components/ui/BaseButton.vue';
import { useVerificationGate } from '@/composables/useVerificationGate';
import type { SubscribeField, SubscribeFormProps } from '../types/subscribe';
import { submitSubscription } from '../utils/subscribe';

/**
 * The newsletter subscription form: in the footer (dark in both schemes), inside a popup and on the
 * /newsletter page. It is sent as JSON, so the page around it stays as it is; the answer is always
 * «check your email». Errors sit under their field and are named by it; the outcome is announced.
 */
const props = withDefaults(
    defineProps<
        SubscribeFormProps & {
            /** `dark` on the footer's background, `default` on the page's own surfaces */
            tone?: 'default' | 'dark';
            /** Footer variant: a one-line email row, small consent text with the policy link inside it */
            compact?: boolean;
        }
    >(),
    { tone: 'default', compact: false }
);

const { t } = useI18n();
const gate = useVerificationGate();

const ids = {
    email: useId(),
    emailError: useId(),
    name: useId(),
    nameError: useId(),
    consent: useId(),
    consentError: useId(),
    trap: useId(),
    gate: useId(),
};

const fields = reactive({
    email: props.email ?? '',
    name: props.name ?? '',
    consent: false,
    website: '',
});
const errors = reactive<Partial<Record<SubscribeField, string>>>({});
const failure = ref<string | null>(null);
const needsVerification = ref(false);
const done = ref<string | null>(null);
const sending = ref(false);

const emailInput = ref<HTMLInputElement | null>(null);
const nameInput = ref<HTMLInputElement | null>(null);
const consentInput = ref<HTMLInputElement | null>(null);
const doneMessage = ref<HTMLElement | null>(null);

const blocked = computed(() => gate.blocked.value || needsVerification.value);
const buttonText = computed(() => props.buttonLabel ?? t('newsletter.subscribe.submit'));
const privacyIsExternal = computed(() => /^https?:\/\//i.test(props.privacyUrl ?? ''));

const dark = computed(() => props.tone === 'dark');
const labelClass = computed(() => (dark.value ? 'text-neutral-200' : 'text-base-secondary'));
const textClass = computed(() => (dark.value ? 'text-neutral-300' : 'text-base-secondary'));
const linkClass = computed(() =>
    dark.value
        ? 'font-medium text-white underline underline-offset-4 hover:text-primary-300'
        : 'font-medium text-primary-700 underline underline-offset-4 hover:text-primary-800 dark:text-primary-300 dark:hover:text-primary-200'
);
const errorClass = computed(() => (dark.value ? 'text-red-300' : 'text-error'));
const checkboxClass = computed(() => [
    'mt-0.5 h-4 w-4 shrink-0 rounded focus:ring-2 focus:ring-primary-500',
    dark.value
        ? 'border-neutral-500 bg-neutral-900 text-primary-600'
        : 'border-neutral-400 text-primary-600',
]);
const consentTextClass = computed(() => [textClass.value, props.compact ? 'text-xs' : 'text-sm']);
// In the compact form the grid puts the button next to the email from `sm`; the email sits in the row
// after the intro, when there is one. Everything else takes the whole row.
const formClass = computed(() =>
    props.compact ? 'grid gap-x-2 gap-y-3 sm:grid-cols-[minmax(0,1fr)_auto]' : 'space-y-4'
);
const full = computed(() => (props.compact ? 'sm:col-span-2' : ''));
const emailRow = computed(() => (props.intro ? 'sm:row-start-2' : 'sm:row-start-1'));
const inputClass = computed(() => [
    'block w-full rounded-md border px-3 py-2 text-sm shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:cursor-not-allowed disabled:opacity-60',
    dark.value
        ? 'border-neutral-600 bg-neutral-900 text-white placeholder:text-neutral-400'
        : 'border-neutral-300 bg-surface text-base-primary placeholder:text-base-muted dark:border-neutral-600',
]);

function clearErrors(): void {
    for (const field of Object.keys(errors) as SubscribeField[]) {
        delete errors[field];
    }
    failure.value = null;
}

function describedBy(field: SubscribeField, errorId: string): string | undefined {
    return errors[field] ? errorId : undefined;
}

async function focusFirstError(): Promise<void> {
    await nextTick();
    const first = errors.email
        ? emailInput.value
        : errors.name
          ? nameInput.value
          : errors.consent
            ? consentInput.value
            : null;
    first?.focus();
}

async function submit(): Promise<void> {
    if (sending.value || blocked.value) {
        return;
    }

    clearErrors();
    sending.value = true;

    const result = await submitSubscription(props.action, {
        email: fields.email.trim(),
        name: props.showName ? fields.name.trim() : '',
        consent: fields.consent,
        website: fields.website,
    });

    sending.value = false;

    if (result.kind === 'success') {
        done.value = result.message ?? t('newsletter.subscribe.check_your_email');
        await nextTick();
        // The form is gone: the message takes the focus, so it is read and nothing is lost
        doneMessage.value?.focus();

        return;
    }

    if (result.kind === 'invalid') {
        Object.assign(errors, result.errors);
        await focusFirstError();

        return;
    }

    if (result.reason === 'verify_email') {
        needsVerification.value = true;

        return;
    }

    failure.value =
        result.message ??
        (result.reason === 'expired'
            ? t('newsletter.subscribe.errors.expired')
            : result.reason === 'too_many'
              ? t('newsletter.subscribe.errors.too_many')
              : t('newsletter.subscribe.errors.failed'));
}
</script>

<template>
    <div class="relative">
        <p
            v-if="done"
            ref="doneMessage"
            role="status"
            tabindex="-1"
            data-subscribe-done
            class="rounded-md text-sm focus:outline-none"
            :class="dark ? 'text-neutral-100' : 'text-base-primary'"
        >
            {{ done }}
        </p>

        <form
            v-else
            :action="action"
            method="post"
            novalidate
            :class="formClass"
            :aria-busy="sending ? 'true' : undefined"
            @submit.prevent="submit"
        >
            <p v-if="intro" class="text-sm" :class="[textClass, full]">{{ intro }}</p>

            <div :class="compact ? ['sm:col-start-1', emailRow] : undefined">
                <label
                    :for="ids.email"
                    :class="compact ? 'sr-only' : ['mb-1 block text-sm font-medium', labelClass]"
                >
                    {{ t('newsletter.subscribe.fields.email') }}
                    <span aria-hidden="true">*</span>
                </label>
                <input
                    :id="ids.email"
                    ref="emailInput"
                    v-model="fields.email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    inputmode="email"
                    required
                    maxlength="254"
                    :placeholder="compact ? t('newsletter.subscribe.fields.email') : undefined"
                    :disabled="sending"
                    :aria-invalid="errors.email ? 'true' : undefined"
                    :aria-describedby="describedBy('email', ids.emailError)"
                    :class="inputClass"
                />
                <p
                    v-if="errors.email"
                    :id="ids.emailError"
                    class="mt-1 text-sm"
                    :class="errorClass"
                >
                    {{ errors.email }}
                </p>
            </div>

            <div v-if="showName" :class="full">
                <label :for="ids.name" class="mb-1 block text-sm font-medium" :class="labelClass">
                    {{ t('newsletter.subscribe.fields.name') }}
                    <span class="font-normal">({{ t('newsletter.subscribe.optional') }})</span>
                </label>
                <input
                    :id="ids.name"
                    ref="nameInput"
                    v-model="fields.name"
                    type="text"
                    name="name"
                    autocomplete="given-name"
                    maxlength="100"
                    :disabled="sending"
                    :aria-invalid="errors.name ? 'true' : undefined"
                    :aria-describedby="describedBy('name', ids.nameError)"
                    :class="inputClass"
                />
                <p v-if="errors.name" :id="ids.nameError" class="mt-1 text-sm" :class="errorClass">
                    {{ errors.name }}
                </p>
            </div>

            <!-- Trap field: out of sight and out of the accessibility tree; people leave it empty -->
            <div class="absolute -left-[10000px] h-px w-px overflow-hidden" aria-hidden="true">
                <label :for="ids.trap">{{ t('newsletter.subscribe.fields.trap') }}</label>
                <input
                    :id="ids.trap"
                    v-model="fields.website"
                    type="text"
                    name="website"
                    tabindex="-1"
                    autocomplete="off"
                />
            </div>

            <div :class="full">
                <div v-if="compact" class="flex items-start gap-2">
                    <input
                        :id="ids.consent"
                        ref="consentInput"
                        v-model="fields.consent"
                        type="checkbox"
                        name="consent"
                        required
                        :disabled="sending"
                        :aria-invalid="errors.consent ? 'true' : undefined"
                        :aria-describedby="describedBy('consent', ids.consentError)"
                        :class="checkboxClass"
                    />
                    <p :class="consentTextClass" data-consent-text>
                        <label :for="ids.consent">
                            {{ consentText }}
                            <span aria-hidden="true">*</span>
                        </label>
                        <template v-if="privacyUrl">
                            <span aria-hidden="true"> · </span>
                            <a
                                v-if="privacyIsExternal"
                                :href="privacyUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                :class="linkClass"
                            >
                                {{ t('newsletter.subscribe.privacy') }}
                                <span class="sr-only">{{ t('newsletter.subscribe.new_tab') }}</span>
                            </a>
                            <Link v-else :href="privacyUrl" :class="linkClass">
                                {{ t('newsletter.subscribe.privacy') }}
                            </Link>
                        </template>
                    </p>
                </div>
                <div v-else class="flex items-start gap-2">
                    <input
                        :id="ids.consent"
                        ref="consentInput"
                        v-model="fields.consent"
                        type="checkbox"
                        name="consent"
                        required
                        :disabled="sending"
                        :aria-invalid="errors.consent ? 'true' : undefined"
                        :aria-describedby="describedBy('consent', ids.consentError)"
                        :class="checkboxClass"
                    />
                    <label :for="ids.consent" class="text-sm" :class="textClass">
                        {{ consentText }}
                        <span aria-hidden="true">*</span>
                    </label>
                </div>
                <p v-if="privacyUrl && !compact" class="mt-1 pl-6 text-sm" :class="textClass">
                    <a
                        v-if="privacyIsExternal"
                        :href="privacyUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        :class="linkClass"
                    >
                        {{ t('newsletter.subscribe.privacy') }}
                        <span class="sr-only">{{ t('newsletter.subscribe.new_tab') }}</span>
                    </a>
                    <Link v-else :href="privacyUrl" :class="linkClass">
                        {{ t('newsletter.subscribe.privacy') }}
                    </Link>
                </p>
                <p
                    v-if="errors.consent"
                    :id="ids.consentError"
                    class="mt-1 pl-6 text-sm"
                    :class="errorClass"
                >
                    {{ errors.consent }}
                </p>
            </div>

            <p v-if="blocked" :id="ids.gate" class="text-sm" :class="[textClass, full]">
                {{ gate.reason('form') }}
                <Link :href="gate.noticeUrl" :class="linkClass">
                    {{ gate.linkLabel.value }} <span aria-hidden="true">→</span>
                </Link>
            </p>

            <p v-if="failure" role="alert" class="text-sm" :class="[errorClass, full]">
                {{ failure }}
            </p>

            <div
                :class="
                    compact
                        ? [
                              'sm:col-start-2 sm:self-start [&>button]:w-full sm:[&>button]:w-auto',
                              emailRow,
                          ]
                        : undefined
                "
            >
                <BaseButton
                    type="submit"
                    :size="compact ? 'sm' : 'md'"
                    :loading="sending"
                    :disabled="blocked"
                    :aria-describedby="blocked ? ids.gate : undefined"
                >
                    {{ buttonText }}
                </BaseButton>
            </div>

            <p role="status" class="sr-only">
                {{ sending ? t('newsletter.subscribe.sending') : '' }}
            </p>
        </form>
    </div>
</template>
