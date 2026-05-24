// StockLens AI — analysis.js (5-step sequential pipeline)
const app    = document.getElementById('analysisApp');
const SYMBOL = app?.dataset.symbol || '';
const BASE   = window.APP_BASE || '';
let analysisData = null;
let inWatchlist  = false;
let _pollTimer   = null;

document.addEventListener('DOMContentLoaded', () => {
  if (!SYMBOL) return;
  const savedModel = localStorage.getItem('selectedModel');
  const modelSel   = document.getElementById('modelSelector');
  if (modelSel && savedModel) modelSel.value = savedModel;
  loadAnalysis(false);
});

// ── 5-step sequential pipeline ────────────────────────────────────────────────
async function loadAnalysis(force = false) {
  stopPolling();
  showLoading('Starting analysis...');

  const model = document.getElementById('modelSelector')?.value || localStorage.getItem('selectedModel') || 'best';
  const f     = force ? '&force=1' : '';
  const base  = `${BASE}/api/analyse_steps?symbol=${encodeURIComponent(SYMBOL)}${f}`;

  try {

    // Step 1: Company resolve + NSE price
    updateProgressUI('screener_search', 'Fetching live price from NSE...', 10);
    const s1 = await api(`${base}&step=1`);
    if (s1.error) { showError(s1.error); return; }

    // Cache hit — load from status endpoint
    if (s1.status === 'cached') {
      updateProgressUI('cache_check', 'Loading from cache...', 50);
      const cached = await api(`${BASE}/api/analyse_status?symbol=${encodeURIComponent(SYMBOL)}`);
      if (cached.status === 'done' && cached.data?.company_name) {
        analysisData = cached.data;
        renderAll(cached.data, true, cached.data.created_at);
        return;
      }
      // Cache fetch failed — fall through to fresh pipeline
    }

    const { company_id, screener_id, company_name, sector, nse_quote } = s1;
    const priceRow = extractPriceRow(nse_quote);

    // Step 2: Screener charts + financials
    updateProgressUI('data_fetch', 'Loading financial statements...', 25);
    const s2 = await api(`${base}&step=2&company_id=${company_id}&screener_id=${screener_id}`);
    if (s2.error) { showError(s2.error); return; }
    const { charts, schedules, peers } = s2;
    const metrics = computeMetrics(schedules, charts, nse_quote);

    // Step 3: News
    updateProgressUI('metrics', 'Pulling chart & valuation data...', 45);
    const s3 = await api(`${base}&step=3&company_id=${company_id}&company_name=${encodeURIComponent(company_name)}`);
    const news = s3.news || [];

    // Step 4: AI analysis (the long one)
    updateProgressUI('ai_call', 'Scanning latest news...', 55);
    await sleep(400); // brief UI pause so user sees the news step
    updateProgressUI('ai_call', 'Running AI analysis...', 60);
    const s4 = await api(
      `${BASE}/api/analyse_steps?symbol=${encodeURIComponent(SYMBOL)}&step=4&company_id=${company_id}&model=${encodeURIComponent(model)}`,
      {
        method: 'POST',
        body: JSON.stringify({
          metrics,
          price_row:    priceRow,
          peers_text:   buildPeersText(nse_quote, peers),
          news_text:    buildNewsText(news),
          company_name, sector,
          indices:      extractIndices(nse_quote),
        })
      }
    );
    if (s4.error) { showError(s4.error); return; }

    // Step 5: Save to DB + get final assembled result
    updateProgressUI('db_save', 'Saving results...', 90);
    const s5 = await api(
      `${BASE}/api/analyse_steps?symbol=${encodeURIComponent(SYMBOL)}&step=5&company_id=${company_id}`,
      {
        method: 'POST',
        body: JSON.stringify({ ai_result: s4, metrics, price_row: priceRow, charts, news, peers, company_name, sector })
      }
    );
    if (s5.error) { showError(s5.error); return; }
    if (!s5.data?.company_name) { showError('Analysis incomplete. Please try again.'); return; }

    analysisData = s5.data;
    renderAll(s5.data, false, null);

  } catch (e) {
    console.error('[StockLens] Pipeline error:', e);
    showError('Analysis failed: ' + e.message);
  }
}

// Safe fetch — always returns parsed JSON, logs raw text on failure
async function api(url, opts = {}) {
  if (opts.body && !opts.headers) opts.headers = { 'Content-Type': 'application/json' };
  const res  = await fetch(url, opts);
  const text = await res.text();
  try {
    return JSON.parse(text);
  } catch (e) {
    console.error('[StockLens] Non-JSON response from', url, ':', text.slice(0, 500));
    throw new Error('Server returned invalid JSON. Check browser console for details.');
  }
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

function extractPriceRow(nse_quote) {
  if (!nse_quote) return null;
  if (nse_quote._cached) return nse_quote._row;
  const p = nse_quote.priceInfo    || {};
  const m = nse_quote.metadata     || {};
  const s = nse_quote.securityInfo || {};
  return {
    last_price:     p.lastPrice            ?? null,
    prev_close:     p.previousClose        ?? null,
    change_pct:     p.pChange              ?? null,
    vwap:           p.vwap                 ?? null,
    week_high:      p.weekHighLow?.max     ?? null,
    week_high_date: p.weekHighLow?.maxDate ?? null,
    week_low:       p.weekHighLow?.min     ?? null,
    week_low_date:  p.weekHighLow?.minDate ?? null,
    upper_circuit:  p.upperCP             ?? null,
    lower_circuit:  p.lowerCP             ?? null,
    pe_ratio:       m.pdSymbolPe           ?? null,
    sector_pe:      m.pdSectorPe           ?? null,
    face_value:     s.faceValue            ?? null,
    market_cap_cr:  null,
    indices:        JSON.stringify(m.activeSeries || []),
  };
}

function extractIndices(nse_quote) {
  return (nse_quote?.metadata?.activeSeries || []).join(', ') || 'N/A';
}

function computeMetrics(schedules, charts, nse_quote) {
  const last  = a => a?.length ? +a[a.length - 1] : 0;
  const first = a => a?.length ? +a[0] : 0;
  const prev  = a => a?.length >= 2 ? +a[a.length - 2] : 0;
  const dsLast = (k, i) => {
    const v = charts?.[k]?.datasets?.[i]?.values || [];
    if (!v.length) return null;
    const x = v[v.length - 1];
    return Array.isArray(x) ? +x[1] : +x;
  };
  const cagr = (n, o, y) => (o > 0 && n > 0 && y > 0) ? +((Math.pow(n/o, 1/y) - 1) * 100).toFixed(1) : null;

  const sales   = schedules?.pl_sales?.values  || [];
  const profit  = schedules?.pl_profit?.values || [];
  const borrows = schedules?.bs_borrow?.values || [];
  const opCf    = schedules?.cf_ops?.values    || [];
  const invCf   = schedules?.cf_inv?.values    || [];
  const sNow = last(sales);  const sOld = first(sales);
  const pNow = last(profit);
  const n    = Math.max(sales.length - 1, 1);
  const fcf  = last(opCf) + last(invCf);
  const lp   = nse_quote?._cached ? +nse_quote._row?.last_price : +nse_quote?.priceInfo?.lastPrice || 0;
  const iss  = nse_quote?._cached ? 0 : +nse_quote?.securityInfo?.issuedSize || 0;

  return {
    revenue_latest:    sNow,
    revenue_cagr:      cagr(sNow, sOld, n),
    profit_latest:     pNow,
    profit_cagr:       cagr(pNow, first(profit), n),
    sales_yoy:         prev(sales) > 0 ? +((sNow - prev(sales)) / prev(sales) * 100).toFixed(1) : null,
    free_cash_flow:    fcf,
    fcf_to_pat:        pNow ? +(fcf / pNow).toFixed(2) : null,
    debt_latest:       last(borrows),
    op_cf_latest:      last(opCf),
    inv_cf_latest:     last(invCf),
    market_cap_cr:     iss && lp ? Math.round(iss * lp / 1e7) : null,
    gpm:               dsLast('margins', 0),
    opm:               dsLast('margins', 1),
    npm:               dsLast('margins', 2),
    median_pe:         dsLast('pe',      1),
    ev_ebitda:         dsLast('ev',      0),
    median_ev:         dsLast('ev',      1),
    pbv:               dsLast('pbv',     0),
    median_pbv:        dsLast('pbv',     1),
    mcap_sales:        dsLast('mcap',    0),
    median_mcap_sales: dsLast('mcap',    1),
    div_yield:         dsLast('dividend',0),
    // server-computed (require full price chart history)
    rsi_14: null, dma_50: null, dma_200: null, vs_dma50: null, vs_dma200: null,
    margin_trend: null, debt_trend: null, material_cost: null, pe_vs_median: null,
    support_resistance: [],
  };
}

function buildPeersText(nse_quote, screenerPeers) {
  if (screenerPeers?.data?.length) {
    return screenerPeers.data.slice(0, 5).map(p =>
      `${p.name} — Price: Rs ${p.cmp_rs ?? 'N/A'} | PE: ${p.pe ?? 'N/A'}x | MCap: Rs ${p.mar_cap_rs_cr ?? 'N/A'} Cr`
    ).join('\n');
  }
  const peers = nse_quote?.industryInfo || [];
  if (!peers.length) return 'Peer data not available.';
  return peers.slice(0, 5).map(p =>
    `${p.companyName} (NSE: ${p.symbol}) — Price: Rs ${p.lastPrice ?? 'N/A'} | PE: ${p.peRatio ?? 'N/A'}`
  ).join('\n');
}

function buildNewsText(news) {
  if (!news?.length) return 'No recent news available.';
  return news.slice(0, 10).map((n, i) => {
    const d = n.published_at ? new Date(n.published_at).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) : '';
    return `${i + 1}. [${d}] ${n.title}${n.source ? ' — ' + n.source : ''}`;
  }).join('\n');
}

// Polling helpers kept for cache-path fallback only
function startPolling(jobId, symbol) {
  let attempts = 0;
  const poll = async () => {
    if (++attempts > 60) { stopPolling(); showError('Loading timed out. Please try again.'); return; }
    try {
      const url = jobId
        ? `${BASE}/api/analyse_status?job_id=${encodeURIComponent(jobId)}`
        : `${BASE}/api/analyse_status?symbol=${encodeURIComponent(symbol)}`;
      const json = await api(url);
      if (json.step_label) updateProgressUI(json.step, json.step_label, json.progress || 0);
      if (json.status === 'failed') { stopPolling(); showError(json.error || 'Analysis failed.'); return; }
      if (json.status === 'done') {
        stopPolling();
        if (json.data?.company_name) { analysisData = json.data; renderAll(json.data, false, null); }
        else showError('Data empty. Please try again.');
      }
    } catch (e) { console.warn('[poll]', e); }
  };
  poll();
  _pollTimer = setInterval(poll, 2000);
}

function stopPolling() {
  if (_pollTimer) { clearInterval(_pollTimer); _pollTimer = null; }
}

// ── Progress UI ───────────────────────────────────────────────────────────────
const STEP_MAP = {
  screener_search: 'price', company_resolve: 'price', cache_check: 'price',
  data_fetch: 'fundamentals', metrics: 'charts',
  ai_call: 'ai', db_save: 'ai', done: 'ai',
};
const STEP_ORDER = ['price', 'fundamentals', 'charts', 'news', 'ai'];

function updateProgressUI(step, label, progress) {
  const msgEl = document.getElementById('loadingMsg');
  if (msgEl) msgEl.textContent = label || 'Processing...';
  const currentDot = STEP_MAP[step];
  if (!currentDot) return;
  const currentIdx = STEP_ORDER.indexOf(currentDot);
  STEP_ORDER.forEach((s, i) => {
    const el = document.querySelector(`[data-step="${s}"]`);
    if (!el) return;
    el.classList.remove('active', 'done');
    if (i < currentIdx) el.classList.add('done');
    else if (i === currentIdx) el.classList.add('active');
  });
}

function showLoading(msg = 'Initialising...') {
  document.getElementById('loadingState').style.display = '';
  document.getElementById('errorState').style.display   = 'none';
  document.getElementById('mainContent').style.display  = 'none';
  const msgEl = document.getElementById('loadingMsg');
  if (msgEl) msgEl.textContent = msg;
}

function showError(msg) {
  stopPolling();
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

function renderAll(data, fromCache, cachedAt) {
  STEP_ORDER.forEach(s => {
    const el = document.querySelector(`[data-step="${s}"]`);
    el?.classList.remove('active');
    el?.classList.add('done');
  });

  safeRender('header',     () => renderHeader(data));
  safeRender('sections',   () => renderSections(data));
  safeRender('tradeSetup', () => renderTradeSetup(data));
  safeRender('conviction', () => renderConvictionMatrix(data));
  safeRender('scenarios',  () => renderScenarios(data));
  safeRender('sr',         () => renderSupportResistance(data));
  safeRender('history',    () => renderVerdictHistory(data));
  safeRender('sentiment',  () => renderNewsSentiment(data));
  safeRender('charts',     () => renderCharts(data));
  safeRender('peers',      () => renderPeers(data));
  safeRender('news',       () => renderNews(data));

  checkWatchlist();

  if (fromCache && cachedAt) {
    const mins = Math.round((Date.now() - new Date(cachedAt).getTime()) / 60000);
    document.getElementById('cacheNote').textContent = `Cached ${mins}m ago`;
  }

  showMain();
  setTimeout(() => refreshPrice(), 300000);
  if (!data?.news_sentiment?.items?.length) loadSentimentAsync();
}

function safeRender(name, fn) {
  try { fn(); } catch (e) { console.warn(`[StockLens] renderer "${name}" failed:`, e); }
}

async function loadSentimentAsync() {
  const listEl = document.getElementById('sentimentList');
  if (listEl) listEl.innerHTML = '<div style="color:var(--c-text3);font-size:0.82rem;padding:8px 0;">Scoring news sentiment...</div>';
  try {
    const res  = await fetch(`${BASE}/api/analyse?action=sentiment&symbol=${encodeURIComponent(SYMBOL)}`);
    const json = await res.json();
    if (json.sentiment) {
      if (analysisData) analysisData.news_sentiment = json.sentiment;
      safeRender('sentiment-async', () => renderNewsSentiment({ news_sentiment: json.sentiment }));
    }
  } catch (e) {
    console.warn('[StockLens] Async sentiment failed:', e);
    if (listEl) listEl.innerHTML = '<div style="color:var(--c-text3);font-size:0.82rem;padding:8px 0;">Sentiment analysis unavailable.</div>';
  }
}

function renderHeader(data) {
  document.getElementById('stockName').textContent   = data.company_name + ' (' + data.symbol + ')';
  document.getElementById('stockSector').textContent = data.sector || '';
  const p = data.price_data;
  if (!p) return;
  document.getElementById('stockPrice').textContent = 'Rs.' + fmtNum(p.last_price);
  const changeEl = document.getElementById('stockChange');
  changeEl.textContent = fmtPct(p.change_pct);
  changeEl.className   = 'stock-header__change num ' + colourClass(p.change_pct);
  const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
  set('m52wHigh',  p.week_high     ? 'Rs.' + fmtNum(p.week_high, 2)  : 'N/A');
  set('m52wLow',   p.week_low      ? 'Rs.' + fmtNum(p.week_low, 2)   : 'N/A');
  set('mPE',       p.pe_ratio      ? fmtNum(p.pe_ratio, 1) + 'x'     : 'N/A');
  set('mSectorPE', p.sector_pe     ? fmtNum(p.sector_pe, 1) + 'x'    : 'N/A');
  set('mMktCap',   p.market_cap_cr ? fmtCr(p.market_cap_cr)          : 'N/A');
  set('mDivYield', 'N/A');
  try {
    const m   = JSON.parse(data.metrics_json || '{}');
    const rsi = m.rsi_14;
    const el  = document.getElementById('mRSI');
    if (el) {
      el.textContent = rsi != null ? fmtNum(rsi, 1) : 'N/A';
      el.className   = 'metric-box__value num ' + (rsi > 70 ? 'num--down' : rsi < 30 ? 'num--up' : 'num--neutral');
    }
  } catch (e) {}
  if (data.model) document.getElementById('modelBadge').textContent = data.model.split('/').pop() || data.model;
}

function renderSections(data) {
  const verdict    = (data.verdict || 'Hold').toLowerCase();
  const vc         = verdict === 'buy' ? 'buy' : verdict === 'avoid' ? 'avoid' : 'hold';
  const confidence = data.confidence ?? 50;
  const icons      = { buy: 'up', hold: 'dot', avoid: 'down' };

  document.getElementById('verdictCard').className   = `analysis-card verdict-card analysis-card--${vc}`;
  document.getElementById('verdictLabel').textContent = data.verdict || 'Hold';
  document.getElementById('verdictLabel').style.color = `var(--c-${vc})`;

  const badge = document.getElementById('verdictBadge');
  badge.className   = `badge badge--${vc}`;
  badge.textContent = data.verdict || 'Hold';

  document.getElementById('verdictBody').innerHTML = mdToHtml(data.sections?.verdict || '');

  document.getElementById('confidenceWrap').style.display = '';
  document.getElementById('confidenceScore').textContent  = confidence + '/100';
  setTimeout(() => { document.getElementById('confidenceFill').style.width = confidence + '%'; }, 300);

  const sectionMap = {
    'price_and_technical_setup': 'section-price_and_technical_setup',
    'support_and_resistance':    'section-support_and_resistance',
    'valuation':                 'section-valuation',
    'business_quality':          'section-business_quality',
    'balance_sheet_health':      'section-balance_sheet_health',
    'cash_flow_quality':         'section-cash_flow_quality',
    'catalysts_and_risks':       'section-catalysts_and_risks',
  };
  for (const [key, elId] of Object.entries(sectionMap)) {
    const el   = document.getElementById(elId);
    if (!el) continue;
    const body = el.querySelector('.analysis-card__body');
    if (!body) continue;
    const text = data.sections?.[key];
    body.innerHTML = text ? mdToHtml(text) : '<span style="color:var(--c-text3)">Data not available.</span>';
  }
}

function mdToHtml(text) {
  if (!text) return '';
  // Escape HTML entities first so AI output can never inject tags
  const esc = text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
  return esc
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.+?)\*/g, '<em>$1</em>')
    .replace(/\n\n+/g, '</p><p>')
    .replace(/^/, '<p>').replace(/$/, '</p>');
}

function renderCharts(data) {
  const cd = data.chart_data;
  if (!cd || !Object.keys(cd).length) return;
  window._rawChartData = cd;
  const tryInit = (attempts) => {
    if (typeof Chart !== 'undefined' && typeof initCharts === 'function') {
      try { initCharts(cd); } catch (e) { console.error('Chart init error:', e); }
    } else if (attempts > 0) {
      setTimeout(() => tryInit(attempts - 1), 400);
    }
  };
  tryInit(15);
}

function renderPeers(data) {
  const thead = document.getElementById('peersHead');
  const tbody = document.getElementById('peersBody');
  if (!tbody) return;
  const peers = data.peers;
  if (Array.isArray(peers?.data)) { renderScreenerPeers(peers, thead, tbody); return; }
  if (Array.isArray(peers) && peers.length) { renderNsePeers(peers, thead, tbody); return; }
  if (peers && typeof peers === 'object') {
    if (thead) thead.innerHTML = '<tr><th colspan="2">Sector Information</th></tr>';
    tbody.innerHTML = `<tr><td colspan="2" style="padding:20px;">
      <div style="color:var(--c-text2);font-size:0.875rem;">
        <strong>Sector:</strong> ${peers.sector || 'N/A'} | <strong>Industry:</strong> ${peers.industry || 'N/A'}
      </div></td></tr>`;
    return;
  }
  if (thead) thead.innerHTML = '';
  tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--c-text3);padding:20px;">No peer data available.</td></tr>';
}

function renderScreenerPeers(peersData, thead, tbody) {
  const rows   = peersData.data   || [];
  const median = peersData.median || {};
  const count  = median.companies_count ?? '';
  if (thead) {
    thead.innerHTML = `<tr>
      <th>Company</th><th class="num">Price</th><th class="num">P/E</th>
      <th class="num">Mkt Cap (Cr)</th><th class="num">Div Yield%</th>
      <th class="num">Qtr Profit</th><th class="num">Profit Chg%</th><th class="num">ROCE%</th>
    </tr>`;
  }
  const buildRow = (row, isMedian = false) => {
    const pv  = parseFloat(row.qtr_profit_var_pct);
    const pCls = isNaN(pv) ? '' : pv >= 0 ? 'td-up' : 'td-down';
    const name = isMedian
      ? `<em style="color:var(--c-text3);">Sector Median</em>${count ? ` (${count} cos.)` : ''}`
      : row.name || '—';
    const price = isMedian ? '—' : (row.cmp_rs != null ? 'Rs.' + fmtNum(row.cmp_rs, 2) : '—');
    return `<tr class="${isMedian ? 'peers-median-row' : ''}">
      <td>${name}</td><td class="num">${price}</td>
      <td class="num">${row.pe != null ? fmtNum(row.pe, 1) + 'x' : '—'}</td>
      <td class="num">${row.mar_cap_rs_cr != null ? fmtCr(row.mar_cap_rs_cr) : '—'}</td>
      <td class="num">${row.div_yld_pct != null ? fmtNum(row.div_yld_pct, 2) + '%' : '—'}</td>
      <td class="num">${row.np_qtr_rs_cr != null ? fmtCr(row.np_qtr_rs_cr) : '—'}</td>
      <td class="num ${pCls}">${row.qtr_profit_var_pct != null ? fmtPct(row.qtr_profit_var_pct) : '—'}</td>
      <td class="num">${row.roce_pct != null ? fmtNum(row.roce_pct, 1) + '%' : '—'}</td>
    </tr>`;
  };
  tbody.innerHTML = rows.map(r => buildRow(r)).join('') + (Object.keys(median).length > 1 ? buildRow(median, true) : '');
}

function renderNsePeers(peers, thead, tbody) {
  if (thead) thead.innerHTML = `<tr>
    <th>Company</th><th>Symbol</th><th class="num">Price</th><th class="num">Chg%</th>
    <th class="num">Mkt Cap</th><th class="num">P/E</th><th class="num">52W High</th><th class="num">52W Low</th>
  </tr>`;
  tbody.innerHTML = peers.slice(0, 8).map(p => {
    const chg = Number(p.pChange || p.change_pct || 0);
    return `<tr>
      <td>${p.companyName || p.symbol}</td>
      <td class="num" style="color:var(--c-primary);">${p.symbol}</td>
      <td class="num">Rs.${fmtNum(p.lastPrice || p.last_price, 2)}</td>
      <td class="num ${chg >= 0 ? 'td-up' : 'td-down'}">${fmtPct(chg)}</td>
      <td class="num">${fmtCr(p.marketCap || p.market_cap_cr)}</td>
      <td class="num">${p.pe || p.pe_ratio ? fmtNum(p.pe || p.pe_ratio, 1) + 'x' : 'N/A'}</td>
      <td class="num">Rs.${fmtNum(p.yearHigh || p.week_high, 2)}</td>
      <td class="num">Rs.${fmtNum(p.yearLow || p.week_low, 2)}</td>
    </tr>`;
  }).join('');
}

function renderNews(data) {
  const list = document.getElementById('newsList');
  if (!list) return;
  const news = data.news;
  if (!news?.length) {
    list.innerHTML = '<div style="text-align:center;padding:24px;color:var(--c-text3);">No recent news found.</div>';
    return;
  }
  list.innerHTML = '';
  news.forEach((n, i) => {
    const href = typeof n.link === 'string' && /^https?:\/\//i.test(n.link) ? n.link : '#';
    const a = document.createElement('a');
    a.href      = href;
    a.target    = '_blank';
    a.rel       = 'noopener';
    a.className = 'news-item';

    const num = document.createElement('span');
    num.className   = 'news-item__num';
    num.textContent = String(i + 1).padStart(2, '0');

    const body   = document.createElement('div');
    body.className = 'news-item__body';

    const title = document.createElement('div');
    title.className   = 'news-item__title';
    title.textContent = n.title || '';

    const meta = document.createElement('div');
    meta.className = 'news-item__meta';

    const source = document.createElement('span');
    source.className   = 'news-item__source';
    source.textContent = n.source || '';

    const date = document.createElement('span');
    date.textContent = n.published_at ? formatNewsDate(n.published_at) : '';

    meta.append(source, date);
    body.append(title, meta);

    const arrow = document.createElement('span');
    arrow.style.cssText  = 'color:var(--c-text3);margin-top:2px;';
    arrow.textContent    = 'up';

    a.append(num, body, arrow);
    list.appendChild(a);
  });
}

function formatNewsDate(dt) {
  try {
    const d    = new Date(dt);
    const diff = (Date.now() - d.getTime()) / 1000;
    if (diff < 3600)  return Math.round(diff / 60) + 'm ago';
    if (diff < 86400) return Math.round(diff / 3600) + 'h ago';
    return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
  } catch { return ''; }
}

async function checkWatchlist() {
  try {
    const res  = await fetch(`${BASE}/api/watchlist?action=check&symbol=${encodeURIComponent(SYMBOL)}`);
    const json = await res.json();
    inWatchlist = json.in_watchlist;
    updateWatchlistBtn();
  } catch {}
}

function updateWatchlistBtn() {
  const btn = document.getElementById('watchlistBtn');
  if (!btn) return;
  btn.textContent = inWatchlist ? 'In Watchlist' : 'Add to Watchlist';
  btn.style.color = inWatchlist ? 'var(--c-buy)' : '';
}

async function toggleWatchlist() {
  if (!analysisData) return;
  try {
    const body = new URLSearchParams({ action: inWatchlist ? 'remove' : 'add', symbol: SYMBOL });
    const res  = await fetch(`${BASE}/api/watchlist`, { method: 'POST', body });
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
    const res  = await fetch(`${BASE}/api/prices?symbols=${SYMBOL}`);
    const json = await res.json();
    const p    = json[SYMBOL];
    if (!p) return;
    document.getElementById('stockPrice').textContent = 'Rs.' + fmtNum(p.price);
    const el = document.getElementById('stockChange');
    el.textContent = fmtPct(p.change_pct);
    el.className   = 'stock-header__change num ' + colourClass(p.change_pct);
  } catch {}
}

// ── Trade Setup ───────────────────────────────────────────────────────────────
function renderTradeSetup(data) {
  const body  = document.getElementById('tradeSetupBody');
  const badge = document.getElementById('tsRRBadge');
  if (!body) return;
  const ts = data.trade_setup;
  const hasStructured = ts && (ts.entry || ts.target1 || ts.stop_loss);

  if (!hasStructured) {
    const rawText = ts?.raw || data.sections?.trade_setup || '';
    if (rawText) {
      const keywords = ['ENTRY ZONE', 'TARGET 1', 'TARGET 2', 'STOP LOSS', 'TIMEFRAME', 'RISK/REWARD'];
      let formatted = rawText;
      keywords.forEach(kw => { formatted = formatted.replace(new RegExp(`(${kw}\\s*:)`, 'gi'), '\n$1'); });
      const lines = formatted.split('\n').map(l => l.trim()).filter(Boolean);
      const rows = lines.map(line => {
        const ci = line.indexOf(':');
        if (ci > 0 && ci < 25) {
          const label = line.slice(0, ci).trim();
          const value = line.slice(ci + 1).trim();
          const cls = label.includes('STOP') ? 'ts-row--sl' : label.includes('TARGET') ? 'ts-row--target' : label.includes('ENTRY') ? 'ts-row--entry' : '';
          return `<div class="ts-row ${cls}"><span class="ts-row__label">${label}</span><span class="ts-row__value">${value}</span></div>`;
        }
        return `<div class="ts-rationale">${mdToHtml(line)}</div>`;
      }).join('');
      body.innerHTML = `<div class="ts-grid">${rows}</div>`;
    } else {
      body.innerHTML = '<div class="ts-empty">Trade setup data not available.</div>';
    }
    return;
  }

  if (ts.rr && badge) { badge.textContent = 'R/R ' + String(ts.rr).trim(); badge.style.display = ''; }

  const entryPrice = ts.entry ? String(ts.entry).replace(/Rs?\s*/i, '').trim() : null;
  const t1 = ts.target1, t2 = ts.target2, sl = ts.stop_loss;
  const priceZone = p => !p ? '—' : typeof p === 'object' ? `Rs.${p.price} (${p.pct})` : 'Rs.' + String(p).replace(/Rs?\s*/i, '').trim();
  const slZone    = p => !p ? '—' : typeof p === 'object' ? `Rs.${p.price} (${p.pct})` : 'Rs.' + String(p).replace(/Rs?\s*/i, '').trim();

  body.innerHTML = `
    <div class="ts-grid">
      <div class="ts-row ts-row--entry"><span class="ts-row__label">Entry Zone</span><span class="ts-row__value">Rs.${entryPrice || '—'}</span></div>
      <div class="ts-row ts-row--target"><span class="ts-row__label">Target 1</span><span class="ts-row__value">${priceZone(t1)}</span></div>
      <div class="ts-row ts-row--target"><span class="ts-row__label">Target 2</span><span class="ts-row__value">${priceZone(t2)}</span></div>
      <div class="ts-row ts-row--sl"><span class="ts-row__label">Stop Loss</span><span class="ts-row__value">${slZone(sl)}</span></div>
      <div class="ts-row"><span class="ts-row__label">Timeframe</span><span class="ts-row__value">${ts.timeframe || '—'}</span></div>
    </div>`;
}

// ── Conviction Matrix ─────────────────────────────────────────────────────────
function renderConvictionMatrix(data) {
  const conviction = data.conviction;
  if (!conviction) return;
  const scores  = conviction.scores  || {};
  const reasons = conviction.reasons || {};
  ['TECHNICAL', 'VALUATION', 'QUALITY', 'NEWS', 'MOMENTUM'].forEach(dim => {
    const key    = dim.toLowerCase();
    const score  = scores[dim];
    const scoreEl  = document.getElementById(`conv-${key}`);
    const barEl    = document.getElementById(`conv-bar-${key}`);
    const reasonEl = document.getElementById(`conv-reason-${key}`);
    if (scoreEl)  scoreEl.textContent  = score != null ? score + '/100' : 'N/A';
    if (reasonEl) reasonEl.textContent = reasons[dim] || '';
    if (barEl && score != null) {
      barEl.style.background = score >= 60 ? 'var(--c-buy)' : score >= 40 ? 'var(--c-hold)' : 'var(--c-avoid)';
      setTimeout(() => { barEl.style.width = score + '%'; }, 300);
    }
  });
}

// ── Scenario Analysis ─────────────────────────────────────────────────────────
function renderScenarios(data) {
  const grid = document.getElementById('scenariosGrid');
  if (!grid) return;
  const sc = data.scenarios;
  const hasStructured = sc?.cases && Object.values(sc.cases).some(c => c !== null);

  if (!hasStructured) {
    const rawText = sc?.raw || data.sections?.scenario_analysis || '';
    if (rawText) {
      let formatted = rawText;
      ['BULL CASE', 'BASE CASE', 'BEAR CASE'].forEach(kw => {
        formatted = formatted.replace(new RegExp(kw, 'gi'), '\n' + kw);
      });
      const lines = formatted.split('\n').map(l => l.trim()).filter(Boolean);
      const html = lines.map(line => {
        const isBull = /^BULL/i.test(line), isBase = /^BASE/i.test(line), isBear = /^BEAR/i.test(line);
        if (isBull || isBase || isBear) {
          const cls   = isBull ? 'bull' : isBear ? 'bear' : 'base';
          const label = isBull ? 'Bull Case' : isBear ? 'Bear Case' : 'Base Case';
          const pct   = (line.match(/\(([^)]+)\)/) || [])[1] || '';
          const rest  = line.replace(/^(BULL|BASE|BEAR)(\s+CASE)?\s*(\([^)]+\))?\s*:?\s*/i, '');
          return `<div class="scenario-card scenario-card--${cls}">
            <div class="scenario-card__header">
              <span class="scenario-card__label">${label}</span>
              ${pct ? `<span class="scenario-card__pct">${pct}</span>` : ''}
            </div>
            <div class="scenario-card__condition">${rest}</div>
          </div>`;
        }
        return '';
      }).join('');
      grid.innerHTML = html || mdToHtml(rawText);
    } else {
      grid.innerHTML = '<div style="color:var(--c-text3);font-size:0.85rem;">Scenario data not available.</div>';
    }
    return;
  }

  const makeCard = (type, label, cls) => {
    const c = sc.cases[type];
    if (!c) return '';
    return `<div class="scenario-card scenario-card--${cls}">
      <div class="scenario-card__header">
        <span class="scenario-card__label">${label}</span>
        <span class="scenario-card__pct">${c.pct || ''}</span>
      </div>
      <div class="scenario-card__price">Rs.${c.price || '—'}</div>
      <div class="scenario-card__condition">${c.condition || ''}</div>
    </div>`;
  };
  grid.innerHTML = makeCard('BULL', 'Bull Case', 'bull') + makeCard('BASE', 'Base Case', 'base') + makeCard('BEAR', 'Bear Case', 'bear');
}

// ── Support & Resistance ──────────────────────────────────────────────────────
function renderSupportResistance(data) {
  const body = document.getElementById('srLevelsBody');
  if (!body) return;
  const levels = data.support_resistance || [];
  const price  = parseFloat(data.price_data?.last_price || data.price || 0);

  if (!levels.length) {
    body.innerHTML = '<div style="color:var(--c-text3);font-size:0.85rem;padding:8px 0;">Level data not available yet.</div>';
    return;
  }

  const above = levels.filter(l => l.type === 'resistance').sort((a, b) => a.price - b.price);
  const below = levels.filter(l => l.type === 'support').sort((a, b) => b.price - a.price);

  const makeRow = (lvl, isAbove) => {
    const dist    = price > 0 ? ((lvl.price - price) / price * 100).toFixed(1) : null;
    const distStr = dist != null ? `<span class="sr-dist ${isAbove ? 'sr-dist--up' : 'sr-dist--down'}">${isAbove ? '+' : ''}${dist}%</span>` : '';
    return `<div class="sr-level ${lvl.strength === 'strong' ? 'sr-level--strong' : ''} sr-level--${lvl.type}">
      <div class="sr-level__left">
        <span class="sr-level__type-dot"></span>
        <span class="sr-level__label">${lvl.label}</span>
        <span class="sr-level__strength">${lvl.strength}</span>
      </div>
      <div class="sr-level__right">
        <span class="sr-level__price">Rs.${fmtNum(lvl.price, 2)}</span>${distStr}
      </div>
    </div>`;
  };

  body.innerHTML =
    (above.length ? `<div class="sr-group"><div class="sr-group__heading">Resistance</div>${above.map(l => makeRow(l, true)).join('')}</div>` : '') +
    `<div class="sr-current-price">Current Rs.${fmtNum(price, 2)}</div>` +
    (below.length ? `<div class="sr-group"><div class="sr-group__heading">Support</div>${below.map(l => makeRow(l, false)).join('')}</div>` : '');
}

// ── Verdict History ───────────────────────────────────────────────────────────
function renderVerdictHistory(data) {
  const tl = document.getElementById('verdictTimeline');
  if (!tl) return;
  const history = data.verdict_history || [];
  if (!history.length) {
    tl.innerHTML = '<div style="color:var(--c-text3);font-size:0.85rem;padding:8px 0;">No history yet.</div>';
    return;
  }
  tl.innerHTML = history.map((h, i) => {
    const v      = (h.verdict || 'Hold').toLowerCase();
    const date   = h.created_at ? new Date(h.created_at).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: '2-digit' }) : '';
    const price  = h.price_at_time ? 'Rs.' + fmtNum(h.price_at_time, 2) : '';
    const isLast = i === history.length - 1;
    return `<div class="vt-item ${isLast ? 'vt-item--current' : ''}">
      <div class="vt-item__dot vt-item__dot--${v}"></div>
      <div class="vt-item__body">
        <div class="vt-item__verdict verdict-${v}">${h.verdict || 'Hold'}</div>
        <div class="vt-item__meta">${date}${price ? ' · ' + price : ''}</div>
        ${h.confidence_score ? `<div class="vt-item__conf">${h.confidence_score}/100</div>` : ''}
      </div>
      ${!isLast ? '<div class="vt-connector"></div>' : ''}
    </div>`;
  }).join('');
}

// ── News Sentiment ────────────────────────────────────────────────────────────
function renderNewsSentiment(data) {
  const listEl    = document.getElementById('sentimentList');
  const labelEl   = document.getElementById('sentimentLabel');
  const scoreEl   = document.getElementById('sentimentScore');
  const gaugeFill = document.getElementById('sentimentGaugeFill');
  if (!listEl) return;

  const sentiment = data.news_sentiment;
  if (!sentiment?.items?.length) {
    listEl.innerHTML = '<div style="color:var(--c-text3);font-size:0.85rem;padding:8px 0;">News sentiment data not available.</div>';
    return;
  }

  const overall = sentiment.overall_score ?? 50;
  const label   = sentiment.overall_label ?? 'NEUTRAL';
  const lCls    = label === 'BULLISH' ? 'sent-bullish' : label === 'BEARISH' ? 'sent-bearish' : 'sent-neutral';
  if (labelEl)   { labelEl.textContent = label; labelEl.className = `sentiment-overall__label ${lCls}`; }
  if (scoreEl)   scoreEl.textContent = overall + '/100';
  if (gaugeFill) {
    gaugeFill.className = `sentiment-gauge__fill ${lCls}`;
    setTimeout(() => { gaugeFill.style.width = overall + '%'; }, 300);
  }

  listEl.innerHTML = sentiment.items.map(item => {
    const s   = (item.sentiment || 'NEUTRAL').toUpperCase();
    const cls = s === 'BULLISH' ? 'sent-bullish' : s === 'BEARISH' ? 'sent-bearish' : 'sent-neutral';
    const date = item.published_at ? formatNewsDate(item.published_at) : '';
    return `<div class="sentiment-item">
      <span class="sentiment-badge ${cls}">${s}</span>
      <div class="sentiment-item__body">
        <a href="${item.link || '#'}" target="_blank" rel="noopener" class="sentiment-item__title">${item.title || ''}</a>
        <div class="sentiment-item__meta">
          ${item.source ? `<span>${item.source}</span>` : ''}
          ${date ? `<span>${date}</span>` : ''}
        </div>
      </div>
    </div>`;
  }).join('');
}
