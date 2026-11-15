<script setup lang="ts">
import { computed } from 'vue';
import { useSiteBlockPlacement } from '@/composables/useSiteBlockPlacement';
import type { SubscribeFormProps } from '../types/subscribe';
import SubscribeForm from './SubscribeForm.vue';

/**
 * Site block `newsletter.subscribe` (core#696), in the footer, inside a popup or on the link page
 * (core#755). The footer is dark in both colour schemes; a popup and the link page follow the page,
 * so the form takes its tone from the placement the core says it is drawn in (core#718), never from
 * the markup around it. In the footer the form is also
 * compact (newsletter#17): the form itself never reads the placement.
 */
defineProps<SubscribeFormProps>();

const placement = useSiteBlockPlacement();
const inFooter = computed(() => placement.value === 'footer');
const tone = computed<'default' | 'dark'>(() => (inFooter.value ? 'dark' : 'default'));
</script>

<template>
    <div data-newsletter-subscribe>
        <SubscribeForm v-bind="$props" :tone="tone" :compact="inFooter" />
    </div>
</template>
