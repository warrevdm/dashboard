const form = document.querySelector('#collectForm');
const previewButton = document.querySelector('#previewButton');
const previewDialog = document.querySelector('#previewDialog');
const closePreview = document.querySelector('#closePreview');
const previewContent = document.querySelector('#previewContent');
const note = document.querySelector('#pickup_note');
const noteCount = document.querySelector('#noteCount');

const fields = {
    customer_name: 'Vul de naam van de klant in.',
    customer_email: 'Vul een geldig e-mailadres in.',
    bike_type: 'Vul het bestelde product in.'
};

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function validateForm() {
    let valid = true;

    Object.entries(fields).forEach(([name, message]) => {
        const input = form.elements[name];
        const error = input.parentElement.querySelector('.field-error');
        const value = input.value.trim();
        let fieldValid = value !== '';

        if (name === 'customer_email') {
            fieldValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
        }

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
    const item = escapeHtml(form.elements.bike_type.value.trim());
    const extra = escapeHtml(form.elements.pickup_note.value.trim()).replaceAll('\n', '<br>');

    previewContent.innerHTML = `
        <div style="max-width:640px;margin:auto;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 8px 24px rgba(20,35,24,.08);font-family:Arial,sans-serif;">
            <div style="background:#fff;padding:20px 32px 18px;text-align:center;">
                <img src="assets/aab-logo.svg" alt="Aerts Action Bike" style="display:block;width:270px;max-width:100%;height:auto;margin:0 auto;">
            </div>
            <div style="background:#172019;padding:24px 32px 26px;color:#fff;text-align:center;">
                <h2 style="margin:0;font-size:30px;">Je bestelling staat klaar</h2>
            </div>
            <div style="padding:32px;font-size:16px;line-height:1.7;color:#263229;">
                <small style="color:#667068;">Naar: ${email}</small><br><br>
                Dag ${name},<br><br>
                Goed nieuws: je bestelling <strong>${item}</strong> staat klaar voor afhaling bij Aerts Action Bike.<br><br>
                Je hoeft hiervoor geen afspraak te maken. Kom gerust langs tijdens onze openingsuren en ons team helpt je verder.
                <div style="margin:24px 0;padding:18px 20px;background:#f4f7f2;border-radius:10px;line-height:1.7;">
                    <strong>Openingsuren</strong><br>
                    Dinsdag t.e.m. vrijdag: 09:00–12:30 &amp; 13:30–18:00<br>
                    Zaterdag: 09:00–17:00<br>
                    Zondag &amp; maandag: gesloten
                </div>
                ${extra ? `<div style="padding:16px 18px;background:#f4f7f2;border-left:4px solid #60bb46;border-radius:8px;"><strong>Extra informatie</strong><br>${extra}</div><br>` : ''}
                Heb je nog een vraag? Antwoord gerust op deze mail of neem contact op met onze winkel.<br><br>
                Tot snel!<br><strong>Team Aerts Action Bike</strong>
            </div>
        </div>`;

    previewDialog.showModal();
}

note.addEventListener('input', () => {
    noteCount.textContent = note.value.length;
});

previewButton.addEventListener('click', buildPreview);
closePreview.addEventListener('click', () => previewDialog.close());
previewDialog.addEventListener('click', event => {
    if (event.target === previewDialog) previewDialog.close();
});

form.addEventListener('submit', event => {
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
