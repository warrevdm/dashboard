require('dotenv').config();

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE_URL = 'https://portal.cyclobility.be';

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

function absoluteUrl(href) {
    if (!href) {
        return '';
    }

    try {
        return new URL(href, BASE_URL).href;
    } catch (error) {
        return href;
    }
}

function safeFilename(value) {
    return String(value || 'unknown').replace(/[^a-zA-Z0-9-_]/g, '_');
}

function splitCustomerCell(value) {
    const lines = String(value || '')
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter(Boolean);

    const emailRegex = /[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i;

    const emailLine = lines.find((line) => emailRegex.test(line)) || '';
    const emailMatch = emailLine.match(emailRegex);

    const email = emailMatch ? emailMatch[0].trim() : '';
    const name = lines.find((line) => !emailRegex.test(line)) || '';

    return {
        name: name.trim(),
        email,
    };
}

function normalizeCyclobilityMoney(value) {
    const text = String(value || '').replace(/\u00a0/g, ' ').trim();
    const match = text.match(/-?\s*[\d.]+,\d{2}/);

    if (!match) {
        return '';
    }

    return match[0].replace(/\s/g, '');
}

function addOneYearToBelgianDate(value) {
    const match = String(value || '').match(/(\d{2})\/(\d{2})\/(\d{4})/);

    if (!match) {
        return '';
    }

    const day = Number(match[1]);
    const month = Number(match[2]);
    const year = Number(match[3]);

    const date = new Date(year + 1, month - 1, day);

    const outputDay = String(date.getDate()).padStart(2, '0');
    const outputMonth = String(date.getMonth() + 1).padStart(2, '0');
    const outputYear = date.getFullYear();

    return `${outputDay}/${outputMonth}/${outputYear}`;
}

function normalizeBikeTypeFromName(value) {
    const text = String(value || '').toLowerCase();

    if (
        text.includes('gazelle') ||
        text.includes('avignon') ||
        text.includes('grenoble') ||
        text.includes('ultimate') ||
        text.includes('verve+') ||
        text.includes('stromer') ||
        text.includes('e-bike') ||
        text.includes('ebike') ||
        text.includes('800wh') ||
        text.includes('500wh') ||
        text.includes('625wh') ||
        text.includes('750wh')
    ) {
        return 'elektrisch';
    }

    if (text.includes('gravel')) {
        return 'gravelfiets';
    }

    if (
        text.includes('race') ||
        text.includes('road') ||
        text.includes('emonda') ||
        text.includes('madone') ||
        text.includes('addict')
    ) {
        return 'racefiets';
    }

    if (
        text.includes('mtb') ||
        text.includes('mountain') ||
        text.includes('procaliber')
    ) {
        return 'mountainbike';
    }

    if (
        text.includes('bakfiets') ||
        text.includes('cargo') ||
        text.includes('longtail')
    ) {
        return 'elektrische gezinsfiets';
    }

    return 'onbekend';
}

function extractPhone(text) {
    const match = String(text || '').match(/(\+32\s?[0-9]{8,10}|04\d{8}|04\d{2}[\s./-]?\d{2}[\s./-]?\d{2}[\s./-]?\d{2})/);
    return match ? match[1].trim() : '';
}

async function scrapeCurrentPageRows(page) {
    return await page.locator('table.views-table tbody tr').evaluateAll((trs) => {
        return trs.map((tr) => {
            const cells = Array.from(tr.querySelectorAll('td'));

            const orderLink = tr.querySelector('td.views-field-order-number a');
            const viewLink =
                tr.querySelector('li.external-retailer-view a') ||
                tr.querySelector('td.views-field-order-retailer-operations a[href*="/orders/"]');

            return {
                soNumber: orderLink ? orderLink.innerText.trim() : (cells[0]?.innerText.trim() || ''),
                detailHref: viewLink
                    ? new URL(viewLink.getAttribute('href'), 'https://portal.cyclobility.be').href
                    : (orderLink ? new URL(orderLink.getAttribute('href'), 'https://portal.cyclobility.be').href : ''),
                placedAt: cells[1]?.innerText.trim() || '',
                customer: cells[2]?.innerText.trim() || '',
                status: cells[3]?.innerText.trim() || '',
                leaseEndDate: cells[4]?.innerText.trim() || '',
                rawCells: cells.map((cell) => cell.innerText.trim()),
            };
        });
    });
}

function rowsToSignature(rows) {
    return JSON.stringify(rows.map((row) => row.soNumber).filter(Boolean));
}

async function clickNextPage(page) {
    const candidates = [
        page.locator('a[rel="next"]').first(),
        page.getByRole('link', { name: /volgende|next|›|»/i }).first(),
        page.getByRole('button', { name: /volgende|next|›|»/i }).first(),
        page.locator('.pager__item--next a').first(),
        page.locator('.pagination a').filter({ hasText: /volgende|next|›|»/i }).first(),
        page.locator('.pagination button').filter({ hasText: /volgende|next|›|»/i }).first(),
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

            console.log('Klik op volgende Cyclobility-pagina...');

            await candidate.click();

            try {
                await page.waitForLoadState('domcontentloaded', { timeout: 10000 });
            } catch (error) {
                // Drupal/ajax kan zonder volledige paginaload werken.
            }

            await page.waitForTimeout(1800);

            return true;
        } catch (error) {
            // Probeer volgende kandidaat.
        }
    }

    return false;
}

async function scrapeAllOverviewRows(page) {
    const allRows = [];
    const seenSignatures = new Set();

    let pageNumber = 1;

    while (true) {
        console.log(`Cyclobility overzichtspagina ${pageNumber} uitlezen...`);

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

async function scrapeOrderDetails(page, row) {
    const detailUrl = row.detailHref;

    const emptyDetails = {
        phone: '',
        bikeType: '',
        bikeName: '',
        frameNumber: '',
        leaseStartDate: '',
        maintenanceBudget: '',
        yearlyMaintenanceEndDate: '',
    };

    if (!detailUrl) {
        return emptyDetails;
    }

    await page.goto(detailUrl, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
    });

    await page.waitForTimeout(2500);

    const bodyText = await page.locator('body').innerText();

    fs.writeFileSync(
        path.resolve(__dirname, `debug-detail-${safeFilename(row.soNumber)}.txt`),
        bodyText,
        'utf8'
    );

    const bikeName = await page
        .locator('.order-item__title')
        .first()
        .innerText()
        .catch(() => '');

    const frameNumber = await page
        .locator('.field--name-field-frame-number .field__item')
        .first()
        .innerText()
        .catch(() => '');

    const leaseStartDate = await page
        .locator('.field--label-inline')
        .filter({ hasText: 'Leverdatum' })
        .locator('.field__item')
        .first()
        .innerText()
        .catch(() => '');

    const maintenanceBudget = await page
        .locator('.field--type-string.field--label-inline')
        .filter({ hasText: 'Balans (huidig jaar)' })
        .locator('.field__item')
        .first()
        .innerText()
        .catch(() => '');

    const phone = extractPhone(bodyText);

    return {
        phone,
        bikeType: normalizeBikeTypeFromName(bikeName),
        bikeName: bikeName.trim(),
        frameNumber: frameNumber.trim(),
        leaseStartDate: leaseStartDate.trim(),
        maintenanceBudget: normalizeCyclobilityMoney(maintenanceBudget),
        yearlyMaintenanceEndDate: addOneYearToBelgianDate(leaseStartDate),
    };
}

(async () => {
    const authStatePath = path.resolve(__dirname, process.env.AUTH_STATE);
    const ordersUrl = process.env.CYCLOBILITY_ORDERS_URL;
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

    const overviewPage = await context.newPage();
    const detailPage = await context.newPage();

    await overviewPage.goto(ordersUrl, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
    });

    await overviewPage.waitForTimeout(3000);

    console.log('Cyclobility overzicht geladen.');

    await overviewPage.screenshot({
        path: path.resolve(__dirname, 'cyclobility-overview.png'),
        fullPage: true,
    });

const rows = await scrapeAllOverviewRows(overviewPage);

console.log(`Totaal aantal rijen: ${rows.length}`);

if (rows.length === 0) {
    const screenshotPath = path.resolve(__dirname, 'cyclobility-empty-or-login-page.png');
    const bodyPath = path.resolve(__dirname, 'cyclobility-empty-or-login-page.txt');

    await overviewPage.screenshot({
        path: screenshotPath,
        fullPage: true,
    }).catch(() => {});

    const bodyText = await overviewPage.locator('body').innerText().catch(() => '');

    fs.writeFileSync(bodyPath, bodyText, 'utf8');

    console.error('');
    console.error('Geen Cyclobility-rijen gevonden.');
    console.error('Waarschijnlijk is de sessie verlopen of staat de scraper op de loginpagina.');
    console.error('De bestaande CSV wordt NIET overschreven.');
    console.error('');
    console.error('Controlebestanden:');
    console.error(`- ${screenshotPath}`);
    console.error(`- ${bodyPath}`);
    console.error('');
    console.error('Oplossing: run opnieuw npm run auth, log manueel in en druk pas ENTER wanneer de ordertabel zichtbaar is.');

    await browser.close();
    process.exit(1);
}

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

    let skippedWithoutSoNumber = 0;

    for (const [index, row] of rows.entries()) {
        const soNumber = String(row.soNumber || '').trim();

        if (!soNumber) {
            skippedWithoutSoNumber++;
            continue;
        }

        console.log(`Scrape Cyclobility detail voor ${soNumber} (${index + 1}/${rows.length})`);

        const customer = splitCustomerCell(row.customer);

        let details = {
            phone: '',
            bikeType: '',
            bikeName: '',
            frameNumber: '',
            leaseStartDate: '',
            maintenanceBudget: '',
            yearlyMaintenanceEndDate: '',
        };

        try {
            details = await scrapeOrderDetails(detailPage, row);
        } catch (error) {
            console.log(`Detail scrape mislukt voor ${soNumber}: ${error.message}`);
        }

        const missingFields = [];

        if (!details.bikeType) {
            missingFields.push('soort fiets');
        }

        if (!details.bikeName) {
            missingFields.push('fietsnaam');
        }

        if (!details.frameNumber) {
            missingFields.push('framenummer');
        }

        if (!details.leaseStartDate) {
            missingFields.push('startdatum');
        }

        if (!details.maintenanceBudget) {
            missingFields.push('onderhoudsbudget');
        }

        if (!details.yearlyMaintenanceEndDate) {
            missingFields.push('einde jaarlijks onderhoudscontract');
        }

        if (missingFields.length > 0) {
            console.log(`Ontbrekende detailvelden voor ${soNumber}: ${missingFields.join(', ')}`);
        }

        csvLines.push([
            soNumber,
            customer.name,
            customer.email,
            details.phone,
            '',
            'Cyclobility',
            row.status,
            details.bikeType,
            details.bikeName,
            details.frameNumber,
            details.leaseStartDate,
            row.leaseEndDate,
            details.maintenanceBudget,
            details.yearlyMaintenanceEndDate,
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
        console.error('Sluit cyclobility-orders.csv in Excel of een andere app en probeer opnieuw.');
        console.error(`Bestand: ${outputCsvPath}`);
        console.error(error.message);

        process.exit(1);
    }

    console.log(`CSV geschreven naar: ${outputCsvPath}`);
    console.log(`Aantal bruikbare rijen: ${csvLines.length - 1}`);
    console.log(`Rijen zonder SO-number: ${skippedWithoutSoNumber}`);

    await browser.close();
})();