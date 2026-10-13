(() => {
  document.querySelectorAll('[data-dossier-bikes]').forEach(root => {
    const cards = [...root.querySelectorAll('[data-bike-card]')];
    const search = root.querySelector('[data-bike-search]');
    const selectedOnly = root.querySelector('[data-selected-only]');
    const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();
    const form = root.closest('form');
    const money = value => new Intl.NumberFormat('nl-BE', {style:'currency',currency:'EUR'}).format(value);
    const notice = root.querySelector('[data-package-price-notice]');
    const expected = root.querySelector('[data-package-price]');
    const confirmation = root.querySelector('[data-package-price-confirm]');
    const confirmInput = confirmation.querySelector('input');
    confirmInput.addEventListener('invalid', () => { root.open = true; });
    let lastQuote = '';
    const update = () => {
      const query = normalize(search.value.trim());
      let selected = 0, visible = 0;
      cards.forEach(card => {
        const checked = card.querySelector('input').checked;
        selected += Number(checked);
        card.hidden = !normalize(card.dataset.search).includes(query) || (selectedOnly.checked && !checked);
        visible += Number(!card.hidden);
      });
      root.querySelector('[data-bike-count]').textContent = `${selected} ${selected === 1 ? 'fiets' : 'fietsen'} geselecteerd`;
      root.querySelector('[data-bike-empty]').hidden = visible > 0;
      const changed = cards.some(card => card.querySelector('input').checked !== (card.dataset.original === '1'));
      const start = new Date(form.elements.start_date.value + 'T' + form.elements.start_time.value);
      const end = new Date(form.elements.end_date.value + 'T' + form.elements.end_time.value);
      const days = Math.ceil((end - start) / 86400000);
      let total = 0, valid = Number.isFinite(days) && days > 0 && selected > 0;
      const replacement = form.elements.rental_kind.value === 'replacement';
      cards.filter(card => card.querySelector('input').checked).forEach(card => {
        const day = Number(card.dataset.day), week = Number(card.dataset.week);
        if (!replacement && day <= 0) valid = false;
        total += replacement ? 0 : week > 0 ? Math.floor(days / 7) * week + Math.min((days % 7) * day, week) : days * day;
      });
      total = Math.round(total * 100) / 100;
      const old = Number(root.dataset.originalTotal);
      const different = changed && valid && Math.abs(total - old) >= .005;
      const quote = changed && valid ? total.toFixed(2) : '';
      if (quote !== lastQuote) confirmInput.checked = false;
      lastQuote = quote;
      expected.value = quote;
      notice.hidden = !changed;
      confirmation.hidden = !different;
      confirmInput.required = different;
      notice.textContent = !valid ? 'Kies minstens één fiets, een geldige periode en fietsen met een geldig tarief.' :
        'Eindprijs: ' + money(old) + ' → ' + money(total) + '. Verschil: ' + (total > old ? '+' : '') + money(total - old) + '. Bestaande betalingen blijven behouden.';

    };
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();
  });
})();
