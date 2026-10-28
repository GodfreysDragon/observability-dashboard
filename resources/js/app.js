import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import App from './App.vue';
import { api, handleUnauthorized } from './lib/api';
import { state } from './lib/store';

const routes = [
    { path: '/login', name: 'login', component: () => import('./pages/LoginPage.vue'), meta: { guest: true, title: 'Sign in' } },
    { path: '/', name: 'overview', component: () => import('./pages/OverviewPage.vue'), meta: { title: 'Overview' } },
    { path: '/routes', name: 'routes', component: () => import('./pages/RoutesPage.vue'), meta: { title: 'Routes' } },
    { path: '/routes/detail', name: 'route', component: () => import('./pages/RoutePage.vue'), meta: { title: 'Route', section: 'routes' } },
    { path: '/traces', name: 'traces', component: () => import('./pages/TracesPage.vue'), meta: { title: 'Traces' } },
    { path: '/traces/:kind(requests|jobs)/:id(\\d+)', name: 'trace', component: () => import('./pages/TracePage.vue'), meta: { title: 'Trace', section: 'traces', noRange: true } },
    { path: '/queries', name: 'queries', component: () => import('./pages/QueriesPage.vue'), meta: { title: 'Queries' } },
    { path: '/queries/:fingerprint([0-9a-f]{16})', name: 'query', component: () => import('./pages/QueryPage.vue'), meta: { title: 'Query', section: 'queries' } },
    { path: '/exceptions', name: 'exceptions', component: () => import('./pages/ExceptionsPage.vue'), meta: { title: 'Exceptions' } },
    { path: '/exceptions/:id(\\d+)', name: 'exception', component: () => import('./pages/ExceptionPage.vue'), meta: { title: 'Exception', section: 'exceptions' } },
    { path: '/queues', name: 'queues', component: () => import('./pages/QueuesPage.vue'), meta: { title: 'Queues' } },
    { path: '/deploys', name: 'deploys', component: () => import('./pages/DeploysPage.vue'), meta: { title: 'Deploys', noRange: true } },
    { path: '/:pathMatch(.*)*', redirect: '/' },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: (to, from, saved) => saved ?? (to.path !== from.path ? { top: 0 } : undefined),
});

router.beforeEach((to) => {
    if (state.user === null && !to.meta.guest) return { name: 'login', query: to.fullPath !== '/' ? { next: to.fullPath } : {} };
    if (state.user && to.meta.guest) return { name: 'overview' };
});

router.afterEach((to) => {
    document.title = `${to.meta.title ?? 'Dashboard'} · Ledgerline Observability`;
});

handleUnauthorized(() => {
    if (state.user === null) return;
    state.user = null;
    router.push({ name: 'login' });
});

// Resolve the session before the router's first navigation so guards know who is signed in.
try {
    state.user = (await api.get('/me')).user;
} catch {
    state.user = null;
}

createApp(App).use(router).mount('#app');
