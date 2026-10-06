// Q-Transport — minimal progressive enhancement. All calculations stay server-side.

function riskCat(rpn) {
  var t = window.QT_RPN || { low: 80, med: 250, high: 600 };
  if (rpn <= t.low) return 'low';
  if (rpn <= t.med) return 'medium';
  if (rpn <= t.high) return 'high';
  return 'critical';
}

function recalcRisk(card) {
  var s = parseInt(card.querySelector('[name^="s["]')?.value || card.querySelector('.sc-s')?.value || 0, 10);
  var o = parseInt(card.querySelector('.sc-o')?.value || 0, 10);
  var d = parseInt(card.querySelector('.sc-d')?.value || 0, 10);
  var rpnEl = card.querySelector('.rpnbox');
  if (!rpnEl) return;
  var rpn = (s && o && d) ? s * o * d : 0;
  rpnEl.textContent = rpn;
  var b = card.querySelector('.rpn-badge');
  if (b) {
    var cat = rpn ? riskCat(rpn) : null;
    var map = { low: 'b-low', medium: 'b-med', high: 'b-high', critical: 'b-crit' };
    var labels = { low: 'Low', medium: 'Medium', high: 'High', critical: 'Critical' };
    b.className = 'badge rpn-badge ' + (map[cat] || 'b-neutral');
    b.textContent = cat ? labels[cat] : '—';
  }
}

document.addEventListener('change', function (e) {
  var el = e.target;
  if (el.matches('.sc-s, .sc-o, .sc-d')) {
    var f = el.closest('.f');
    if (f) f.classList.toggle('open', !!el.value);
    var card = el.closest('.rcard');
    if (card) recalcRisk(card);
  }
  if (el.id === 'event_type') {
    var dev = el.value === 'deviation';
    document.querySelectorAll('[data-when-deviation]').forEach(function (n) {
      n.style.display = dev ? '' : 'none';
      n.querySelectorAll('input,select,textarea').forEach(function (i) { i.disabled = !dev && n.dataset.required !== 'always'; });
    });
  }
  if (el.id === 'report_type') {
    var label = document.getElementById('report_target_label');
    var sel = document.getElementById('report_target');
    if (label && sel) {
      var opt = sel.querySelector('option[value="' + el.value + '"]');
      var group = sel.querySelector('option[data-group]');
      Array.prototype.forEach.call(sel.options, function (o) {
        o.hidden = group && o.dataset.group && o.dataset.group !== el.value;
      });
      var first = Array.prototype.filter.call(sel.options, function (o) { return !o.hidden; })[0];
      if (first) sel.value = first.value;
      label.textContent = el.value === 'tlra' ? 'Lane' : (el.value === 'supplier' ? 'Supplier' : (el.value === 'summary' ? 'Cluster' : '—'));
      if (el.value === 'comparison') sel.disabled = true; else sel.disabled = false;
    }
  }
  if (el.id === 'lane_cluster') {
    document.querySelectorAll('[data-cluster]').forEach(function (n) {
      n.style.display = (n.dataset.cluster === el.value || n.dataset.cluster === 'both') ? '' : 'none';
    });
  }
});

document.addEventListener('submit', function (e) {
  var f = e.target;
  if (f.dataset.confirm && !window.confirm(f.dataset.confirm)) e.preventDefault();
  if (f.classList.contains('need-rational')) {
    var missing = Array.prototype.filter.call(f.querySelectorAll('.f.open input'), function (i) { return !i.value.trim(); });
    if (missing.length) {
      e.preventDefault();
      missing[0].focus();
      window.alert('Rationale wajib diisi untuk setiap skor yang dipilih.');
    }
  }
});

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.rcard').forEach(function (c) {
    c.querySelectorAll('.f').forEach(function (f) {
      var sel = f.querySelector('select');
      if (sel && sel.value) f.classList.add('open');
    });
    recalcRisk(c);
  });
  var t = document.getElementById('event_type');
  if (t) t.dispatchEvent(new Event('change'));
  var c = document.getElementById('lane_cluster');
  if (c) c.dispatchEvent(new Event('change'));
  var rt = document.getElementById('report_type');
  if (rt) rt.dispatchEvent(new Event('change'));
});
