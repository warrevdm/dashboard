require('dotenv').config();

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

(async () => {
    const authStatePath = path.resolve(__dirname, process.env.AUTH_STATE);
    const ordersUrl = process.env.CYCLOBILITY_ORDERS_URL;

    fs.mkdirSync(path.dirname(authStatePath), { recursive: true });

    const browser = await chromium.launch({
        headless: false,
    });

    const context = await browser.newContext();
    const page = await context.newPage();

    await page.goto(ordersUrl, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
    });

    console.log('Log manueel in bij Cyclobility.');
    console.log('Ga tot je de contracten/orders-pagina ziet.');
    console.log('Druk daarna hier in PowerShell op ENTER.');

    process.stdin.resume();
    process.stdin.once('data', async () => {
        await context.storageState({
            path: authStatePath,
        });

        console.log(`Auth state opgeslagen in: ${authStatePath}`);

        await browser.close();
        process.exit(0);
    });
})();