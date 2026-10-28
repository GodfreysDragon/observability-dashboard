<script setup>
import { computed } from 'vue';
import { signedPercent, signedPoints } from '../lib/format';

/**
 * A change badge. Direction is text (▲/▼ and a sign), so colour is never the only cue.
 */
const props = defineProps({
    value: { type: Number, default: null },
    /** 'relative' (fraction) or 'points' (difference of two rates) or 'absolute' */
    kind: { type: String, default: 'relative' },
    upIsGood: { type: Boolean, default: false },
    /** Below this magnitude the change is shown as neutral. */
    threshold: { type: Number, default: 0.05 },
    format: { type: Function, default: null },
});

const text = computed(() => {
    if (props.value === null || props.value === undefined) return '–';
    if (props.format) return props.format(props.value);
    return props.kind === 'points' ? signedPoints(props.value) : signedPercent(props.value);
});

const tone = computed(() => {
    if (props.value === null || Math.abs(props.value) < props.threshold) return 'text-muted';
    return props.value > 0 === props.upIsGood ? 'text-good' : 'text-bad';
});

const arrow = computed(() => (props.value === null || Math.abs(props.value) < props.threshold ? '' : props.value > 0 ? '▲' : '▼'));
</script>

<template>
    <span class="text-xs whitespace-nowrap tabular-nums" :class="tone"><span v-if="arrow" aria-hidden="true">{{ arrow }} </span>{{ text }}</span>
</template>
