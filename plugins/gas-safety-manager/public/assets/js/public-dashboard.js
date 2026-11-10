/**
 * Public status dashboard (aggregate-only). External file so the page can run
 * under a strict CSP (script-src 'self', no inline script).
 */
(function () {
  function qs(k){ return new URLSearchParams(location.search).get(k) || ''; }
  var token = qs('t') || qs('token');
  // The API base comes from the URL, so only same-origin values are accepted:
  // a crafted ?api=https://evil.example link must not make this page (on the
  // trusted domain) fetch and display someone else's data, nor send the token away.
  function sameOriginApi(v) {
    var fallback = '/wp-json/gas/v1';
    if (!v) return fallback;
    try {
      var u = new URL(v, location.href);
      if (u.origin !== location.origin || (u.protocol !== 'https:' && u.protocol !== 'http:')) return fallback;
      return u.href;
    } catch (e) { return fallback; }
  }
  var api = sameOriginApi(qs('api'));
  var root = document.getElementById('root');
  if (!token) { root.innerHTML = '<div class="err">חסר אסימון גישה בקישור.</div>'; return; }

  function esc(s){ var d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; }

  function render(d){
    document.getElementById('org').textContent = d.org || 'נתוני מצב מצטברים';
    var rows = (d.by_status||[]).map(function(s){
      return '<tr><td>'+esc(s.label)+'</td><td>'+esc(s.count)+'</td></tr>';
    }).join('');
    root.innerHTML =
      '<div class="grid">' +
        tile(d.total_equipment, 'ציוד במערכת', '') +
        tile((d.scan_coverage_pct||0)+'%', 'כיסוי סריקה (30 ימים)', cls(d.scan_coverage_pct>=70?'ok':(d.scan_coverage_pct>=40?'warn':'danger'))) +
        tile(d.faults_open, 'תקלות פתוחות', cls(d.faults_open>0?'warn':'ok')) +
        tile(d.quarterly_overdue, 'ביקורת רבעונית באיחור', cls(d.quarterly_overdue>0?'danger':'ok')) +
      '</div>' +
      (rows ? '<div class="card"><h2>ציוד לפי סטטוס</h2><table><thead><tr><th>סטטוס</th><th>כמות</th></tr></thead><tbody>'+rows+'</tbody></table></div>' : '') +
      '<div class="foot">עודכן: '+esc(d.generated||'')+'</div>';
  }
  function tile(n,l,c){ return '<div class="tile'+c+'"><div class="n">'+esc(n)+'</div><div class="l">'+esc(l)+'</div></div>'; }
  function cls(c){ return c?(' '+c):''; }

  function fetchJson(url){ return fetch(url, { credentials:'omit' }).then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); }); }

  var url = api.replace(/\/$/,'') + '/bi/public?token=' + encodeURIComponent(token);
  fetchJson(url)
    .then(render)
    .catch(function(){
      // Fallback for sites on "plain" permalinks.
      fetchJson('/?rest_route=/gas/v1/bi/public&token=' + encodeURIComponent(token))
        .then(render)
        .catch(function(e){ root.innerHTML = '<div class="err">טעינת הנתונים נכשלה ('+esc(e.message||e)+'). ייתכן שהאסימון אינו תקף.</div>'; });
    });
})();
