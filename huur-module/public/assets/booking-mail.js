'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const dialog = document.querySelector('[data-booking-mail-dialog]');
  const form = document.querySelector('[data-booking-mail-form]');
  const skip = form?.querySelector('[data-booking-mail-skip]');
  if (!dialog || !form || !skip) return;

  let submitting = false;
  const skipMail = () => {
    if (!submitting) form.requestSubmit(skip);
  };
  dialog.addEventListener('cancel', event => {
    event.preventDefault();
    skipMail();
  });
  dialog.addEventListener('click', event => {
    if (event.target !== dialog) return;
    const bounds = dialog.getBoundingClientRect();
    if (event.clientX < bounds.left || event.clientX > bounds.right
      || event.clientY < bounds.top || event.clientY > bounds.bottom) skipMail();
  });

  form.addEventListener('submit', event => {
    if (submitting) {
      event.preventDefault();
      return;
    }
    const choice = event.submitter?.dataset.bookingAction;
    if (choice !== 'send' && choice !== 'skip') {
      event.preventDefault();
      return;
    }
    submitting = true;
    const action = form.querySelector('[data-booking-action-value]');
    action.value = choice;
    action.disabled = false;
    // Keep the explicit action in the POST after disabling all submit buttons.
    document.querySelectorAll('[data-booking-action]').forEach(button => {
      button.removeAttribute('name');
      button.disabled = true;
    });
    form.querySelector('[data-booking-mail-status]').textContent = choice === 'send'
      ? 'Mail wordt verstuurd…'
      : 'Verdergaan zonder mail…';
  });

  if (typeof dialog.showModal === 'function') {
    dialog.close();
    dialog.showModal();
  }
});
