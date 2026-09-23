require('dotenv').config();

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

(async () => {
    const authStatePath = path.resolve(__dirname, process.env.AUTH_STATE);
    const ordersUrl = process.env.CYCLOBILITY_ORDERS_URL;

    if (!fs.existsSync(authStatePath)) {
        console.error('Geen auth state gevonden. Run eerst: node auth.setup.js');
        process.exit(1);
    }

    const browser = await chromium.launch({
        headless: false,
    });

    const context = await browser.newContext({
        storageState: authStatePath,
    });

    const page = await context.newPage();

    await page.goto(ordersUrl, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
    });

    await page.waitForTimeout(3000);

    await page.screenshot({
        path: path.resolve(__dirname, 'debug-cyclobility-overview.png'),
        fullPage: true,
    });

    const bodyText = await page.locator('body').innerText();

    fs.writeFileSync(
        path.resolve(__dirname, 'debug-cyclobility-overview.txt'),
        bodyText,
        'utf8'
    );

    const tables = await page.locator('table').count();

    console.log(`Aantal tabellen gevonden: ${tables}`);

    for (let tableIndex = 0; tableIndex < tables; tableIndex++) {
        console.log(`\n--- TABEL ${tableIndex + 1} ---`);

        const table = page.locator('table').nth(tableIndex);

        const headers = await table.locator('thead th').evaluateAll((ths) => {
            return ths.map((th) => th.innerText.trim());
        }).catch(() => []);

        console.log('Headers:', headers);

        const rows = await table.locator('tbody tr').evaluateAll((trs) => {
            return trs.slice(0, 5).map((tr) => {
                return Array.from(tr.querySelectorAll('td')).map((td) => td.innerText.trim());
            });
        }).catch(() => []);

        console.log('Eerste 5 rijen:');
        console.dir(rows, { depth: null });
    }

    const links = await page.locator('a').evaluateAll((items) => {
        return items.slice(0, 80).map((item, index) => ({
            index,
            text: item.innerText.trim(),
            href: item.href,
            className: item.className,
        }));
    }).catch(() => []);

    fs.writeFileSync(
        path.resolve(__dirname, 'debug-cyclobility-links.json'),
        JSON.stringify(links, null, 2),
        'utf8'
    );

    console.log('Debugbestanden gemaakt:');
    console.log('- debug-cyclobility-overview.png');
    console.log('- debug-cyclobility-overview.txt');
    console.log('- debug-cyclobility-links.json');

    await browser.close();
})();