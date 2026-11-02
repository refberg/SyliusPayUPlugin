// Pays a PayU sandbox order the way a customer would: bank transfer, mTransfer test bank,
// then the provider simulator's positive or negative authorization.
// Usage: node tests/Sandbox/pay.cjs <redirectUri> <positive|negative>
// Requires Playwright with Chromium (npm install playwright && npx playwright install chromium).
const { chromium } = require('playwright');

(async () => {
    const [url, outcome] = process.argv.slice(2);
    if (!url || !url.startsWith('https://merch-prod.snd.payu.com/')) {
        throw new Error('Only PayU sandbox payment pages are allowed.');
    }

    const proxy = process.env.HTTPS_PROXY ? { server: process.env.HTTPS_PROXY } : undefined;
    const browser = await chromium.launch({ proxy });
    try {
        const page = await browser.newPage({ locale: 'en-US' });
        await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 });
        await page.locator('text=Bank transfer').first().click();
        await page.locator('button[title="mTransfer"]').first().click();
        const button = outcome === 'negative' ? 'Negative authorization' : 'Positive authorization';
        await page.locator(`text=${button}`).first().click({ timeout: 60000 });
        // PayU redirects to continueUrl afterwards; the shop is not reachable from here, which is fine.
        await page.waitForTimeout(3000);
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error.message);
    process.exit(1);
});
