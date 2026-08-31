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
  var status = refreshControl.querySelector('[data-board-refresh-status]');
  if (!Number.isFinite(seconds) || seconds < 1 || !button || !status) return;

  var remaining = seconds;
  var paused = false;
  function renderRefreshState() {
    button.textContent = paused ? 'Resume automatic refresh' : 'Pause automatic refresh';
    button.setAttribute('aria-pressed', paused ? 'true' : 'false');
    status.textContent = paused
      ? 'Automatic board refresh paused.'
      : 'Next board refresh in ' + remaining + ' seconds.';
  }
  button.addEventListener('click', function () {
    paused = !paused;
    if (!paused) remaining = seconds;
    renderRefreshState();
  });
  window.setInterval(function () {
    if (paused || document.hidden) return;
    remaining -= 1;
    if (remaining <= 0) {
      window.location.reload();
      return;
    }
    renderRefreshState();
  }, 1000);
  renderRefreshState();
})();
