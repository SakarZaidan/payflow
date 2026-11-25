const { createRequire } = require('node:module');
const { resolve } = require('node:path');
const toolRequire = createRequire(resolve('artifacts/browser/package.json'));
const { chromium } = toolRequire('playwright');
const { AxeBuilder } = toolRequire('@axe-core/playwright');
const { mkdirSync, readFileSync } = require('node:fs');
const assert = require('node:assert/strict');

const base = process.env.PAYFLOW_QA_URL || 'http://127.0.0.1:8080';
const password = readFileSync('.env', 'utf8').match(/^PAYFLOW_OPERATOR_PASSWORD=(.+)$/m)[1];

async function audit(page, label) {
    await page.evaluate(() => document.fonts.ready);
    const { violations } = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    assert.deepEqual(violations.map(v => [v.id, v.nodes.map(n => n.target)]), [], label);
    assert(await page.evaluate(() => document.documentElement.scrollWidth === innerWidth), label + ' has horizontal overflow');
}

async function capture(page, name, fullPage = true) {
    if (process.env.PAYFLOW_CAPTURE !== '1') return;
    mkdirSync('docs/screenshots', { recursive: true });
    await page.screenshot({ path: 'docs/screenshots/' + name + '.png', fullPage });
}

async function login(page) {
    await page.goto(base);
    assert.equal(new URL(page.url()).pathname, '/login');
    await page.getByLabel('Console password').fill(password);
    await Promise.all([page.waitForURL(base + '/'), page.getByRole('button', { name: 'Sign in' }).click()]);
}

(async () => {
    const browser = await chromium.launch();
    try {
        const desktop = await (await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' })).newPage();
        await login(desktop);
        await audit(desktop, 'Desktop payments');
        await capture(desktop, 'payments');
        await desktop.keyboard.press('Tab');
        assert.equal(await desktop.evaluate(() => document.activeElement.textContent.trim()), 'Skip to content');

        await desktop.getByLabel('Status').selectOption('declined');
        await Promise.all([desktop.waitForURL(/state=declined/), desktop.getByRole('button', { name: 'Apply' }).click()]);
        assert.equal(await desktop.locator('tbody .declined').count(), 2);
        await desktop.getByLabel('Method').selectOption('card');
        await Promise.all([desktop.waitForURL(/rail=card/), desktop.getByRole('button', { name: 'Apply' }).click()]);
        assert.equal(await desktop.locator('tbody .declined').count(), 1);
        await desktop.getByLabel('Status').selectOption('needs_review');
        await Promise.all([desktop.waitForURL(/state=needs_review/), desktop.getByRole('button', { name: 'Apply' }).click()]);
        assert(await desktop.getByText('No payments match these filters.').isVisible());

        await desktop.getByRole('link', { name: 'Settlement' }).click();
        assert(await desktop.getByText('Amount mismatch', { exact: true }).isVisible());
        await audit(desktop, 'Desktop settlement');
        await capture(desktop, 'settlement');
        await desktop.locator('tr').filter({ hasText: '-3.800' }).getByRole('link').click();
        assert(await desktop.getByText('3.800 KWD', { exact: true }).isVisible());
        await audit(desktop, 'Desktop payment detail');
        await capture(desktop, 'payment-detail');
        await desktop.getByRole('button', { name: 'Sign out' }).click();
        await desktop.goto(base + '/settlement');
        assert.equal(new URL(desktop.url()).pathname, '/login');
        await audit(desktop, 'Sign-in');

        const mobile = await (await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, reducedMotion: 'reduce' })).newPage();
        await login(mobile);
        await audit(mobile, 'Mobile payments');
        await capture(mobile, 'payments-mobile', false);
        console.log('Console navigation, filters, evidence links, keyboard entry and accessibility checks passed.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
