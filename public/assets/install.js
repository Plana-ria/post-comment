(() => {
  'use strict';
  const button = document.getElementById('generate-proof');
  const field = document.getElementById('setup-proof');
  if (!button || !field) return;
  button.addEventListener('click', () => {
    const bytes = new Uint8Array(32);
    crypto.getRandomValues(bytes);
    field.value = [...bytes].map(b => b.toString(16).padStart(2, '0')).join('');
    field.focus();
    field.select();
  });
})();
