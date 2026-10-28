<script setup>
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, setCsrfToken } from '../lib/api';
import { state } from '../lib/store';

const route = useRoute();
const router = useRouter();
const form = reactive({ email: 'admin@example.com', password: '', remember: true });
const error = ref('');
const busy = ref(false);

async function submit() {
    busy.value = true;
    error.value = '';
    try {
        const { user, csrf_token } = await api.post('/login', form);
        setCsrfToken(csrf_token);
        state.user = user;
        router.replace(typeof route.query.next === 'string' && route.query.next.startsWith('/') ? route.query.next : { name: 'overview' });
    } catch (e) {
        error.value = e.errors?.email?.[0] ?? e.message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="flex min-h-full items-center justify-center px-4 py-12">
        <form class="card w-full max-w-sm space-y-4 p-6" @submit.prevent="submit">
            <div>
                <h1 class="text-lg font-semibold text-ink">Ledgerline Observability</h1>
                <p class="mt-1 text-sm text-ink-2">Performance and reliability for the Ledgerline app.</p>
            </div>
            <label class="block space-y-1">
                <span class="label">Email</span>
                <input v-model="form.email" type="email" required autocomplete="username" class="field w-full" />
            </label>
            <label class="block space-y-1">
                <span class="label">Password</span>
                <input v-model="form.password" type="password" required autocomplete="current-password" class="field w-full" />
            </label>
            <p v-if="error" class="text-sm text-bad" role="alert">{{ error }}</p>
            <button type="submit" class="btn btn-primary w-full justify-center" :disabled="busy">{{ busy ? 'Signing in…' : 'Sign in' }}</button>
            <p class="text-xs text-muted">Demo account: admin@example.com / password (after <code>php artisan db:seed</code>).</p>
        </form>
    </div>
</template>
