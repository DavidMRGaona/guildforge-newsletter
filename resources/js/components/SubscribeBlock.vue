<script setup lang="ts">
import { onMounted, ref } from 'vue';
import type { SubscribeFormProps } from '../types/subscribe';
import SubscribeForm from './SubscribeForm.vue';

/**
 * Site block `newsletter.subscribe` (core#696), in the footer or inside a popup. The footer is dark in
 * both colour schemes and a popup follows the page, so the form takes its tone from where it is drawn.
 * The core does not tell a block its placement yet: the configurable footer marks each of its
 * columns with `data-footer-column`, so only that mark (never any `<footer>`, which a popup or an
 * article may hold) makes it dark.
 */
defineProps<SubscribeFormProps>();

const root = ref<HTMLElement | null>(null);
const tone = ref<'default' | 'dark'>('default');

onMounted(() => {
    // Before the first paint, so the form never flashes in the wrong colours
    tone.value = root.value?.closest('[data-footer-column]') ? 'dark' : 'default';
});
</script>

<template>
    <div ref="root" data-newsletter-subscribe>
        <SubscribeForm v-bind="$props" :tone="tone" />
    </div>
</template>
