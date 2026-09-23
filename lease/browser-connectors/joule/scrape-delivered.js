require('dotenv').config();

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE_URL = 'https://clients.joule.be';

function csvEscape(value) {
    if (value === null || value === undefined) {
        return '';
    }

    const stringValue = String(value).replace(/\r?\n|\r/g, ' ').trim();

    if (
        stringValue.includes(';') ||
        stringValue.includes('"') ||
        stringValue.includes(',')
    ) {
        return `"${stringValue.replace(/"/g, '""')}"`;
    }

    return stringValue;
}

function normalizeMoneyValue(value) {
    const text = String(value || '')
        .replace(/\u00a0/g, ' ')
        .replace('€', '')
        .trim();

    const match = text.match(/[\d.,]+/);

    if (!match) {
        return '';
    }

    return match[0].trim();
}

function extractRemainingVoucherBalance(value) {
    const text = String(value || '').replace(/\u00a0/g, ' ').trim();

    // Voorbeeld: € 899.95 / € 900.00
    // Voorbeeld: € 1,200.08 / € 1,200.00
    const firstPart = text.split('/')[0] || '';

    return normalizeMoneyValue(firstPart);
}

function normalizeBikeTypeFromName(value) {
    const text = String(value || '').toLowerCase();

    if (
        text.includes('speed') ||
        text.includes('stromer') ||
        text.includes('st3') ||
        text.includes('st5') ||
        text.includes('st7')
    ) {
        return 'speedpedelec';
    }

    if (
        text.includes('gazelle') ||
        text.includes('grenoble') ||
        text.includes('ultimate') ||
        text.includes('district+') ||
        text.includes('verve+') ||
        text.includes('urban arrow') ||
        text.includes('e-bike') ||
        text.includes('ebike') ||
        text.includes('wh')
    ) {
        return 'elektrisch';
    }

    if (
        text.includes('urban arrow') ||
        text.includes('bakfiets') ||
        text.includes('cargo') ||
        text.includes('longtail')
    ) {
        return 'elektrische gezinsfiets';
    }

    if (
        text.includes('cervelo') ||
        text.includes('cervélo') ||
        text.includes('s5') ||
        text.includes('addict') ||
        text.includes('race') ||
        text.includes('road')
    ) {
        return 'racefiets';
    }

    if (text.includes('gravel')) {
        return 'gravelfiets';
    }

    if (
        text.includes('mtb') ||
        text.includes('mountain') ||
        text.includes('procaliber')
    ) {
        return 'mountainbike';
    }

    return 'onbekend';
}

async function scrapeCurrentPageRows(page) {
    return await page.locator('table tbody tr').evaluateAll((trs) => {
        return trs.map((tr) => {
            const cells = Array.from(tr.querySelectorAll('td'));

            const purchaseOrderLink = cells[0]?.querySelector('a');
            const bikeTitle = cells[4]?.querySelector('h6');

            return {
                purchaseOrder: purchaseOrderLink ? purchaseOrderLink.innerText.trim() : (cells[0]?.innerText.trim() || ''),
                detailHref: purchaseOrderLink ? purchaseOrderLink.href : '',
                reference: cells[1]?.innerText.trim() || '',
                driver: cells[2]?.innerText.trim() || '',
                voucherBalance: cells[3]?.innerText.trim() || '',
                bikeName: bikeTitle ? bikeTitle.innerText.trim() : (cells[4]?.innerText.trim() || ''),
                salesOrder: cells[5]?.innerText.trim() || '',
                leaseStartDate: cells[6]?.innerText.trim() || '',
                leaseEndDate: cells[7]?.innerText.trim() || '',
                billNumber: cells[8]?.innerText.trim() || '',
                totalExclVat: cells[9]?.innerText.trim() || '',
                status: cells[10]?.innerText.trim() || '',
                rawCells: cells.map((cell) => cell.innerText.trim()),
            };
        });
    });
}

function rowsToSignature(rows) {
    return JSON.stringify(rows.map((row) => row.purchaseOrder + '|' + row.salesOrder));
}

async function clickNextPage(page) {
    const candidates = [
        page.locator('a[rel="next"]').first(),
        page.getByRole('link', { name: /volgende|next|›|»/i }).first(),
        page.getByRole('button', { name: /volgende|next|›|»/i }).first(),
        page.locator('.pagination a').filter({ hasText: /volgende|next|›|»/i }).first(),
        page.locator('.o_portal_pager a').filter({ hasText: /volgende|next|›|»/i }).first(),
    ];

    for (const candidate of candidates) {
        try {
            if ((await candidate.count()) === 0) {
                continue;
            }

            if (!(await candidate.isVisible({ timeout: 700 }))) {
                continue;
            }

            const disabled = await candidate.evaluate((element) => {
                return (
                    element.hasAttribute('disabled') ||
                    element.getAttribute('aria-disabled') === 'true' ||
                    element.classList.contains('disabled') ||
                    element.closest('.disabled') !== null
                );
            });

            if (disabled) {
                continue;
            }

            console.log('Klik op volgende Joule-pagina...');

            await candidate.click();

            try {
                await page.waitForLoadState('domcontentloaded', { timeout: 10000 });
            } catch (error) {
                // Odoo portal kan zonder volledige reload werken.
            }

            await page.waitForTimeout(1800);

            return true;
        } catch (error) {
            // Probeer volgende kandidaat.
        }
    }

    return false;
}

async function scrapeAllRows(page) {
    const allRows = [];
    const seenSignatures = new Set();

    let pageNumber = 1;

    while (true) {
        console.log(`Joule overzichtspagina ${pageNumber} uitlezen...`);

        await page.waitForTimeout(1200);

        const rows = await scrapeCurrentPageRows(page);
        const signature = rowsToSignature(rows);

        if (seenSignatures.has(signature)) {
            console.log('Pagina herkend als duplicaat. Stop paginering.');
            break;
        }

        seenSignatures.add(signature);

        console.log(`Aantal rijen op pagina ${pageNumber}: ${rows.length}`);

        allRows.push(...rows);

        const clicked = await clickNextPage(page);

        if (!clicked) {
            console.log('Geen volgende-pagina-knop gevonden. Paginering klaar.');
            break;
        }

        const newRows = await scrapeCurrentPageRows(page);
        const newSignature = rowsToSignature(newRows);

        if (newSignature === signature) {
            console.log('Klik naar volgende pagina veranderde de tabel niet. Stop paginering.');
            break;
        }

        pageNumber++;
    }

    return allRows;
}

(async () => {
    const authStatePath = path.resolve(__dirname, process.env.AUTH_STATE);
    const deliveredUrl = process.env.JOULE_DELIVERED_URL;
    const outputCsvPath = path.resolve(__dirname, process.env.OUTPUT_CSV);

    if (!fs.existsSync(authStatePath)) {
        console.error('Geen auth state gevonden. Run eerst: npm run auth');
        process.exit(1);
    }

    fs.mkdirSync(path.dirname(outputCsvPath), { recursive: true });

    const browser = await chromium.launch({
        headless: false,
    });

    const context = await browser.newContext({
        storageState: authStatePath,
    });

    const page = await context.newPage();

    console.log('Joule Delivered Bikes openen...');

try {
    await page.goto(deliveredUrl, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
    });
} catch (error) {
    console.error('Joule-pagina kon niet geladen worden.');
    console.error(error.message);

    await browser.close();
    process.exit(1);
}

if (page.isClosed()) {
    console.error('');
    console.error('De Joule-tab werd automatisch gesloten.');
    console.error('Waarschijnlijk blokkeert Cloudflare of is de opgeslagen sessie ongeldig.');
    console.error('');
    console.error('Oplossing: run opnieuw npm run auth en blijf in de browser tot de Delivered Bikes-tabel zichtbaar is.');
    console.error('Druk pas daarna op ENTER in PowerShell.');

    await browser.close().catch(() => {});
    process.exit(1);
}

try {
    await page.waitForTimeout(3000);
} catch (error) {
    console.error('');
    console.error('De Joule-pagina werd gesloten tijdens het wachten.');
    console.error('Waarschijnlijk door Cloudflare/session protection.');
    console.error('');
    console.error('Gebruik voorlopig de manuele Joule-import of probeer opnieuw met npm run auth.');
    console.error(error.message);

    await browser.close().catch(() => {});
    process.exit(1);
}

if (page.isClosed()) {
    console.error('De Joule-pagina is gesloten na laden. Scrape gestopt.');
    await browser.close().catch(() => {});
    process.exit(1);
}

console.log('Joule Delivered Bikes geladen.');

    await page.screenshot({
        path: path.resolve(__dirname, 'joule-delivered-page.png'),
        fullPage: true,
    });

    const rows = await scrapeAllRows(page);

    const outputHeaders = [
        'SO-number',
        'Naam',
        'Email',
        'Telefoonnummer',
        'Bedrijf',
        'Leasepartner',
        'Orderstatus',
        'Soort fiets',
        'Fietsnaam',
        'Framenummer',
        'Startdatum leasingcontract',
        'Einddatum leasingcontract',
        'Beschikbaar onderhoudsbudget',
        'Einde jaarlijks onderhoudscontract',
    ];

    const csvLines = [];
    csvLines.push(outputHeaders.map(csvEscape).join(';'));

    let skippedWithoutNumber = 0;

    for (const row of rows) {
        const soNumber = row.salesOrder || row.purchaseOrder;

        if (!soNumber) {
            skippedWithoutNumber++;
            continue;
        }

        csvLines.push([
            soNumber,
            row.driver,
            '',
            '',
            '',
            'Joule',
            row.status,
            normalizeBikeTypeFromName(row.bikeName),
            row.bikeName,
            '',
            row.leaseStartDate,
            row.leaseEndDate,
            extractRemainingVoucherBalance(row.voucherBalance),
            row.leaseEndDate,
        ].map(csvEscape).join(';'));
    }

    const csvContent = '\uFEFF' + csvLines.join('\n');
    const tempOutputCsvPath = outputCsvPath + '.tmp';

    try {
        fs.writeFileSync(tempOutputCsvPath, csvContent, 'utf8');

        if (fs.existsSync(outputCsvPath)) {
            fs.unlinkSync(outputCsvPath);
        }

        fs.renameSync(tempOutputCsvPath, outputCsvPath);
    } catch (error) {
        console.error('');
        console.error('CSV kon niet worden weggeschreven.');
        console.error('Sluit joule-delivered.csv in Excel of een andere app en probeer opnieuw.');
        console.error(`Bestand: ${outputCsvPath}`);
        console.error(error.message);

        process.exit(1);
    }

    console.log(`CSV geschreven naar: ${outputCsvPath}`);
    console.log(`Aantal ruwe rijen: ${rows.length}`);
    console.log(`Aantal bruikbare rijen: ${csvLines.length - 1}`);
    console.log(`Rijen zonder nummer: ${skippedWithoutNumber}`);

    await browser.close();
})();