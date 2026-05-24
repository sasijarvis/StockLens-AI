// ── charts.js — Chart.js 4.x ─────────────────────────────────────────

const CHART_COLORS = {
  blue:   '#2f81f7',
  green:  '#3fb950',
  amber:  '#d29922',
  red:    '#f85149',
  gray:   '#6e7681',
};

function applyChartDefaults() {
  Chart.defaults.color                            = '#8b949e';
  Chart.defaults.borderColor                      = '#2d3748';
  Chart.defaults.font.family                      = "'DM Mono', monospace";
  Chart.defaults.font.size                        = 11;
  Chart.defaults.plugins.tooltip.backgroundColor = '#1c2330';
  Chart.defaults.plugins.tooltip.borderColor      = '#3d4a5c';
  Chart.defaults.plugins.tooltip.borderWidth      = 1;
  Chart.defaults.plugins.tooltip.padding          = 10;
  Chart.defaults.plugins.tooltip.titleColor       = '#e6edf3';
  Chart.defaults.plugins.tooltip.bodyColor        = '#8b949e';
}

let charts       = {};
let rawChartData = {};

function initCharts(chartData) {
  if (typeof Chart === 'undefined') return;
  applyChartDefaults();
  rawChartData = chartData || {};
  buildPriceChart(chartData?.price, 365);
  buildPeChart(chartData?.pe);
  buildMarginsChart(chartData?.margins, 1825);
  buildCashflowChart(chartData);
}

function findDataset(chartData, ...keywords) {
  const datasets = chartData?.datasets || [];
  for (const kw of keywords) {
    const kwl = kw.toLowerCase();
    const ds  = datasets.find(d =>
      (d.metric || '').toLowerCase().includes(kwl) ||
      (d.label  || '').toLowerCase().includes(kwl)
    );
    if (ds) return ds;
  }
  return null;
}

function extractData(dataset, days) {
  if (!dataset) return { labels: [], values: [] };
  const raw    = dataset.values || [];
  const cutoff = days ? Date.now() - days * 86400000 : 0;
  const labels = [], values = [];
  for (const v of raw) {
    const dateStr = Array.isArray(v) ? v[0] : (v.x || '');
    const val     = Array.isArray(v) ? v[1] : (v.y ?? v[1]);
    if (!dateStr) continue;
    if (days && new Date(dateStr).getTime() < cutoff) continue;
    const y = parseFloat(val);
    if (!isNaN(y)) { labels.push(dateStr); values.push(y); }
  }
  return { labels, values };
}

function fmtDate(d) {
  try {
    const dt = new Date(d);
    return dt.toLocaleDateString('en-IN', { month:'short', year:'2-digit' });
  } catch { return d; }
}

function buildPriceChart(data, days) {
  const ctx = document.getElementById('chart-price');
  if (!ctx || !data?.datasets?.length) return;
  if (charts.price) { charts.price.destroy(); delete charts.price; }

  const dsPrice = findDataset(data, 'price', 'nse')             || data.datasets[0];
  const dsDma50 = findDataset(data, 'dma50', '50 dma', '50dma') || data.datasets[1];
  const dsDma200= findDataset(data, 'dma200','200 dma','200dma') || data.datasets[2];

  const base = extractData(dsPrice, days);
  const d50  = extractData(dsDma50, days);
  const d200 = extractData(dsDma200, days);
  const labels     = base.labels;
  const tickLabels = labels.map((l, i) => (i % Math.ceil(labels.length / 8) === 0) ? fmtDate(l) : '');

  charts.price = new Chart(ctx, {
    type: 'line',
    data: { labels, datasets: [
      { label:'Price',   data: base.values, borderColor: CHART_COLORS.blue,  borderWidth:2,   pointRadius:0, tension:0.2, fill:false },
      { label:'50 DMA',  data: d50.values,  borderColor: CHART_COLORS.amber, borderWidth:1.5, pointRadius:0, tension:0.2, fill:false, borderDash:[4,3] },
      { label:'200 DMA', data: d200.values, borderColor: CHART_COLORS.red,   borderWidth:1.5, pointRadius:0, tension:0.2, fill:false, borderDash:[4,3] },
    ]},
    options: baseOptions({ labels: tickLabels, yTick: v => '₹' + v.toLocaleString('en-IN') })
  });
}

function buildPeChart(data) {
  const ctx = document.getElementById('chart-pe');
  if (!ctx || !data?.datasets?.length) return;
  if (charts.pe) { charts.pe.destroy(); delete charts.pe; }

  const dsPe     = findDataset(data, 'price to earn')
                || data.datasets.find(d => !(d.metric||'').toLowerCase().includes('eps'))
                || data.datasets[0];
  const dsMedian = findDataset(data, 'median') || data.datasets[1];

  const pe     = extractData(dsPe,     null);
  const median = extractData(dsMedian, null);
  const labels     = pe.labels;
  const tickLabels = labels.map((l, i) => (i % Math.ceil(labels.length / 8) === 0) ? fmtDate(l) : '');

  charts.pe = new Chart(ctx, {
    type: 'line',
    data: { labels, datasets: [
      { label:'P/E',       data: pe.values,     borderColor: CHART_COLORS.blue, borderWidth:2,   pointRadius:0, tension:0.2, fill:false },
      { label:'Median PE', data: median.values, borderColor: CHART_COLORS.gray, borderWidth:1.5, pointRadius:0, tension:0.2, fill:false, borderDash:[5,4] },
    ]},
    options: baseOptions({ labels: tickLabels, yTick: v => v + 'x', yTitle: 'PE Multiple (x)' })
  });
}

function buildMarginsChart(data, days) {
  const ctx = document.getElementById('chart-margins');
  if (!ctx || !data?.datasets?.length) return;
  if (charts.margins) { charts.margins.destroy(); delete charts.margins; }

  const dsGpm = findDataset(data, 'gpm', 'gross')     || data.datasets[0];
  const dsOpm = findDataset(data, 'opm', 'operating') || data.datasets[1];
  const dsNpm = findDataset(data, 'npm', 'net')       || data.datasets[2];

  const gpm    = extractData(dsGpm, days);
  const opm    = extractData(dsOpm, days);
  const npm    = extractData(dsNpm, days);
  const labels     = gpm.labels;
  const tickLabels = labels.map((l, i) => (i % Math.ceil(labels.length / 8) === 0) ? fmtDate(l) : '');

  charts.margins = new Chart(ctx, {
    type: 'line',
    data: { labels, datasets: [
      { label:'Gross Margin', data: gpm.values, borderColor: CHART_COLORS.green, borderWidth:2, pointRadius:0, tension:0.2, fill:false },
      { label:'Op. Margin',   data: opm.values, borderColor: CHART_COLORS.blue,  borderWidth:2, pointRadius:0, tension:0.2, fill:false },
      { label:'Net Margin',   data: npm.values, borderColor: CHART_COLORS.amber, borderWidth:2, pointRadius:0, tension:0.2, fill:false },
    ]},
    options: baseOptions({ labels: tickLabels, yTick: v => v + '%', yTitle: 'Margin (%)', yMin: 0 })
  });
}

function buildCashflowChart(allData) {
  const ctx = document.getElementById('chart-cashflow');
  if (!ctx) return;
  if (charts.cashflow) { charts.cashflow.destroy(); delete charts.cashflow; }

  const cfData = allData?.cashflow || allData?.cash_flow || allData?.['cash-flow'];
  if (!cfData?.datasets?.length) { ctx.closest('.chart-card').style.display = 'none'; return; }

  const dsOp  = findDataset(cfData, 'operating') || cfData.datasets[0];
  const dsInv = findDataset(cfData, 'investing')  || cfData.datasets[1];

  const op  = extractData(dsOp,  null);
  const inv = extractData(dsInv, null);
  const fcf = op.values.map((v, i) => v + (inv.values[i] || 0));
  const tickLabels = op.labels.map((l, i) => (i % Math.ceil(op.labels.length / 8) === 0) ? fmtDate(l) : '');

  charts.cashflow = new Chart(ctx, {
    type: 'bar',
    data: { labels: op.labels, datasets: [
      { label:'Operating CF', data: op.values,  backgroundColor: CHART_COLORS.green + 'cc', borderRadius:4 },
      { label:'Investing CF', data: inv.values, backgroundColor: CHART_COLORS.red   + 'cc', borderRadius:4 },
      { label:'Free CF',      data: fcf,        backgroundColor: CHART_COLORS.blue  + 'cc', borderRadius:4 },
    ]},
    options: {
      responsive:true, maintainAspectRatio:false,
      interaction:{ mode:'index', intersect:false },
      scales:{
        x:{ type:'category', grid:{ display:false }, ticks:{ callback:(v,i) => tickLabels[i] || '' } },
        y:{ grid:{ color:'rgba(255,255,255,0.04)' }, title:{ display:true, text:'Amount (Cr)' },
            ticks:{ callback: v => '₹' + v.toLocaleString('en-IN') } }
      },
      plugins:{ legend:{ position:'top', labels:{ boxWidth:12, padding:12 } } }
    }
  });
}

function baseOptions({ labels = [], yTick, yTitle, yMin } = {}) {
  return {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode:'index', intersect:false },
    scales: {
      x: {
        // Explicitly use 'category' scale so Chart.js v4 never tries to
        // auto-detect date strings and require a date adapter.
        type: 'category',
        grid: { color:'rgba(255,255,255,0.04)' },
        ticks: { callback: (val, i) => labels[i] || '', maxRotation:0, autoSkip:false }
      },
      y: {
        grid: { color:'rgba(255,255,255,0.04)' },
        ...(yTitle            ? { title: { display:true, text:yTitle } } : {}),
        ...(yMin !== undefined ? { min: yMin }                           : {}),
        ...(yTick             ? { ticks: { callback: yTick } }          : {}),
      }
    },
    plugins: { legend: { position:'top', labels:{ boxWidth:12, padding:12 } } }
  };
}

function filterChart(chartName, days, btn) {
  btn?.closest('.chart-timerange')?.querySelectorAll('button').forEach(b => b.classList.remove('active'));
  btn?.classList.add('active');
  if (chartName === 'price')   buildPriceChart(rawChartData?.price, days);
  if (chartName === 'margins') buildMarginsChart(rawChartData?.margins, days);
}