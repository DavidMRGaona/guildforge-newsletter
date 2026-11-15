<script setup lang="ts">
import { nextTick, onMounted, ref, useTemplateRef } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import BaseButton from '@/components/ui/BaseButton.vue';
import type {
    ProfileNewsletter,
    ProfileNewsletterResult,
    ProfileNewsletterStatus,
} from '../../types/profile';
import { requestProfileNewsletter } from '../../utils/profile';

const props = defineProps<{
    /** Null while the newsletter is not available (the tab is hidden then). */
    profileNewsletter: ProfileNewsletter | null;
    /** The tab's visibility, read by the core: declared so it stays off the markup. */
    profileNewsletterVisible?: boolean | null;
}>();

const { t } = useI18n();

/** `loading` until the first answer; an error keeps the last known status out of sight. */
const status = ref<ProfileNewsletterStatus | 'loading'>('loading');
const failure = ref<'too_many' | 'expired' | 'verify_email' | 'failed' | null>(null);
const sending = ref(false);
/** The line that tells the status: it takes the focus when the pressed button goes away. */
const statusLine = useTemplateRef<HTMLElement>('statusLine');

const LINK_CLASS =
    'rounded-sm font-medium text-primary underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 focus-visible:ring-offset-page';

function apply(result: ProfileNewsletterResult): void {
    if (result.kind === 'status') {
        status.value = result.status;
        failure.value = null;

        return;
    }

    failure.value = result.reason;

    if (status.value === 'loading') {
        status.value = 'unavailable';
    }
}

async function load(): Promise<void> {
    if (props.profileNewsletter === null || !props.profileNewsletter.verified) {
        return;
    }

    status.value = 'loading';
    apply(await requestProfileNewsletter(props.profileNewsletter.endpoint, 'GET'));
}

async function change(method: 'POST' | 'DELETE'): Promise<void> {
    if (props.profileNewsletter === null || sending.value) {
        return;
    }

    sending.value = true;
    apply(await requestProfileNewsletter(props.profileNewsletter.endpoint, method));
    sending.value = false;

    // The pressed button is replaced by the new state: keep the focus on something that stays
    await nextTick();
    statusLine.value?.focus();
}

onMounted(load);
</script>

<template>
    <section
        v-if="profileNewsletter"
        class="space-y-4"
        data-testid="profile-newsletter"
        :aria-busy="status === 'loading' || sending"
    >
        <h2 class="text-xl font-semibold text-base-primary">
            {{ t('newsletter.profile.title') }}
        </h2>

        <div class="space-y-3 rounded-lg border border-default bg-surface p-4">
            <p
                v-if="!profileNewsletter.verified"
                class="text-base-primary"
                data-testid="newsletter-verify"
            >
                {{ t('newsletter.profile.verify') }}
                <Link :href="profileNewsletter.verifyUrl" :class="LINK_CLASS">
                    {{ t('newsletter.profile.verify_link') }}
                </Link>
            </p>

            <template v-else>
                <!-- A live region: the answer to a press is announced without moving the page -->
                <p
                    ref="statusLine"
                    tabindex="-1"
                    role="status"
                    aria-live="polite"
                    class="text-base-primary focus:outline-none"
                    data-testid="newsletter-status"
                >
                    {{ t(`newsletter.profile.status.${status}`) }}
                </p>

                <BaseButton
                    v-if="status === 'subscribed'"
                    variant="outline"
                    :loading="sending"
                    @click="change('DELETE')"
                >
                    {{ t('newsletter.profile.unsubscribe') }}
                </BaseButton>

                <template v-else-if="status === 'unsubscribed'">
                    <BaseButton :loading="sending" @click="change('POST')">
                        {{ t('newsletter.profile.subscribe') }}
                    </BaseButton>
                    <p class="text-sm text-base-secondary">
                        {{ t('newsletter.profile.confirmation_note') }}
                    </p>
                </template>

                <BaseButton v-else-if="status === 'unavailable'" variant="outline" @click="load">
                    {{ t('newsletter.profile.retry') }}
                </BaseButton>

                <p v-if="failure" role="alert" class="text-sm text-red-700 dark:text-red-400">
                    {{ t(`newsletter.profile.errors.${failure}`) }}
                </p>
            </template>
        </div>
    </section>
</template>
