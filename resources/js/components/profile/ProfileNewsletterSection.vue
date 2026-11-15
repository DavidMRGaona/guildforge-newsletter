<script setup lang="ts">
import { onMounted, ref } from 'vue';
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
    if (props.profileNewsletter === null) {
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
            <p v-if="status === 'loading'" class="text-sm text-base-secondary" role="status">
                {{ t('newsletter.profile.loading') }}
            </p>

            <template v-else-if="status === 'subscribed'">
                <p class="text-base-primary" data-testid="newsletter-status">
                    {{ t('newsletter.profile.subscribed') }}
                </p>
                <BaseButton variant="outline" :loading="sending" @click="change('DELETE')">
                    {{ t('newsletter.profile.unsubscribe') }}
                </BaseButton>
            </template>

            <p
                v-else-if="status === 'pending'"
                class="text-base-primary"
                data-testid="newsletter-status"
            >
                {{ t('newsletter.profile.pending') }}
            </p>

            <template v-else-if="status === 'unsubscribed'">
                <p class="text-base-primary" data-testid="newsletter-status">
                    {{ t('newsletter.profile.unsubscribed') }}
                </p>
                <BaseButton :loading="sending" @click="change('POST')">
                    {{ t('newsletter.profile.subscribe') }}
                </BaseButton>
            </template>

            <p
                v-else-if="status === 'blocked'"
                class="text-base-primary"
                data-testid="newsletter-status"
            >
                {{ t('newsletter.profile.blocked') }}
            </p>

            <template v-else>
                <p class="text-base-primary" data-testid="newsletter-status">
                    {{ t('newsletter.profile.unavailable') }}
                </p>
                <BaseButton variant="outline" @click="load">
                    {{ t('newsletter.profile.retry') }}
                </BaseButton>
            </template>

            <p v-if="failure" role="alert" class="text-sm text-red-700 dark:text-red-400">
                {{ t(`newsletter.profile.errors.${failure}`) }}
            </p>

            <p v-if="status === 'unsubscribed'" class="text-sm text-base-secondary">
                {{ t('newsletter.profile.confirmation_note') }}
            </p>
        </div>
    </section>
</template>
