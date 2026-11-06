(() => {
  'use strict';
  const form = document.getElementById('form-editor');
  const fields = document.getElementById('form-fields');
  const add = document.getElementById('add-field');
  const preview = document.getElementById('preview-form');
  const output = document.getElementById('form-preview');
  const previewFields = document.getElementById('preview-fields');
  if (!form || !fields || !add || !preview || !output || !previewFields) return;

  function rows() {
    return [...fields.querySelectorAll('fieldset.form-field')];
  }
  function renumber() {
    for (const [i, row] of rows().entries()) {
      row.querySelector('legend').textContent = '項目 ' + (i + 1);
      for (const input of row.querySelectorAll('[name^="fields["]')) {
        input.name = input.name.replace(/^fields\[\d+\]/, 'fields[' + i + ']');
      }
    }
    add.disabled = rows().length >= 40;
  }
  function input(row, suffix) {
    return row.querySelector('[name$="[' + suffix + ']"]');
  }
  add.addEventListener('click', () => {
    const all = rows();
    if (!all.length || all.length >= 40) return;
    const row = all[all.length - 1].cloneNode(true);
    for (const element of row.querySelectorAll('input,select,textarea')) {
      if (element.type === 'checkbox') element.checked = false;
      else if (element.tagName === 'SELECT') element.selectedIndex = 0;
      else element.value = '';
    }
    fields.append(row);
    renumber();
    input(row, 'label').focus();
  });
  fields.addEventListener('click', event => {
    const button = event.target.closest('button[data-move]');
    if (!button) return;
    const row = button.closest('fieldset.form-field');
    if (!row) return;
    const direction = button.dataset.move;
    if (direction === 'up' && row.previousElementSibling) {
      fields.insertBefore(row, row.previousElementSibling);
    } else if (direction === 'down' && row.nextElementSibling) {
      fields.insertBefore(row.nextElementSibling, row);
    }
    renumber();
  });
  fields.addEventListener('change', event => {
    if (!event.target.matches('[name$="[comment_body]"]') || !event.target.checked) return;
    const selected = event.target.closest('fieldset.form-field');
    for (const row of rows()) {
      const body = input(row, 'comment_body');
      if (row !== selected && body) body.checked = false;
    }
    const required = input(selected, 'required');
    if (required) required.checked = true;
    const type = input(selected, 'type');
    if (type && !['text','textarea'].includes(type.value)) type.value = 'textarea';
  });

  preview.addEventListener('click', () => {
    previewFields.replaceChildren();
    const title = document.createElement('h3');
    title.textContent = form.querySelector('[name="title"]').value;
    previewFields.append(title);
    const description = document.createElement('p');
    description.textContent = form.querySelector('[name="description"]').value;
    previewFields.append(description);
    for (const row of rows()) {
      if (input(row, 'omit')?.checked) continue;
      const labelText = input(row, 'label')?.value.trim();
      const key = input(row, 'key')?.value.trim();
      if (!labelText && !key) continue;
      const label = document.createElement('label');
      label.textContent = labelText + (input(row, 'required')?.checked ? '（必須）' : '');
      previewFields.append(label);
      const note = input(row, 'description')?.value;
      if (note) {
        const small = document.createElement('small');
        small.textContent = note;
        previewFields.append(small);
      }
      const type = input(row, 'type')?.value || 'text';
      let control;
      if (type === 'textarea') control = document.createElement('textarea');
      else if (['radio','select'].includes(type)) {
        control = document.createElement('select');
        for (const value of (input(row, 'options')?.value || '').split(/\r?\n/).filter(Boolean)) {
          const option = document.createElement('option');
          option.textContent = value;
          control.append(option);
        }
      } else {
        control = document.createElement('input');
        control.type = type === 'checkbox' ? 'checkbox' : type === 'email' ? 'email' : 'text';
      }
      control.disabled = true;
      control.placeholder = input(row, 'placeholder')?.value || '';
      previewFields.append(control);
    }
    const submit = document.createElement('button');
    submit.type = 'button';
    submit.disabled = true;
    submit.textContent = form.querySelector('[name="submit_label"]').value;
    previewFields.append(submit);
    output.hidden = false;
    output.scrollIntoView({behavior: 'smooth', block:'start'});
  });
  form.addEventListener('submit', () => {
    renumber();
  });
  renumber();
})();
