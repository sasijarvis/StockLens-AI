// ── analysis.js — drives the analysis page ──────────────────────

const app = document.getElementById('analysisApp');
const SYMBOL = app?.dataset.symbol || '';
let analysisData = null;
let inWatchlist  = false;

document.addEventListener('DOMContentLoaded', () => {
  if (!SYMBOL) return;
  loadAnalysis(false);
});

async function loadAnalysis(force = false) {
  showLoading();
  animateProgressSteps();

  try {
    const url = `/api/analyse?symbol=${encodeURIComponent(SYMBOL)}${force ? '&force=1' : ''}`;
    const res  = await fetch(url);
    const json = await res.json();

    if (!res.ok || json.error) {
      showError(json.error || 'Analysis failed. Please try again.');
      return;
    }

    analysisData = json.data;
    renderAll(json.data, json.status === 'cached', json.cached_at);

  } catch (e) {
    showError('Network error. Please check your connection and try again.');
    console.error(e);
  }
}

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
  const steps = ['price', 'fundamentals', 'charts', 'news', 'ai'];
  let i = 0;
  const msgs = [
    'Fetching live price from NSE…',
    'Loading financial statements…',
    'Pulling 5 years of chart data…',
    'Scanning latest news…',
    'Running AI analysis — this takes ~10 seconds…',
  ];
  const interval = setInterval(() => {
    if (i > 0) {
      const prev = document.querySelector(`[data-step="${steps[i-1]}"]`);
      prev?.classList.remove('active');
      prev?.classList.add('done');
    }
    if (i < steps.length) {
      const cur = document.querySelector(`[data-step="${steps[i]}"]`);
      cur?.classList.add('active');
      document.getElementById('loadingMsg').textContent = msgs[i];
      i++;
    } else {
      clearInterval(interval);
    }
  }, 1800);
  window._progressInterval = interval;
}

// ── Render everything ──
function renderAll(data, fromCache, cachedAt) {
  clearInterval(window._progressInterval);

  renderHeader(data);
  renderSections(data);
  renderCharts(data);
  renderPeers(data);
  renderNews(data);
  checkWatchlist(data);

  // Cache note
  if (fromCache && cachedAt) {
    const t = new Date(cachedAt);
    const mins = Math.round((Date.now() - t.getTime()) / 60000);
    document.getElementById('cacheNote').textContent = `Cached ${mins}m ago`;
  }

  showMain();

  // Auto-refresh price after 5 minutes
  setTimeout(() => refreshPrice(), 300000);
}

function renderHeader(data) {
  document.getElementById('stockName').textContent   = data.company_name + ' (' + data.symbol + ')';
  document.getElementById('stockSector').textContent = data.sector || '';

  const p = data.price_data;
  if (p) {
    const price  = Number(p.last_price);
    const change = Number(p.change_pct);

    document.getElementById('stockPrice').textContent = '₹' + fmtNum(price);
    const changeEl = document.getElementById('stockChange');
    changeEl.textContent = fmtPct(change);
    changeEl.className   = 'stock-header__change num ' + colourClass(change);

    // Metrics strip — field names from actual API response
    const set = (id, val, cls) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.textContent = val;
      if (cls) el.className = 'metric-box__value num ' + cls;
    };

    set('m52wHigh',  p.week_high  ? '₹' + fmtNum(p.week_high, 2)  : 'N/A');
    set('m52wLow',   p.week_low   ? '₹' + fmtNum(p.week_low,  2)  : 'N/A');
    set('mPE',       p.pe_ratio   ? fmtNum(p.pe_ratio,   1) + 'x' : 'N/A');
    set('mSectorPE', p.sector_pe  ? fmtNum(p.sector_pe,  1) + 'x' : 'N/A');
    set('mMktCap',   p.market_cap_cr ? fmtCr(p.market_cap_cr)      : 'N/A');
    set('mDivYield', 'N/A'); // not in price_data directly

    // RSI from metrics_json
    try {
      const m   = JSON.parse(data.metrics_json || '{}');
      const rsi = m.rsi_14;
      const rsiCls = rsi > 70 ? 'num--down' : rsi < 30 ? 'num--up' : 'num--neutral';
      set('mRSI', rsi != null ? fmtNum(rsi, 1) : 'N/A', rsiCls);
    } catch { set('mRSI', 'N/A'); }
  }

  // Model badge
  if (data.model) {
    const shortModel = data.model.split('/').pop() || data.model;
    document.getElementById('modelBadge').textContent = shortModel;
  }
}

function renderSections(data) {
  const verdict = (data.verdict || 'Hold').toLowerCase();
  const vc = verdict === 'buy' ? 'buy' : verdict === 'avoid' ? 'avoid' : 'hold';
  const icons = { buy: '▲', hold: '●', avoid: '▼' };
  const confidence = data.confidence ?? 50;

  // Verdict card
  const verdictCard = document.getElementById('verdictCard');
  verdictCard.className = `analysis-card verdict-card analysis-card--${vc}`;
  document.getElementById('verdictLabel').textContent = data.verdict || 'Hold';
  document.getElementById('verdictLabel').style.color = `var(--c-${vc})`;

  const badge = document.getElementById('verdictBadge');
  badge.className = `badge badge--${vc}`;
  badge.textContent = `${icons[vc]} ${data.verdict || 'Hold'}`;

  document.getElementById('verdictBody').innerHTML = mdToHtml(data.sections?.verdict || '');

  // Confidence bar
  const confWrap = document.getElementById('confidenceWrap');
  confWrap.style.display = '';
  document.getElementById('confidenceScore').textContent = confidence + '/100';
  setTimeout(() => {
    document.getElementById('confidenceFill').style.width = confidence + '%';
  }, 300);

  // Other sections
  const sectionMap = {
    'price_and_technical_setup': 'section-price_and_technical_setup',
    'valuation':                 'section-valuation',
    'business_quality':          'section-business_quality',
    'balance_sheet_health':      'section-balance_sheet_health',
    'cash_flow_quality':         'section-cash_flow_quality',
    'catalysts_and_risks':       'section-catalysts_and_risks',
    'confidence_score':          'section-confidence_score',
  };

  for (const [key, elId] of Object.entries(sectionMap)) {
    const el = document.getElementById(elId);
    if (!el) continue;
    const body = el.querySelector('.analysis-card__body');
    if (!body) continue;
    const text = data.sections?.[key];
    if (text) {
      body.innerHTML = mdToHtml(text);
    } else {
      body.innerHTML = '<span style="color:var(--c-text3)">Data not available for this section.</span>';
    }
  }
}

// Minimal markdown → HTML (bold, paragraphs)
function mdToHtml(text) {
  if (!text) return '';
  return text
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.+?)\*/g, '<em>$1</em>')
    .replace(/\n\n+/g, '</p><p>')
    .replace(/^/, '<p>')
    .replace(/$/, '</p>');
}

function renderCharts(data) {
  if (!data.chart_data) return;
  window._chartData = data.chart_data;
  window._cashflowData = data.chart_data;

  if (typeof initCharts === 'function') {
    try { initCharts(data.chart_data); } catch(e) { console.warn('Chart init error:', e); }
  }
}

function renderPeers(data) {
  const tbody = document.getElementById('peersBody');
  if (!tbody) return;

  // peers from the API is the NSE industryInfo object {macro, sector, industry, basicIndustry}
  // Actual peer stock rows are not available via this API — show a message
  const peers = data.peers;
  if (!peers || Array.isArray(peers) && !peers.length) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--c-text3);padding:20px;">Peer comparison data not available for this symbol via NSE.</td></tr>';
    return;
  }

  // If peers is an object (industry info) display sector context
  if (!Array.isArray(peers)) {
    tbody.innerHTML = `
      <tr>
        <td colspan="8" style="padding:20px;">
          <div style="color:var(--c-text2);font-size:0.875rem;">
            <strong>Sector:</strong> ${peers.sector || 'N/A'} &nbsp;|&nbsp;
            <strong>Industry:</strong> ${peers.industry || 'N/A'} &nbsp;|&nbsp;
            <strong>Category:</strong> ${peers.basicIndustry || 'N/A'}
          </div>
          <div style="color:var(--c-text3);font-size:0.8rem;margin-top:8px;">
            Live peer comparison requires NSE sector index data. Use the <a href="/compare" style="color:var(--c-primary);">Compare page</a> to compare specific stocks side by side.
          </div>
        </td>
      </tr>`;
    return;
  }

  // If it IS an array of peer stocks
  tbody.innerHTML = peers.slice(0, 6).map(p => {
    const chg = Number(p.pChange || p.change_pct || 0);
    return `
      <tr>
        <td><a href="/analysis/${p.symbol}" style="color:var(--c-text);">${p.companyName || p.company_name || p.symbol}</a></td>
        <td class="num" style="color:var(--c-primary);">${p.symbol}</td>
        <td class="num">₹${fmtNum(p.lastPrice || p.last_price, 2)}</td>
        <td class="num ${chg >= 0 ? 'td-up' : 'td-down'}">${fmtPct(chg)}</td>
        <td class="num">${fmtCr(p.marketCap || p.market_cap_cr)}</td>
        <td class="num">${p.pe || p.pe_ratio ? fmtNum(p.pe || p.pe_ratio, 1) + 'x' : 'N/A'}</td>
        <td class="num">₹${fmtNum(p.yearHigh || p.week_high, 2)}</td>
        <td class="num">₹${fmtNum(p.yearLow  || p.week_low,  2)}</td>
      </tr>`;
  }).join('');
}

function renderNews(data) {
  const list = document.getElementById('newsList');
  if (!list) return;
  const news = data.news;
  if (!news || !news.length) {
    list.innerHTML = '<div style="text-align:center;padding:24px;color:var(--c-text3);">No recent news found.</div>';
    return;
  }
  list.innerHTML = news.map((n, i) => `
    <a href="${n.link || '#'}" target="_blank" rel="noopener" class="news-item">
      <span class="news-item__num">${String(i + 1).padStart(2, '0')}</span>
      <div class="news-item__body">
        <div class="news-item__title">${n.title || ''}</div>
        <div class="news-item__meta">
          <span class="news-item__source">${n.source || ''}</span>
          <span>${n.published_at ? formatNewsDate(n.published_at) : ''}</span>
        </div>
      </div>
      <span style="color:var(--c-text3);margin-top:2px;">↗</span>
    </a>
  `).join('');
}

function formatNewsDate(dt) {
  try {
    const d = new Date(dt);
    const diff = (Date.now() - d.getTime()) / 1000;
    if (diff < 3600)  return Math.round(diff / 60) + 'm ago';
    if (diff < 86400) return Math.round(diff / 3600) + 'h ago';
    return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
  } catch { return ''; }
}

async function checkWatchlist(data) {
  if (!data) return;
  try {
    const companyId = data.id; // analysis row id — not ideal; better to use company ID
    const res  = await fetch(`/api/watchlist?action=check&symbol=${encodeURIComponent(SYMBOL)}`);
    const json = await res.json();
    inWatchlist = json.in_watchlist;
    updateWatchlistBtn();
  } catch { }
}

function updateWatchlistBtn() {
  const btn = document.getElementById('watchlistBtn');
  if (!btn) return;
  btn.textContent = inWatchlist ? '★ In Watchlist' : '☆ Add to Watchlist';
  btn.style.color  = inWatchlist ? 'var(--c-buy)' : '';
}

async function toggleWatchlist() {
  if (!analysisData) return;
  const action = inWatchlist ? 'remove' : 'add';
  const company = Company_findBySymbol(SYMBOL) || { id: analysisData.company_id };

  try {
    const body = new URLSearchParams({ action, symbol: SYMBOL });
    const res  = await fetch('/api/watchlist', { method: 'POST', body });
    const json = await res.json();
    inWatchlist = json.in_watchlist;
    updateWatchlistBtn();
    showToast(inWatchlist ? 'Added to watchlist' : 'Removed from watchlist', 'success');
  } catch {
    showToast('Failed to update watchlist', 'error');
  }
}

// Workaround — pass company_id in the analyse response
async function Company_findBySymbol(sym) { return null; }

function reanalyse() {
  if (!confirm('Re-analyse ' + SYMBOL + '? This will fetch fresh data and use an AI credit.')) return;
  loadAnalysis(true);
}

async function refreshPrice() {
  if (!SYMBOL) return;
  try {
    const res  = await fetch(`/api/prices?symbols=${SYMBOL}`);
    const json = await res.json();
    const p    = json[SYMBOL];
    if (!p) return;
    document.getElementById('stockPrice').textContent = '₹' + fmtNum(p.price);
    const changeEl = document.getElementById('stockChange');
    changeEl.textContent = fmtPct(p.change_pct);
    changeEl.className   = 'stock-header__change num ' + colourClass(p.change_pct);
  } catch { }
}
