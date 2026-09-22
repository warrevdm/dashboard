const form = document.querySelector('#mailForm');
const previewButton = document.querySelector('#previewButton');
const previewDialog = document.querySelector('#previewDialog');
const closePreview = document.querySelector('#closePreview');
const previewContent = document.querySelector('#previewContent');
const note = document.querySelector('#pickup_note');
const noteCount = document.querySelector('#noteCount');
const bikeInput = document.querySelector('#bike_type');
const quickIdCard = document.querySelector('#quickIdCard');
const quickLeaseABikePin = document.querySelector('#quickLeaseABikePin');
const quickPickupDate = document.querySelector('#quickPickupDate');
const pickupDateRow = document.querySelector('#pickupDateRow');
const pickupDate = document.querySelector('#pickup_date');

const fields = {
    customer_name: 'Vul de naam van de klant in.',
    customer_email: 'Vul een geldig e-mailadres in.',
    bike_type: 'Vul het type of model van de nieuwe fiets in.'
};

const ID_CARD_MESSAGE = 'Gelieve je identiteitskaart mee te brengen in functie van de leasing.';
const LEASE_A_BIKE_PIN_MESSAGE = 'Gelieve je pincode van Lease a Bike mee te brengen.';
const PICKUP_PREFIX = 'Je fiets kan opgehaald worden vanaf ';

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function capitalizeWords(value) {
    return value.replace(/(^|\s|[-/])([\p{L}])/gu, (match, separator, letter) => separator + letter.toLocaleUpperCase('nl-BE'));
}

function formatDateNl(value) {
    if (!value) return '';
    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(year, month - 1, day);
    return new Intl.DateTimeFormat('nl-BE', { day: 'numeric', month: 'long', year: 'numeric' }).format(date);
}

function setQuickActive(button, active) {
    button.classList.toggle('active', active);
    button.setAttribute('aria-pressed', active ? 'true' : 'false');
}

function rebuildPickupNote() {
    const lines = note.value
        .split('\n')
        .map(line => line.trim())
        .filter(line => line !== '' && line !== ID_CARD_MESSAGE && line !== LEASE_A_BIKE_PIN_MESSAGE && !line.startsWith(PICKUP_PREFIX));

    if (quickIdCard.classList.contains('active')) lines.unshift(ID_CARD_MESSAGE);
    if (quickLeaseABikePin.classList.contains('active')) lines.unshift(LEASE_A_BIKE_PIN_MESSAGE);
    if (quickPickupDate.classList.contains('active') && pickupDate.value) {
        lines.push(`${PICKUP_PREFIX}${formatDateNl(pickupDate.value)}.`);
    }

    note.value = lines.join('\n');
    noteCount.textContent = note.value.length;
}

function validateForm() {
    let valid = true;
    Object.entries(fields).forEach(([name, message]) => {
        const input = form.elements[name];
        const error = input.parentElement.querySelector('.field-error');
        const value = input.value.trim();
        let fieldValid = value !== '';
        if (name === 'customer_email') fieldValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
        input.classList.toggle('invalid', !fieldValid);
        error.textContent = fieldValid ? '' : message;
        valid = valid && fieldValid;
    });
    return valid;
}

function buildPreview() {
    if (!validateForm()) return;

    const name = escapeHtml(form.elements.customer_name.value.trim());
    const email = escapeHtml(form.elements.customer_email.value.trim());
    const bike = escapeHtml(form.elements.bike_type.value.trim());
    const extra = escapeHtml(form.elements.pickup_note.value.trim()).replaceAll('\n', '<br>');

    previewContent.innerHTML = `
        <div style="max-width:640px;margin:auto;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 8px 24px rgba(20,35,24,.08);font-family:Arial,sans-serif;">
            <div style="background:#fff;padding:20px 32px 18px;text-align:center;">
                <img src="assets/aab-logo.svg" alt="Aerts Action Bike" style="display:block;width:270px;max-width:100%;height:auto;margin:0 auto;">
            </div>
            <div style="background:#172019;padding:24px 32px 26px;color:#fff;text-align:center;">
                <h2 style="margin:0;font-size:30px;">Je nieuwe fiets staat klaar</h2>
            </div>
            <div style="padding:32px;font-size:16px;line-height:1.7;color:#263229;">
                <small style="color:#667068;">Naar: ${email}</small><br><br>
                Dag ${name},<br><br>
                <strong>Proficiat met je nieuwe fiets!</strong><br><br>
                Goed nieuws: je nieuwe <strong>${bike}</strong> staat volledig klaar voor afhaling bij Aerts Action Bike.<br><br>
                Tijdens de afhaling nemen we de tijd om je nieuwe fiets samen te overlopen, correct af te stellen en alle belangrijke uitleg mee te geven.
                <div style="text-align:center;padding:28px 0;">
                    <span style="display:inline-block;background:#60bb46;padding:15px 24px;border-radius:8px;font-weight:700;">Plan de afhaling van je nieuwe fiets</span>
                </div>
                ${extra ? `<div style="padding:16px 18px;background:#f4f7f2;border-left:4px solid #60bb46;border-radius:8px;"><strong>Extra informatie</strong><br>${extra}</div><br>` : ''}
                Heb je nog een vraag? Antwoord gerust op deze mail of neem contact op met onze winkel.<br><br>
                Sportieve groeten,<br><strong>Team Aerts Action Bike</strong>
            </div>
        </div>`;

    previewDialog.showModal();
}

bikeInput.addEventListener('input', () => {
    const start = bikeInput.selectionStart;
    const end = bikeInput.selectionEnd;
    bikeInput.value = capitalizeWords(bikeInput.value);
    if (start !== null && end !== null) bikeInput.setSelectionRange(start, end);
});

quickIdCard.addEventListener('click', () => {
    setQuickActive(quickIdCard, !quickIdCard.classList.contains('active'));
    rebuildPickupNote();
});
quickLeaseABikePin.addEventListener('click', () => {
    setQuickActive(quickLeaseABikePin, !quickLeaseABikePin.classList.contains('active'));
    rebuildPickupNote();
});
quickPickupDate.addEventListener('click', () => {
    const active = !quickPickupDate.classList.contains('active');
    setQuickActive(quickPickupDate, active);
    pickupDateRow.hidden = !active;
    if (active) pickupDate.focus();
    rebuildPickupNote();
});
pickupDate.addEventListener('change', rebuildPickupNote);
note.addEventListener('input', () => { noteCount.textContent = note.value.length; });
previewButton.addEventListener('click', buildPreview);
closePreview.addEventListener('click', () => previewDialog.close());
previewDialog.addEventListener('click', event => { if (event.target === previewDialog) previewDialog.close(); });

form.addEventListener('submit', event => {
    bikeInput.value = capitalizeWords(bikeInput.value.trim());
    rebuildPickupNote();
    if (!validateForm()) {
        event.preventDefault();
        return;
    }
    const submitter = event.submitter;
    if (submitter && submitter.value === 'graph') {
        submitter.disabled = true;
        submitter.textContent = 'Mail wordt verstuurd…';
    }
});
