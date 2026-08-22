(function () {
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form.matches('[data-confirm]')) return;
    if (!window.confirm(form.getAttribute('data-confirm'))) {
      event.preventDefault();
    }
  });
})();
