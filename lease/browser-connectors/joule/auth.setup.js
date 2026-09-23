require('dotenv').config();

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

(async () => {
    const authStatePath = path.resolve(__dirname, process.env.AUTH_STATE);
    const deliveredUrl = process.env.JOULE_DELIVERED_URL;

    fs.mkdirSync(path.dirname(authStatePath), { recursive: true });

    const browser = await chromium.launch({
        headless: false,
    });

    const context = await browser.newContext();
    const page = await context.newPage();

    await page.goto(deliveredUrl, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
    });

    console.log('Log manueel in bij Joule.');
    console.log('Ga tot je de Delivered Bikes-pagina ziet.');
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