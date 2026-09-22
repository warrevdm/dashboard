(() => {
  'use strict';

  const cards = [...document.querySelectorAll('[data-tool]')];
  const filters = [...document.querySelectorAll('[data-category-filter]')];
  const favoriteButtons = [...document.querySelectorAll('[data-favorite]')];
  const search = document.getElementById('tool-search');
  const clearSearch = document.getElementById('clear-search');
  const emptyState = document.getElementById('empty-state');
  const status = document.getElementById('filter-status');
  const storageKey = 'aab.home.favorites.v1';
  const validIds = new Set(cards.map(card => card.dataset.tool));
  const titles = new Map(cards.map(card => [card.dataset.tool, card.querySelector('h3').textContent]));
  const categoryNames = { all: 'Alle tools', verhuur: 'Verhuur & fietsen', communicatie: 'Communicatie', beheer: 'Beheer', favorites: 'Jouw favorieten' };
  let activeCategory = 'all';
  let favorites = new Set();

  try {
    const saved = JSON.parse(localStorage.getItem(storageKey) || '[]');
    if (Array.isArray(saved)) favorites = new Set(saved.filter(id => validIds.has(id)));
  } catch {
    // Navigation keeps working when local storage is blocked or malformed.
  }

  const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('nl-BE').trim();
  const searchableText = new Map(cards.map(card => [card.dataset.tool, normalize(`${card.dataset.search} ${card.querySelector('h3').textContent} ${card.querySelector('p').textContent}`)]));

  function updateFavorites() {
    favoriteButtons.forEach(button => {
      const id = button.dataset.favorite;
      const selected = favorites.has(id);
      button.setAttribute('aria-pressed', String(selected));
      button.setAttribute('aria-label', `${titles.get(id)} ${selected ? 'verwijderen uit' : 'toevoegen aan'} favorieten`);
      button.title = selected ? 'Verwijder uit favorieten' : 'Voeg toe aan favorieten';
      button.closest('[data-tool]').classList.toggle('is-favorite', selected);
    });
    document.getElementById('favorite-count').textContent = String(favorites.size);
  }

  function applyFilters(announce = true) {
    const terms = normalize(search.value).split(/\s+/).filter(Boolean);
    let visible = 0;
    cards.forEach(card => {
      const categoryMatch = activeCategory === 'all'
        || (activeCategory === 'favorites' ? favorites.has(card.dataset.tool) : card.dataset.category === activeCategory);
      const searchMatch = terms.every(term => searchableText.get(card.dataset.tool).includes(term));
      card.hidden = !(categoryMatch && searchMatch);
      if (!card.hidden) visible += 1;
    });

    filters.forEach(filter => {
      const selected = filter.dataset.categoryFilter === activeCategory;
      filter.classList.toggle('is-active', selected);
      if (filter.tagName === 'BUTTON') filter.setAttribute('aria-pressed', String(selected));
      else if (selected) filter.setAttribute('aria-current', 'true');
      else filter.removeAttribute('aria-current');
    });

    const countText = `${visible} ${visible === 1 ? 'tool' : 'tools'}`;
    document.getElementById('tools-title').textContent = categoryNames[activeCategory];
    document.getElementById('result-count').textContent = countText;
    clearSearch.hidden = search.value.length === 0;
    search.parentElement.querySelector('kbd').hidden = search.value.length > 0;
    emptyState.hidden = visible > 0;
    const noFavorites = activeCategory === 'favorites' && favorites.size === 0;
    document.getElementById('empty-title').textContent = noFavorites ? 'Jouw favoriete tools, hier bij elkaar' : 'Geen tools gevonden';
    document.getElementById('empty-description').textContent = noFavorites
      ? 'Klik op het sterretje bij een tool. Je favorieten worden in deze browser bewaard.'
      : 'Probeer een andere zoekterm of bekijk alle tools.';
    if (announce) status.textContent = `${countText} getoond${search.value.trim() ? ` voor “${search.value.trim()}”` : ''}.`;
  }

  favoriteButtons.forEach(button => {
    button.addEventListener('click', () => {
      const id = button.dataset.favorite;
      if (favorites.has(id)) favorites.delete(id);
      else favorites.add(id);
      try {
        localStorage.setItem(storageKey, JSON.stringify([...favorites]));
        document.getElementById('storage-notice').hidden = true;
      } catch {
        document.getElementById('storage-notice').hidden = false;
      }
      updateFavorites();
      applyFilters(false);
      status.textContent = `${titles.get(id)} ${favorites.has(id) ? 'toegevoegd aan' : 'verwijderd uit'} favorieten.`;
      if (button.closest('[data-tool]').hidden) {
        const nextFavorite = cards.find(card => !card.hidden)?.querySelector('[data-favorite]');
        (nextFavorite || document.getElementById('reset-filters')).focus({ preventScroll: true });
      }
    });
  });

  filters.forEach(filter => {
    filter.addEventListener('click', () => {
      activeCategory = filter.dataset.categoryFilter;
      applyFilters();
    });
  });

  search.addEventListener('input', () => applyFilters());
  clearSearch.addEventListener('click', () => {
    search.value = '';
    applyFilters();
    search.focus();
  });
  document.getElementById('reset-filters').addEventListener('click', () => {
    activeCategory = 'all';
    search.value = '';
    applyFilters();
    search.focus({ preventScroll: true });
  });

  document.addEventListener('keydown', event => {
    const editing = event.target instanceof Element && (event.target.matches('input, textarea, select') || event.target.isContentEditable);
    if (event.key === '/' && !editing && !event.ctrlKey && !event.metaKey && !event.altKey) {
      event.preventDefault();
      search.focus();
    }
    if (event.key === 'Escape' && document.activeElement === search) {
      search.value = '';
      applyFilters();
    }
  });

  window.addEventListener('storage', event => {
    if (event.key !== storageKey && event.key !== null) return;
    try {
      const saved = JSON.parse(event.newValue || '[]');
      favorites = new Set(Array.isArray(saved) ? saved.filter(id => validIds.has(id)) : []);
      updateFavorites();
      applyFilters();
    } catch {
      // Ignore invalid state from another tab.
    }
  });

  function updateDate() {
    const now = new Date();
    const timezone = 'Europe/Brussels';
    const hour = Number(new Intl.DateTimeFormat('nl-BE', { timeZone: timezone, hour: 'numeric', hourCycle: 'h23' }).format(now));
    document.getElementById('greeting').textContent = `${hour < 12 ? 'Goedemorgen' : hour < 18 ? 'Goedemiddag' : 'Goedenavond'}, team Aerts.`;
    const dateElement = document.getElementById('current-date');
    dateElement.textContent = new Intl.DateTimeFormat('nl-BE', { timeZone: timezone, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(now);
    // Match the machine-readable date to Brussels too, including near midnight.
    dateElement.dateTime = new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
  }

  updateFavorites();
  applyFilters(false);
  document.querySelectorAll('[data-enhanced-only]').forEach(element => { element.hidden = false; });
  updateDate();
  window.setInterval(updateDate, 60000);
})();
