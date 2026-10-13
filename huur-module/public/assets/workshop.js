(() => {
  const board = document.getElementById('board');
  const status = document.getElementById('refresh-status');
  const button = document.getElementById('refresh');
  let busy = false;
  let locked = false;
  async function refresh() {
    if (busy || locked) return;
    busy = true;
    button.disabled = true;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
      const url = new URL(window.location.href);
      url.searchParams.set('refresh', '1');
      const response = await fetch(url, {cache: 'no-store', credentials: 'same-origin', signal: controller.signal});
      if (response.status === 401 || response.status === 403 || response.redirected) {
        locked = true;
        board.replaceChildren();
        status.textContent = 'Toegang verlopen. Ga naar Planning om opnieuw aan te melden.';
        status.classList.add('stale');
        return;
      }
      if (!response.ok) throw new Error('Refresh failed');
      const data = await response.json();
      if (typeof data.html !== 'string' || typeof data.updated !== 'string') throw new Error('Invalid response');
      const positions = [...board.querySelectorAll('.board-list')].map(el => el.scrollTop);
      const focused = document.activeElement;
      const focusedHref = board.contains(focused) ? focused.getAttribute('href') : null;
      board.innerHTML = data.html;
      board.querySelectorAll('.board-list').forEach((el, i) => { el.scrollTop = positions[i] || 0; });
      if (focusedHref) [...board.querySelectorAll('a')].find(el => el.getAttribute('href') === focusedHref)?.focus({preventScroll: true});
      status.textContent = `Bijgewerkt: ${data.updated} · vernieuwt elke 60 seconden`;
      status.classList.remove('stale');
    } catch {
      status.textContent = 'Niet bijgewerkt — getoonde gegevens kunnen verouderd zijn. Nieuwe poging binnen 60 seconden.';
      status.classList.add('stale');
    } finally {
      clearTimeout(timeout);
      busy = false;
      button.disabled = locked;
    }
  }
  button.addEventListener('click', refresh);
  setInterval(refresh, 60000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
  window.addEventListener('online', refresh);
  const full = document.getElementById('fullscreen');
  if (!document.fullscreenEnabled) full.hidden = true;
  full.addEventListener('click', async () => {
    try {
      if (document.fullscreenElement) await document.exitFullscreen();
      else await document.documentElement.requestFullscreen();
    } catch { full.textContent = 'Gebruik F11 voor volledig scherm'; }
  });
  document.addEventListener('fullscreenchange', () => { full.textContent = document.fullscreenElement ? 'Volledig scherm sluiten' : 'Volledig scherm'; });
})();
