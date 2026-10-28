/**
 * Browser smoke test against a running, seeded app:
 *
 *   php artisan migrate:fresh --seed && php artisan serve --port=8050
 *   E2E_URL=http://127.0.0.1:8050 npm run test:e2e
 *
 * Signs in, then visits every page (and a drill-down from each list) in light mode,
 * dark mode and at phone width. Fails on page errors, console errors, failed API calls,
 * horizontal overflow, or a page that never renders its content. Uses an installed
 * Edge or Chrome (E2E_BROWSER=msedge|chrome) via playwright-core; screenshots go to storage/e2e.
 */
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright-core';

const BASE = process.env.E2E_URL ?? 'http://127.0.0.1:8050';
const OUT = 'storage/e2e';
mkdirSync(OUT, { recursive: true });

const results = [];
let failed = false;

async function step(name, fn) {
    const started = Date.now();
    try {
        await fn();
        results.push(`PASS ${name} (${Date.now() - started}ms)`);
    } catch (e) {
        failed = true;
        results.push(`FAIL ${name}: ${e.message.split('\n')[0]}`);
    }
}

const browser = await chromium.launch({ channel: process.env.E2E_BROWSER ?? 'msedge', headless: true });

const MODES = [
    { name: 'light', viewport: { width: 1440, height: 900 }, colorScheme: 'light' },
    { name: 'dark', viewport: { width: 1440, height: 900 }, colorScheme: 'dark' },
    { name: 'mobile', viewport: { width: 390, height: 844 }, colorScheme: 'light', isMobile: true },
];

/** [label, path, text that proves the page rendered data] */
const PAGES = [
    ['overview', '/', 'Error budget remaining'],
    ['routes', '/routes', 'Time share'],
    ['traces', '/traces', 'Kept because'],
    ['queries', '/queries', 'of DB time'],
    ['exceptions', '/exceptions', 'ErrorException'],
    ['queues', '/queues', 'Recent failures and retries'],
    ['deploys', '/deploys', 'v3.15.0'],
];

/** Drill-downs: [label, list path, link selector, text on the detail page] */
const DRILLS = [
    ['route-detail', '/routes', 'a[href^="/routes/detail"]', 'Latency distribution'],
    ['n-plus-one-trace', '/traces?reason=n_plus_one', 'a[href^="/traces/requests/"]', 'Likely N+1'],
    ['query-detail', '/queries', 'a[href^="/queries/"]', 'Call sites'],
    ['exception-detail', '/exceptions', 'a[href^="/exceptions/"]', 'Latest stack trace'],
    ['job-trace', '/queues', 'a[href^="/traces/jobs/"]', 'Waited in queue'],
];

for (const mode of MODES) {
    const context = await browser.newContext({ viewport: mode.viewport, colorScheme: mode.colorScheme, isMobile: mode.isMobile ?? false });
    const page = await context.newPage();
    const problems = [];

    page.on('pageerror', (e) => problems.push(`page error: ${e.message}`));
    page.on('console', (m) => m.type() === 'error' && problems.push(`console: ${m.text()}`));
    page.on('response', (r) => r.url().includes('/api/admin/') && r.status() >= 400 && !r.url().endsWith('/me') && problems.push(`HTTP ${r.status()} ${r.url()}`));

    const check = async (label) => {
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        if (overflow > 1) throw new Error(`horizontal overflow of ${overflow}px`);
        if (problems.length) throw new Error(problems.splice(0).join(' | '));
        await page.screenshot({ path: `${OUT}/${mode.name}-${label}.png`, fullPage: true });
    };

    await step(`[${mode.name}] sign in`, async () => {
        await page.goto(BASE);
        await page.fill('input[type=password]', 'password');
        await page.click('button[type=submit]');
        await page.waitForSelector('text=Error budget remaining', { timeout: 15000 });
    });

    for (const [label, path, proof] of PAGES) {
        await step(`[${mode.name}] ${label}`, async () => {
            await page.goto(BASE + path);
            await page.waitForSelector(`text=${proof}`, { timeout: 15000 });
            await page.waitForLoadState('networkidle');
            await check(label);
        });
    }

    for (const [label, path, selector, proof] of DRILLS) {
        await step(`[${mode.name}] ${label}`, async () => {
            await page.goto(BASE + path);
            await page.waitForSelector(selector, { timeout: 15000 });
            await page.locator(selector).first().click();
            await page.waitForSelector(`text=${proof}`, { timeout: 15000 });
            await page.waitForLoadState('networkidle');
            await check(label);
        });
    }

    await step(`[${mode.name}] range switch to 7d`, async () => {
        await page.goto(BASE + '/');
        await page.waitForSelector('text=Error budget remaining');
        await page.click('button[aria-pressed]:has-text("7d")');
        await page.waitForLoadState('networkidle');
        await check('overview-7d');
        await page.click('button[aria-pressed]:has-text("24h")');
    });

    await context.close();
}

await browser.close();
console.log(results.join('\n'));
process.exit(failed ? 1 : 0);
