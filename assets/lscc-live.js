/* Light Show Car Counter - optional live numbers for [car_counter live="true"] */
(function () {
  'use strict';
  if (!window.LSCC_LIVE || !window.fetch) { return; }
  var els = document.querySelectorAll('[data-lscc-live]');
  if (!els.length) { return; }

  function update() {
    if (document.hidden) { return; }
    fetch(LSCC_LIVE.url, { cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) { return; }
        Array.prototype.forEach.call(els, function (el) {
          var key = el.getAttribute('data-lscc-live');
          if (typeof d[key] !== 'number') { return; }
          el.textContent = el.getAttribute('data-lscc-format') === 'raw' ? String(d[key]) : d[key].toLocaleString();
        });
      })
      .catch(function () {});
  }
  setInterval(update, (LSCC_LIVE.interval || 60) * 1000);
  document.addEventListener('visibilitychange', update);
  update();
})();
