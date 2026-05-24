// ── analysis.js ──────────────────────────────────────────────────────

const app    = document.getElementById('analysisApp');
const SYMBOL = app?.dataset.symbol || '';
let analysisData = null;
let inWatchlist  = false;

// ── Debug panel ──────────────────────────────────────────────────────
const DEBUG = true; // set false to hide panel

function dbg(label, value, status = 'info') {
  if (!DEBUG) return;
  const panel = document.getElementById('debugPanel');
  if (!panel) return;
  const colors = { ok: '#3fb950', warn: '#d29922', error: '#f85149', info: '#8b949e' };
  const row = document.createElement('div');
  row.style.cssText = `display:flex;gap:8px;padding:4px 0;border-bottom:1px solid #2d3748;font-size:12px;font-family:monospace;`;
  row.innerHTML = `
    <span style="color:${colors[status]};min-width:40px;">[${status.toUpperCase()}]</span>
    <span style="color:#e6edf3;min-width:220px;">${label}</span>
    <span style="color:#8b949e;word-break:break-all;">${typeof value === 'object' ? JSON.stringify(value).slice(0,300) : String(value)}</span>
  `;
  panel.appendChild(row);
  panel.scrollTop = panel.scrollHeight;
}

function injectDebugPanel() {
  if (!DEBUG) return;
  const div = document.createElement('div');
  div.innerHTML = `
    <div style="margin:16px 0;background:#0d1117;border:1px solid #f85149;border-radius:10px;overflow:hidden;">
      <div style="background:#1c2330;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;">
        <span style="color:#f85149;font-weight:600;font-size:13px;font-family:monospace;">🐛 DEBUG PANEL</span>
        <button onclick="document.getElementById('debugPanel').parentElement.parentElement.remove()" 
                style="background:none;border:none;color:#8b949e;cursor:pointer;font-size:16px;">✕</button>
      </div>
      <div id="debugPanel" style="padding:12px 16px;max-height:400px;overflow-y:auto;"></div>
    </div>
  `;
  // Insert after loading state
  const main = document.getElementById('analysisApp');
  main?.insertBefore(div, main.firstChild);
}

// ── Boot ─────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  injectDebugPanel();
  if (!SYMBOL) { dbg('SYMBOL', 'EMPTY — no symbol in page', 'error'); return; }
  dbg('SYMBOL', SYMBOL, 'ok');
  loadAnalysis(false);
});

// ── Load ─────────────────────────────────────────────────────────────
async function loadAnalysis(force = false) {
  showLoading();
  animateProgressSteps();

  const url = `/api/analyse?symbol=${encodeURIComponent(SYMBOL)}${force ? '&force=1' : ''}`;
  dbg('Fetching URL', url, 'info');

  try {
    const res  = await fetch(url);
    const json = await res.json();

    dbg('HTTP status', res.status, res.ok ? 'ok' : 'error');
    dbg('Response status', json.status || 'none', 'info');
    dbg('json.error', json.error || 'none', json.error ? 'error' : 'ok');

    if (!res.ok || json.error) {
      showError(json.error || 'Analysis failed.');
      return;
    }

    const data = json.data;
    dbg('data keys', Object.keys(data || {}), 'info');
    dbg('data.verdict', data?.verdict, data?.verdict ? 'ok' : 'warn');
    dbg('data.confidence', data?.confidence, 'info');
    dbg('data.price_data', data?.price_data ? 'present' : 'MISSING', data?.price_data ? 'ok' : 'error');
    dbg('data.price_data.last_price', data?.price_data?.last_price, 'info');
    dbg('data.sections keys', Object.keys(data?.sections || {}), 'info');

    // Check each section
    const sec = data?.sections || {};
    for (const [k,v] of Object.entries(sec)) {
      dbg(`  sections.${k}`, v ? `${String(v).length} chars` : 'NULL', v ? 'ok' : 'warn');
    }

    dbg('data.chart_data', data?.chart_data ? 'present' : 'MISSING', data?.chart_data ? 'ok' : 'error');
    dbg('chart_data keys', Object.keys(data?.chart_data || {}), 'info');

    // Check chart datasets
    const cd = data?.chart_data || {};
    for (const [k, v] of Object.entries(cd)) {
      const ds = v?.datasets || [];
      dbg(`  chart[${k}] datasets`, ds.length + ' datasets', ds.length ? 'ok' : 'warn');
      ds.forEach((d, i) => {
        const vals = d?.values || [];
        dbg(`    dataset[${i}] metric="${d?.metric}" label="${d?.label}"`, `${vals.length} values, first: ${JSON.stringify(vals[0])}`, vals.length ? 'ok' : 'warn');
      });
    }

    dbg('data.news', Array.isArray(data?.news) ? `${data.news.length} items` : 'MISSING', data?.news?.length ? 'ok' : 'warn');
    dbg('data.peers', data?.peers ? (Array.isArray(data.peers) ? `array(${data.peers.length})` : `object: ${JSON.stringify(data.peers).slice(0,80)}`) : 'MISSING', 'info');
    dbg('data.metrics_json', data?.metrics_json ? 'present' : 'MISSING', data?.metrics_json ? 'ok' : 'warn');

    analysisData = data;
    renderAll(data, json.status === 'cached', json.cached_at);

  } catch (e) {
    dbg('EXCEPTION', e.message, 'error');
    showError('Network error: ' + e.message);
    console.error(e);
  }
}

// ── UI state ─────────────────────────────────────────────────────────
function showLoading() {
  document.getElementById('loadingState').style.display = '';
  document.getElementById('errorState').style.display   = 'none';
  document.getElementById('mainContent').style.display  = 'none';
}

function showError(msg) {
  document.getElementById('loadingState').style.display = 'none';
  document.getElementById('errorState').style.display   = '';
  document.getElementById('mainContent').style.display  = 'none';
  document.getElementById('errorMsg').textContent = msg;
}

function showMain() {
  document.getElementById('loadingState').style.display = 'none';
  document.getElementById('errorState').style.display   = 'none';
  document.getElementById('mainContent').style.display  = '';
}

function animateProgressSteps() {
  const steps = ['price','fundamentals','charts','news','ai'];
  const msgs  = [
    'Fetching live price from NSE…',
    'Loading financial statements…',
    'Pulling 5 years of chart data…',
    'Scanning latest news…',
    'Running AI analysis…',
  ];
  let i = 0;
  const interval = setInterval(() => {
    if (i > 0) {
      const prev = document.querySelector(`[data-step="${steps[i-1]}"]`);
      prev?.classList.remove('active'); prev?.classList.add('done');
    }
    if (i < steps.length) {
      document.querySelector(`[data-step="${steps[i]}"]`)?.classList.add('active');
      document.getElementById('loadingMsg').textContent = msgs[i];
      i++;
    } else { clearInterval(interval); }
  }, 1800);
  window._progressInterval = interval;
}

// ── Render all ───────────────────────────────────────────────────────
function renderAll(data, fromCache, cachedAt) {
  clearInterval(window._progressInterval);

  dbg('--- renderAll() ---', '', 'info');

  try { renderHeader(data);   dbg('renderHeader', 'OK', 'ok'); } catch(e) { dbg('renderHeader ERROR', e.message, 'error'); }
  try { renderSections(data); dbg('renderSections', 'OK', 'ok'); } catch(e) { dbg('renderSections ERROR', e.message, 'error'); }
  try { renderCharts(data);   dbg('renderCharts', 'OK', 'ok'); } catch(e) { dbg('renderCharts ERROR', e.message, 'error'); }
  try { renderPeers(data);    dbg('renderPeers', 'OK', 'ok'); } catch(e) { dbg('renderPeers ERROR', e.message, 'error'); }
  try { renderNews(data);     dbg('renderNews', 'OK', 'ok'); } catch(e) { dbg('renderNews ERROR', e.message, 'error'); }
  try { checkWatchlist(data); } catch(e) {}

  if (fromCache && cachedAt) {
    const mins = Math.round((Date.now() - new Date(cachedAt).getTime()) / 60000);
    document.getElementById('cacheNote').textContent = `Cached ${mins}m ago`;
  }

  showMain();
  setTimeout(() => refreshPrice(), 300000);
}

// ── Header ───────────────────────────────────────────────────────────
function renderHeader(data) {
  document.getElementById('stockName').textContent   = data.company_name + ' (' + data.symbol + ')';
  document.getElementById('stockSector').textContent = data.sector || '';

  const p = data.price_data;
  if (!p) { dbg('price_data', 'NULL — skipping header metrics', 'warn'); return; }

  document.getElementById('stockPrice').textContent = '₹' + fmtNum(p.last_price);
  const changeEl = document.getElementById('stockChange');
  changeEl.textContent = fmtPct(p.change_pct);
  changeEl.className   = 'stock-header__change num ' + colourClass(p.change_pct);

  const set = (id, val) => {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
  };
  set('m52wHigh',  p.week_high    ? '₹' + fmtNum(p.week_high, 2)   : 'N/A');
  set('m52wLow',   p.week_low     ? '₹' + fmtNum(p.week_low, 2)    : 'N/A');
  set('mPE',       p.pe_ratio     ? fmtNum(p.pe_ratio, 1) + 'x'    : 'N/A');
  set('mSectorPE', p.sector_pe    ? fmtNum(p.sector_pe, 1) + 'x'   : 'N/A');
  set('mMktCap',   p.market_cap_cr ? fmtCr(p.market_cap_cr)         : 'N/A');
  set('mDivYield', 'N/A');

  try {
    const m   = JSON.parse(data.metrics_json || '{}');
    const rsi = m.rsi_14;
    const el  = document.getElementById('mRSI');
    if (el) {
      el.textContent = rsi != null ? fmtNum(rsi, 1) : 'N/A';
      el.className   = 'metric-box__value num ' + (rsi > 70 ? 'num--down' : rsi < 30 ? 'num--up' : 'num--neutral');
    }
  } catch(e) { dbg('metrics_json parse error', e.message, 'error'); }

  if (data.model) {
    document.getElementById('modelBadge').textContent = data.model.split('/').pop() || data.model;
  }
}

// ── AI Sections ───────────────────────────────────────────────────────
function renderSections(data) {
  const verdict    = (data.verdict || 'Hold').toLowerCase();
  const vc         = verdict === 'buy' ? 'buy' : verdict === 'avoid' ? 'avoid' : 'hold';
  const confidence = data.confidence ?? 50;
  const icons      = { buy:'▲', hold:'●', avoid:'▼' };

  document.getElementById('verdictCard').className  = `analysis-card verdict-card analysis-card--${vc}`;
  document.getElementById('verdictLabel').textContent = data.verdict || 'Hold';
  document.getElementById('verdictLabel').style.color = `var(--c-${vc})`;

  const badge = document.getElementById('verdictBadge');
  badge.className   = `badge badge--${vc}`;
  badge.textContent = `${icons[vc]} ${data.verdict || 'Hold'}`;

  document.getElementById('verdictBody').innerHTML = mdToHtml(data.sections?.verdict || '');

  document.getElementById('confidenceWrap').style.display = '';
  document.getElementById('confidenceScore').textContent  = confidence + '/100';
  setTimeout(() => { document.getElementById('confidenceFill').style.width = confidence + '%'; }, 300);

  // Map section key → card element ID
  const sectionMap = {
    'price_and_technical_setup' : 'section-price_and_technical_setup',
    'valuation'                 : 'section-valuation',
    'business_quality'          : 'section-business_quality',
    'balance_sheet_health'      : 'section-balance_sheet_health',
    'cash_flow_quality'         : 'section-cash_flow_quality',
    'catalysts_and_risks'       : 'section-catalysts_and_risks',
    'confidence_score'          : 'section-confidence_score',
  };

  for (const [key, elId] of Object.entries(sectionMap)) {
    const el   = document.getElementById(elId);
    if (!el) { dbg(`section card missing: #${elId}`, '', 'warn'); continue; }
    const body = el.querySelector('.analysis-card__body');
    if (!body) continue;
    const text = data.sections?.[key];
    dbg(`section[${key}]`, text ? `${text.length} chars` : 'NULL — showing fallback', text ? 'ok' : 'warn');
    body.innerHTML = text
      ? mdToHtml(text)
      : `<span style="color:var(--c-text3)">No data for this section. Check debug panel → sections keys above.</span>`;
  }
}

function mdToHtml(text) {
  if (!text) return '';
  return text
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.+?)\*/g, '<em>$1</em>')
    .replace(/\n\n+/g, '</p><p>')
    .replace(/^/, '<p>').replace(/$/, '</p>');
}

// ── Charts ───────────────────────────────────────────────────────────
function renderCharts(data) {
  const cd = data.chart_data;
  if (!cd || !Object.keys(cd).length) {
    dbg('chart_data', 'EMPTY — no charts to render', 'warn');
    return;
  }

  dbg('Chart.js available', typeof Chart !== 'undefined' ? 'YES' : 'NO', typeof Chart !== 'undefined' ? 'ok' : 'error');
  dbg('initCharts available', typeof initCharts === 'function' ? 'YES' : 'NO', typeof initCharts === 'function' ? 'ok' : 'error');

  window._rawChartData = cd;

  const tryInit = (attempts) => {
    if (typeof Chart !== 'undefined' && typeof initCharts === 'function') {
      dbg('initCharts()', 'calling now', 'ok');
      try {
        initCharts(cd);
        dbg('initCharts()', 'completed', 'ok');
      } catch(e) {
        dbg('initCharts() ERROR', e.message, 'error');
        console.error('Chart init error:', e);
      }
    } else if (attempts > 0) {
      dbg('initCharts retry', `${attempts} attempts left`, 'warn');
      setTimeout(() => tryInit(attempts - 1), 400);
    } else {
      dbg('Chart.js NEVER loaded', 'Check CDN / network', 'error');
    }
  };
  tryInit(15);
}

// ── Peers ────────────────────────────────────────────────────────────
function renderPeers(data) {
  const tbody = document.getElementById('peersBody');
  if (!tbody) return;
  const peers = data.peers;

  dbg('peers type', peers ? (Array.isArray(peers) ? 'array' : 'object') : 'NULL', peers ? 'info' : 'warn');

  if (!peers) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--c-text3);padding:20px;">No peer data returned.</td></tr>';
    return;
  }

  if (!Array.isArray(peers)) {
    tbody.innerHTML = `
      <tr><td colspan="8" style="padding:20px;">
        <div style="color:var(--c-text2);font-size:0.875rem;">
          <strong>Sector:</strong> ${peers.sector||'N/A'} &nbsp;|&nbsp;
          <strong>Industry:</strong> ${peers.industry||'N/A'} &nbsp;|&nbsp;
          <strong>Category:</strong> ${peers.basicIndustry||'N/A'}
        </div>
        <div style="color:var(--c-text3);font-size:0.8rem;margin-top:8px;">
          NSE returns industry labels only, not peer stock rows. Use the 
          <a href="/compare" style="color:var(--c-primary);">Compare page</a> to compare stocks side by side.
        </div>
      </td></tr>`;
    return;
  }

  tbody.innerHTML = peers.slice(0,6).map(p => {
    const chg = Number(p.pChange || p.change_pct || 0);
    return `<tr>
      <td><a href="/analysis/${p.symbol}" style="color:var(--c-text);">${p.companyName||p.symbol}</a></td>
      <td class="num" style="color:var(--c-primary);">${p.symbol}</td>
      <td class="num">₹${fmtNum(p.lastPrice||p.last_price, 2)}</td>
      <td class="num ${chg>=0?'td-up':'td-down'}">${fmtPct(chg)}</td>
      <td class="num">${fmtCr(p.marketCap||p.market_cap_cr)}</td>
      <td class="num">${p.pe||p.pe_ratio ? fmtNum(p.pe||p.pe_ratio,1)+'x' : 'N/A'}</td>
      <td class="num">₹${fmtNum(p.yearHigh||p.week_high,2)}</td>
      <td class="num">₹${fmtNum(p.yearLow||p.week_low,2)}</td>
    </tr>`;
  }).join('');
}

// ── News ─────────────────────────────────────────────────────────────
function renderNews(data) {
  const list = document.getElementById('newsList');
  if (!list) return;
  const news = data.news;
  if (!news?.length) {
    list.innerHTML = '<div style="text-align:center;padding:24px;color:var(--c-text3);">No recent news found.</div>';
    return;
  }
  list.innerHTML = news.map((n, i) => `
    <a href="${n.link||'#'}" target="_blank" rel="noopener" class="news-item">
      <span class="news-item__num">${String(i+1).padStart(2,'0')}</span>
      <div class="news-item__body">
        <div class="news-item__title">${n.title||''}</div>
        <div class="news-item__meta">
          <span class="news-item__source">${n.source||''}</span>
          <span>${n.published_at ? formatNewsDate(n.published_at) : ''}</span>
        </div>
      </div>
      <span style="color:var(--c-text3);margin-top:2px;">↗</span>
    </a>`).join('');
}

function formatNewsDate(dt) {
  try {
    const d    = new Date(dt);
    const diff = (Date.now() - d.getTime()) / 1000;
    if (diff < 3600)  return Math.round(diff/60)+'m ago';
    if (diff < 86400) return Math.round(diff/3600)+'h ago';
    return d.toLocaleDateString('en-IN', { day:'numeric', month:'short' });
  } catch { return ''; }
}

// ── Watchlist ────────────────────────────────────────────────────────
async function checkWatchlist() {
  try {
    const res  = await fetch(`/api/watchlist?action=check&symbol=${encodeURIComponent(SYMBOL)}`);
    const json = await res.json();
    inWatchlist = json.in_watchlist;
    updateWatchlistBtn();
  } catch {}
}

function updateWatchlistBtn() {
  const btn = document.getElementById('watchlistBtn');
  if (!btn) return;
  btn.textContent = inWatchlist ? '★ In Watchlist' : '☆ Add to Watchlist';
  btn.style.color = inWatchlist ? 'var(--c-buy)' : '';
}

async function toggleWatchlist() {
  if (!analysisData) return;
  try {
    const body = new URLSearchParams({ action: inWatchlist ? 'remove' : 'add', symbol: SYMBOL });
    const res  = await fetch('/api/watchlist', { method:'POST', body });
    const json = await res.json();
    inWatchlist = json.in_watchlist;
    updateWatchlistBtn();
    showToast(inWatchlist ? 'Added to watchlist' : 'Removed from watchlist', 'success');
  } catch { showToast('Failed to update watchlist', 'error'); }
}

function reanalyse() {
  if (!confirm('Re-analyse ' + SYMBOL + '? This will fetch fresh data and use an AI credit.')) return;
  loadAnalysis(true);
}

async function refreshPrice() {
  try {
    const res  = await fetch(`/api/prices?symbols=${SYMBOL}`);
    const json = await res.json();
    const p    = json[SYMBOL];
    if (!p) return;
    document.getElementById('stockPrice').textContent = '₹' + fmtNum(p.price);
    const el = document.getElementById('stockChange');
    el.textContent = fmtPct(p.change_pct);
    el.className   = 'stock-header__change num ' + colourClass(p.change_pct);
  } catch {}
}
