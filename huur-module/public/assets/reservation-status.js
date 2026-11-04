'use strict';
// Both status selectors share the same explicit confirmation for a no-show.
document.querySelectorAll('form').forEach(form => {
  const fields = form.querySelector('[data-no-show-fields]');
  const status = form.querySelector('select[name="status"]');
  if (!fields || !status) return;
  const update = () => {
    const selected = status.value === 'no_show';
    fields.hidden = !selected;
    fields.querySelectorAll('input, textarea').forEach(input => { input.disabled = !selected; });
    fields.querySelector('[data-no-show-confirm]').required = selected;
  };
  status.addEventListener('change', update);
  update();
});
