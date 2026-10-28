<script setup>
import { computed } from 'vue';

const props = defineProps({
    sql: { type: String, required: true },
    /** Break before major clauses for long statements. */
    pretty: { type: Boolean, default: true },
});

const KEYWORDS = /\b(select|from|where|and|or|inner join|left join|join|on|group by|order by|limit|offset|insert into|values|update|set|delete from|as|in|is not null|is null|like|case|when|then|else|end|sum|count|coalesce|desc|asc|distinct)\b/gi;
const BREAKS = /\s+(from|where|inner join|left join|group by|order by|limit|values|set)\b/gi;

/** Tokens to render: keywords dimmed-bold, placeholders accented. Text only - no v-html. */
const tokens = computed(() => {
    const text = props.pretty && props.sql.length > 90 ? props.sql.replace(BREAKS, '\n$1') : props.sql;
    const parts = [];
    let last = 0;
    for (const match of text.matchAll(new RegExp(`${KEYWORDS.source}|\\?|\\(\\.\\.\\.\\)`, 'gi'))) {
        if (match.index > last) parts.push({ text: text.slice(last, match.index), kind: 'plain' });
        parts.push({ text: match[0], kind: match[0] === '?' || match[0] === '(...)' ? 'param' : 'keyword' });
        last = match.index + match[0].length;
    }
    if (last < text.length) parts.push({ text: text.slice(last), kind: 'plain' });
    return parts;
});
</script>

<template>
    <pre class="mono overflow-x-auto rounded-lg border border-line bg-surface-2 p-3 whitespace-pre-wrap break-words text-ink-2"><span
        v-for="(t, i) in tokens"
        :key="i"
        :class="t.kind === 'keyword' ? 'font-semibold text-ink' : t.kind === 'param' ? 'text-accent-ink' : ''"
    >{{ t.text }}</span></pre>
</template>
