(function () {
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form.matches('[data-confirm]')) return;
    if (!window.confirm(form.getAttribute('data-confirm'))) {
      event.preventDefault();
    }
  });

  var refreshControl = document.querySelector('[data-board-refresh-seconds]');
  if (!refreshControl) return;
  var seconds = Number.parseInt(refreshControl.getAttribute('data-board-refresh-seconds'), 10);
  var button = refreshControl.querySelector('[data-board-refresh-toggle]');
  var countdown = refreshControl.querySelector('[data-board-refresh-countdown]');
  var announcement = refreshControl.querySelector('[data-board-refresh-announcement]');
  if (!Number.isFinite(seconds) || seconds < 1 || !button || !countdown || !announcement) return;

  var remaining = seconds;
  var paused = false;
  function renderRefreshState(message) {
    button.textContent = paused ? 'Resume automatic refresh' : 'Pause automatic refresh';
    countdown.textContent = paused
      ? 'Automatic board refresh paused.'
      : 'Next board refresh in ' + remaining + ' seconds.';
    if (message) announcement.textContent = message;
  }
  button.addEventListener('click', function () {
    paused = !paused;
    if (!paused) remaining = seconds;
    renderRefreshState(paused
      ? 'Automatic board refresh paused.'
      : 'Automatic board refresh resumed. Next board refresh in ' + remaining + ' seconds.');
  });
  window.setInterval(function () {
    if (paused || document.hidden) return;
    remaining -= 1;
    if (remaining <= 0) {
      window.location.reload();
      return;
    }
    renderRefreshState(remaining === 10 ? 'Automatic board refresh in 10 seconds.' : '');
  }, 1000);
  renderRefreshState('');
})();

// Progressive enhancement is opt-in; native constraints and server validation stay authoritative.
(function () {
  var forms = document.querySelectorAll('form[data-field-validation]');
  if (!forms.length) return;
  var announcer = document.createElement('div');
  announcer.className = 'visually-hidden';
  announcer.setAttribute('aria-live', 'polite');
  announcer.setAttribute('aria-atomic', 'true');
  document.body.appendChild(announcer);
  var timer;
  var announcedField;

  forms.forEach(function (form) {
    var states = new Map();
    form.querySelectorAll('input[data-error], select[data-error]').forEach(function (field) {
      var error = document.getElementById(field.getAttribute('data-error'));
      // Do not replace independently authored server errors.
      if (!error || field.getAttribute('aria-invalid') === 'true') return;
      states.set(field, { error: error, dirty: false, announced: false });
      syncMinimumSelections(field);
      field.setAttribute('aria-invalid', 'false');
    });
    function syncMinimumSelections(field) {
      var minimum = Number(field.getAttribute('data-min-selections'));
      if (field.tagName !== 'SELECT' || !field.multiple || !Number.isInteger(minimum) || minimum < 1) return;
      var state = states.get(field);
      field.setCustomValidity(field.selectedOptions.length < minimum ? state.error.textContent : '');
    }
    function update(field, invalid, announce) {
      var state = states.get(field);
      if (!state) return;
      state.error.hidden = !invalid;
      field.setAttribute('aria-invalid', invalid ? 'true' : 'false');
      var id = state.error.id;
      var hints = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(function (item) { return item && item !== id; });
      if (invalid) hints.push(id);
      if (hints.length) field.setAttribute('aria-describedby', hints.join(' '));
      else field.removeAttribute('aria-describedby');
      if (!invalid) {
        state.announced = false;
        if (announcedField === field) {
          clearTimeout(timer);
          announcer.textContent = '';
        }
      } else if (announce && !state.announced) {
        clearTimeout(timer);
        announcer.textContent = '';
        announcedField = field;
        state.announced = true;
        // Only fixed DOM labels/messages; never include submitted values or secrets.
        var label = field.labels && field.labels[0] ? field.labels[0].textContent.trim() + ': ' : '';
        timer = setTimeout(function () { announcer.textContent = label + state.error.textContent; }, 500);
      }
    }
    function handle(event) {
      var field = event.target;
      var state = states.get(field);
      if (!state) return;
      if (event.type === 'input' || event.type === 'change') syncMinimumSelections(field);
      if (event.type === 'invalid') {
        clearTimeout(timer);
        announcer.textContent = '';
        state.dirty = true;
        state.announced = true;
        update(field, true, false); // Native validation focuses and describes the first invalid field.
      } else if (event.type === 'blur') {
        if (state.dirty) update(field, !field.validity.valid, true);
      } else {
        state.dirty = true;
        if (event.type === 'change') update(field, !field.validity.valid, true);
        else if (field.validity.valid) update(field, false, false); // Typing only clears errors; defer new errors until blur/change/submit.
      }
    }
    ['input', 'change', 'blur', 'invalid'].forEach(function (name) { form.addEventListener(name, handle, true); });
    form.addEventListener('reset', function (event) {
      // Reset default action happens after event dispatch; honor cancelled resets.
      setTimeout(function () {
        if (event.defaultPrevented) return;
        states.forEach(function (state, field) {
          syncMinimumSelections(field);
          state.dirty = false;
          update(field, false, false);
        });
      }, 0);
    });
  });
})();
