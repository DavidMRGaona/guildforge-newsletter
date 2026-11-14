<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import SubscribeForm from '../../components/SubscribeForm.vue';
import type { SubscribeFormProps } from '../../types/subscribe';

/**
 * /newsletter: the subscription form on a page of its own (the link the site menu can point to).
 * The server answers 404 while the newsletter is not available, so this page never shows a form that
 * would not reach the list.
 */
defineProps<{
    form: SubscribeFormProps;
}>();

const { t } = useI18n();
</script>

<template>
    <div class="mx-auto max-w-xl px-4 py-12 sm:px-6">
        <h1 class="text-3xl font-bold text-base-primary">
            {{ t('newsletter.subscribe.page_title') }}
        </h1>
        <p class="mt-3 text-base text-base-secondary">{{ t('newsletter.subscribe.page_intro') }}</p>

        <div class="mt-8 rounded-lg bg-surface p-6 shadow-md dark:shadow-none">
            <SubscribeForm v-bind="form" />
        </div>
    </div>
</template>
