<script setup>
import { computed } from 'vue';

/**
 * State indicator: reserved status colour + a shape + a text label, never colour alone.
 */
const props = defineProps({
    /** ok | warning | serious | critical | neutral */
    status: { type: String, required: true },
    label: { type: String, default: '' },
});

const styles = {
    ok: { color: 'var(--status-good)', icon: '●' },
    warning: { color: 'var(--status-warning)', icon: '▲' },
    serious: { color: 'var(--status-serious)', icon: '▲' },
    critical: { color: 'var(--status-critical)', icon: '■' },
    neutral: { color: 'var(--series-other)', icon: '○' },
};

const style = computed(() => styles[props.status] ?? styles.neutral);
</script>

<template>
    <span class="inline-flex items-center gap-1.5 text-xs font-medium whitespace-nowrap text-ink-2">
        <span :style="{ color: style.color }" aria-hidden="true" class="text-[10px]">{{ style.icon }}</span>
        <slot>{{ label }}</slot>
    </span>
</template>
