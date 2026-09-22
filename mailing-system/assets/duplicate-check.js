(() => {
    const form = document.querySelector('#mailForm, #collectForm');
    if (!form) return;

    const emailInput = form.elements.customer_email;
    const itemInput = form.elements.bike_type;
    const warning = document.querySelector('#duplicateWarning');
    if (!emailInput || !itemInput || !warning) return;

    let timer = null;
    let requestId = 0;
    let currentMatches = [];
    let exactMatch = false;

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function formatDate(value) {
        if (!value) return '';
        const date = new Date(value);
        return new Intl.DateTimeFormat('nl-BE', {
            day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'
        }).format(date);
    }

    function hideWarning() {
        currentMatches = [];
        exactMatch = false;
        warning.hidden = true;
        warning.classList.remove('duplicate-warning-exact');
        warning.innerHTML = '';
    }

    function renderWarning(data) {
        currentMatches = Array.isArray(data.matches) ? data.matches : [];
        exactMatch = Boolean(data.exact_match);

        if (currentMatches.length === 0) {
            hideWarning();
            return;
        }

        warning.hidden = false;
        warning.classList.toggle('duplicate-warning-exact', exactMatch);

        const title = exactMatch
            ? 'Let op: voor deze klant én dit product is al een mail verstuurd'
            : 'Deze klant kreeg eerder al communicatie';
        const intro = exactMatch
            ? 'Controleer onderstaande historiek voor je opnieuw verstuurt.'
            : 'Controleer even of deze nieuwe mail nodig is om dubbele communicatie te vermijden.';

        const rows = currentMatches.slice(0, 5).map(row => `
            <div class="duplicate-history-row ${row.exact_item ? 'exact' : ''}">
                <div><strong>${escapeHtml(row.item)}</strong><small>${escapeHtml(row.type)}</small></div>
                <div><strong>${escapeHtml(formatDate(row.created_at))}</strong><small>door ${escapeHtml(row.sender)}</small></div>
                ${row.exact_item ? '<span class="duplicate-badge">Zelfde product</span>' : ''}
            </div>
        `).join('');

        warning.innerHTML = `
            <div class="duplicate-warning-head">
                <div class="duplicate-warning-icon">!</div>
                <div><strong>${title}</strong><p>${intro}</p></div>
            </div>
            <div class="duplicate-history-list">${rows}</div>
            <a class="duplicate-dashboard-link" href="communication-dashboard.php?search=${encodeURIComponent(emailInput.value.trim())}">Bekijk volledige communicatiehistoriek</a>
        `;
    }

    async function checkDuplicates() {
        const email = emailInput.value.trim();
        const item = itemInput.value.trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            hideWarning();
            return;
        }

        const id = ++requestId;
        try {
            const response = await fetch(`duplicate-check.php?email=${encodeURIComponent(email)}&item=${encodeURIComponent(item)}`, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });
            if (!response.ok || id !== requestId) return;
            const data = await response.json();
            if (id === requestId) renderWarning(data);
        } catch (_) {
            // Een tijdelijke controlefout mag de mailflow niet blokkeren.
        }
    }

    function scheduleCheck() {
        clearTimeout(timer);
        timer = setTimeout(checkDuplicates, 350);
    }

    emailInput.addEventListener('input', scheduleCheck);
    emailInput.addEventListener('blur', checkDuplicates);
    itemInput.addEventListener('input', scheduleCheck);
    itemInput.addEventListener('blur', checkDuplicates);

    form.addEventListener('submit', event => {
        const submitter = event.submitter;
        if (!submitter || submitter.value !== 'graph' || currentMatches.length === 0) return;

        const message = exactMatch
            ? 'Voor deze klant en dit product werd al een mail verstuurd. Wil je toch opnieuw verzenden?'
            : 'Deze klant kreeg eerder al een mail. Heb je de historiek gecontroleerd en wil je toch verzenden?';

        if (!window.confirm(message)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);
})();
