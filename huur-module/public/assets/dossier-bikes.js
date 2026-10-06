(() => {
  document.querySelectorAll('[data-dossier-bikes]').forEach(root => {
    const cards = [...root.querySelectorAll('[data-bike-card]')];
    const search = root.querySelector('[data-bike-search]');
    const selectedOnly = root.querySelector('[data-selected-only]');
    const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();
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
    };
    root.addEventListener('input', update);
    root.addEventListener('change', update);
    update();
  });
})();
