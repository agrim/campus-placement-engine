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
    button.setAttribute('aria-pressed', paused ? 'true' : 'false');
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
