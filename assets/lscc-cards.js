/* Light Show Car Counter - gentle count-up for the number cards */
(function () {
  'use strict';
  var els = document.querySelectorAll('[data-lscc-count]');
  if (!els.length) { return; }
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduce || !('IntersectionObserver' in window) || !window.requestAnimationFrame) { return; }

  function run(el) {
    var end = parseInt(el.getAttribute('data-lscc-count'), 10);
    if (!isFinite(end) || end < 1) { return; }
    var final = el.textContent, dur = Math.min(1400, 500 + Math.log(end + 1) * 140), t0 = null;
    function step(t) {
      if (t0 === null) { t0 = t; }
      var p = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - p, 3);
      el.textContent = p < 1 ? Math.round(end * e).toLocaleString() : final;
      if (p < 1) { window.requestAnimationFrame(step); }
    }
    window.requestAnimationFrame(step);
  }
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) { if (en.isIntersecting) { io.unobserve(en.target); run(en.target); } });
  }, { threshold: 0.3 });
  Array.prototype.forEach.call(els, function (el) { io.observe(el); });
})();
