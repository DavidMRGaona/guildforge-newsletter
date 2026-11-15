<script setup lang="ts">
import { computed } from 'vue';
import { useSiteBlockPlacement } from '@/composables/useSiteBlockPlacement';
import type { SubscribeFormProps } from '../types/subscribe';
import SubscribeForm from './SubscribeForm.vue';

/**
 * Site block `newsletter.subscribe` (core#696), in the footer or inside a popup. The footer is dark in
 * both colour schemes and a popup follows the page, so the form takes its tone from the placement the
 * core says it is drawn in (core#718), never from the markup around it.
 */
defineProps<SubscribeFormProps>();

const placement = useSiteBlockPlacement();
const tone = computed<'default' | 'dark'>(() => (placement.value === 'footer' ? 'dark' : 'default'));
</script>

<template>
    <div data-newsletter-subscribe>
        <SubscribeForm v-bind="$props" :tone="tone" />
    </div>
</template>
