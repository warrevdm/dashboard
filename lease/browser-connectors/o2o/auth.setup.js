require('dotenv').config();

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

(async () => {
    const authStatePath = path.resolve(__dirname, process.env.AUTH_STATE);
    const contractsUrl = process.env.O2O_CONTRACTS_URL;

    fs.mkdirSync(path.dirname(authStatePath), { recursive: true });

    const browser = await chromium.launch({
        headless: false,
    });

    const context = await browser.newContext();
    const page = await context.newPage();

    await page.goto(contractsUrl, {
        waitUntil: 'domcontentloaded',
    });

    console.log('Log manueel in bij o2o in het browservenster.');
    console.log('Ga tot je de contractpagina ziet.');
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