// ── charts.js — Chart.js 4.x ─────────────────────────────────────────
// Screener.in chart format:
//   data.datasets = [ { metric, label, values: [[dateStr, numVal], ...] }, ... ]
// Dataset order varies by chart — we match by `metric` name, not array index.

const CHART_COLORS = {
  blue:   '#2f81f7',
  green:  '#3fb950',
  amber:  '#d29922',
  red:    '#f85149',
  gray:   '#6e7681',
  purple: '#a371f7',
};

Chart.defaults.color           = '#8b949e';
Chart.defaults.borderColor     = '#2d3748';
Chart.defaults.font.family     = "'DM Mono', 'Courier New', monospace";
Chart.defaults.font.size       = 11;
Chart.defaults.plugins.tooltip.backgroundColor = '#1c2330';
Chart.defaults.plugins.tooltip.borderColor     = '#3d4a5c';
Chart.defaults.plugins.tooltip.borderWidth     = 1;
Chart.defaults.plugins.tooltip.padding         = 10;
Chart.defaults.plugins.tooltip.titleColor      = '#e6edf3';
Chart.defaults.plugins.tooltip.bodyColor       = '#8b949e';

let charts       = {};
let rawChartData = {};

function initCharts(chartData) {
  rawChartData = chartData || {};
  buildPriceChart(chartData?.price, 365);
  buildPeChart(chartData?.pe);
  buildMarginsChart(chartData?.margins, 1825);
  buildCashflowChart(chartData);
}

// Find a dataset by metric/label keywords (case-insensitive)
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

// Extract [{x, y}] from Screener dataset, optionally filtered to last N days
function extractSeries(dataset, days) {
  if (!dataset) return [];
  const raw    = dataset.values || [];
  const cutoff = days ? Date.now() - days * 86400000 : 0;
  const result = [];
  for (const v of raw) {
    const dateStr = Array.isArray(v) ? v[0] : v.x;
    const val     = Array.isArray(v) ? v[1] : v.y;
    if (!dateStr) continue;
    if (days && new Date(dateStr).getTime() < cutoff) continue;
    const y = parseFloat(val);
    if (!isNaN(y)) result.push({ x: dateStr, y });
  }
  return result;
}

// Chart 1: Price vs 50-DMA vs 200-DMA
function buildPriceChart(data, days) {
  const ctx = document.getElementById('chart-price');
  if (!ctx) return;
  if (charts.price) { charts.price.destroy(); delete charts.price; }
  if (!data?.datasets?.length) return;

  const dsPrice = findDataset(data, 'price', 'nse')       || data.datasets[0];
  const dsDma50 = findDataset(data, 'dma50', '50 dma', '50dma') || data.datasets[1];
  const dsDma200= findDataset(data, 'dma200','200 dma','200dma')|| data.datasets[2];

  charts.price = new Chart(ctx, {
    type: 'line',
    data: { datasets: [
      { label:'Price',   data: extractSeries(dsPrice, days),  borderColor: CHART_COLORS.blue,  borderWidth:2,   pointRadius:0, tension:0.2, fill:false },
      { label:'50 DMA',  data: extractSeries(dsDma50, days),  borderColor: CHART_COLORS.amber, borderWidth:1.5, pointRadius:0, tension:0.2, fill:false, borderDash:[4,3] },
      { label:'200 DMA', data: extractSeries(dsDma200, days), borderColor: CHART_COLORS.red,   borderWidth:1.5, pointRadius:0, tension:0.2, fill:false, borderDash:[4,3] },
    ]},
    options: baseOptions({ yTick: v => '₹' + v.toLocaleString('en-IN') })
  });
}

// Chart 2: PE vs Median PE
function buildPeChart(data) {
  const ctx = document.getElementById('chart-pe');
  if (!ctx) return;
  if (charts.pe) { charts.pe.destroy(); delete charts.pe; }
  if (!data?.datasets?.length) return;

  const dsPe     = findDataset(data, 'pe', 'price to earn') || data.datasets.find(d => !d.metric?.toLowerCase().includes('eps')) || data.datasets[0];
  const dsMedian = findDataset(data, 'median')              || data.datasets[1];

  charts.pe = new Chart(ctx, {
    type: 'line',
    data: { datasets: [
      { label:'P/E',       data: extractSeries(dsPe,     null), borderColor: CHART_COLORS.blue, borderWidth:2,   pointRadius:0, tension:0.2, fill:false },
      { label:'Median PE', data: extractSeries(dsMedian, null), borderColor: CHART_COLORS.gray, borderWidth:1.5, pointRadius:0, tension:0.2, fill:false, borderDash:[5,4] },
    ]},
    options: baseOptions({ yTick: v => v + 'x', yTitle: 'PE Multiple (x)' })
  });
}

// Chart 3: Margin Trends
function buildMarginsChart(data, days) {
  const ctx = document.getElementById('chart-margins');
  if (!ctx) return;
  if (charts.margins) { charts.margins.destroy(); delete charts.margins; }
  if (!data?.datasets?.length) return;

  const dsGpm = findDataset(data, 'gpm', 'gross')     || data.datasets[0];
  const dsOpm = findDataset(data, 'opm', 'operating') || data.datasets[1];
  const dsNpm = findDataset(data, 'npm', 'net')       || data.datasets[2];

  charts.margins = new Chart(ctx, {
    type: 'line',
    data: { datasets: [
      { label:'Gross Margin', data: extractSeries(dsGpm, days), borderColor: CHART_COLORS.green, borderWidth:2, pointRadius:0, tension:0.2, fill:false },
      { label:'Op. Margin',   data: extractSeries(dsOpm, days), borderColor: CHART_COLORS.blue,  borderWidth:2, pointRadius:0, tension:0.2, fill:false },
      { label:'Net Margin',   data: extractSeries(dsNpm, days), borderColor: CHART_COLORS.amber, borderWidth:2, pointRadius:0, tension:0.2, fill:false },
    ]},
    options: baseOptions({ yTick: v => v + '%', yTitle: 'Margin (%)', yMin: 0 })
  });
}

// Chart 4: Cash Flow bars — built from cashflow chart data if present
function buildCashflowChart(allData) {
  const ctx = document.getElementById('chart-cashflow');
  if (!ctx) return;
  if (charts.cashflow) { charts.cashflow.destroy(); delete charts.cashflow; }

  const cfData = allData?.cashflow || allData?.['cash_flow'] || allData?.['cash-flow'];
  if (!cfData?.datasets?.length) {
    ctx.closest('.chart-card').style.display = 'none';
    return;
  }

  const dsOp  = findDataset(cfData, 'operating') || cfData.datasets[0];
  const dsInv = findDataset(cfData, 'investing')  || cfData.datasets[1];

  const opSeries = extractSeries(dsOp,  null);
  const invSeries= extractSeries(dsInv, null);
  const labels   = opSeries.map(p => p.x);
  const opVals   = opSeries.map(p => p.y);
  const invVals  = invSeries.length ? invSeries.map(p => p.y) : labels.map(() => 0);
  const fcfVals  = opVals.map((v, i) => v + (invVals[i] || 0));

  charts.cashflow = new Chart(ctx, {
    type: 'bar',
    data: { labels, datasets: [
      { label:'Operating CF', data: opVals,  backgroundColor: CHART_COLORS.green + 'cc', borderRadius:4 },
      { label:'Investing CF', data: invVals, backgroundColor: CHART_COLORS.red   + 'cc', borderRadius:4 },
      { label:'Free CF',      data: fcfVals, backgroundColor: CHART_COLORS.blue  + 'cc', borderRadius:4 },
    ]},
    options: {
      responsive:true, maintainAspectRatio:false,
      interaction:{ mode:'index', intersect:false },
      scales:{
        x:{ grid:{ display:false } },
        y:{ grid:{ color:'rgba(255,255,255,0.04)' }, title:{display:true,text:'Amount (Cr)'}, ticks:{callback: v=>'₹'+v.toLocaleString('en-IN')} }
      },
      plugins:{ legend:{ position:'top', labels:{boxWidth:12,padding:12} } }
    }
  });
}

// Shared options factory
function baseOptions({ yTick, yTitle, yMin } = {}) {
  return {
    responsive:true, maintainAspectRatio:false,
    interaction:{ mode:'index', intersect:false },
    scales:{
      x:{ type:'time', time:{tooltipFormat:'dd MMM yyyy'}, grid:{color:'rgba(255,255,255,0.04)'}, ticks:{maxTicksLimit:8} },
      y:{
        grid:{color:'rgba(255,255,255,0.04)'},
        ...(yTitle ? {title:{display:true,text:yTitle}} : {}),
        ...(yMin !== undefined ? {min:yMin} : {}),
        ...(yTick ? {ticks:{callback:yTick}} : {}),
      }
    },
    plugins:{ legend:{ position:'top', labels:{boxWidth:12,padding:12} } }
  };
}

// Time-range toggle (called from HTML)
function filterChart(chartName, days, btn) {
  btn?.closest('.chart-timerange')?.querySelectorAll('button').forEach(b => b.classList.remove('active'));
  btn?.classList.add('active');
  if (chartName === 'price')   buildPriceChart(rawChartData?.price, days);
  if (chartName === 'margins') buildMarginsChart(rawChartData?.margins, days);
}
