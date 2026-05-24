// compare.js — side-by-side stock comparison
document.addEventListener('DOMContentLoaded', () => {
  // Init autocomplete on all 3 compare inputs
  document.querySelectorAll('.compare-stock-input').forEach((input, i) => {
    const dropdown = document.getElementById('compareAC' + i);
    if (dropdown) {
      createAutocomplete(input, dropdown, item => {
        input.value = item.symbol;
      });
    }
  });

  // If symbols already in URL, run compare automatically
  const params  = new URLSearchParams(window.location.search);
  const symbols = params.get('symbols');
  if (symbols && symbols.split(',').filter(Boolean).length >= 2) {
    runCompare();
  }
});

async function runCompare() {
  const inputs  = document.querySelectorAll('.compare-stock-input');
  const symbols = Array.from(inputs)
    .map(i => i.value.trim().toUpperCase())
    .filter(Boolean);

  if (symbols.length < 2) {
    showToast('Enter at least 2 stock symbols to compare', 'info');
    return;
  }

  // Update URL
  const url = new URL(window.location.href);
  url.searchParams.set('symbols', symbols.join(','));
  window.history.replaceState({}, '', url);

  document.getElementById('compareEmpty').style.display   = 'none';
  document.getElementById('compareResults').style.display = 'none';
  document.getElementById('compareLoading').style.display = '';

  try {
    // Fetch analyse data for each symbol (uses cache if available)
    const fetches = symbols.map(sym =>
      fetch((window.APP_BASE || '') + `/api/analyse?symbol=${encodeURIComponent(sym)}`)
        .then(r => r.json())
        .catch(() => null)
    );

    const results = await Promise.all(fetches);
    const valid   = results.filter(r => r && !r.error);

    if (valid.length < 2) {
      showToast('Could not load data for enough symbols. Check the symbols and try again.', 'error');
      document.getElementById('compareLoading').style.display = 'none';
      document.getElementById('compareEmpty').style.display   = '';
      return;
    }

    const stockData = valid.map(r => r.data);
    renderCompareTable(stockData);
    renderRadarChart(stockData);

  } catch (e) {
    showToast('Comparison failed: ' + e.message, 'error');
    document.getElementById('compareLoading').style.display = 'none';
    document.getElementById('compareEmpty').style.display   = '';
  }
}

function renderCompareTable(stocks) {
  document.getElementById('compareLoading').style.display = 'none';
  document.getElementById('compareResults').style.display = '';

  const thead = document.getElementById('compareHead');
  const tbody = document.getElementById('compareBody');

  // Header row
  thead.innerHTML = `
    <tr>
      <th style="min-width:180px;">Metric</th>
      ${stocks.map(s => `<th class="stock-col">${s.symbol}<br><span style="font-weight:400;color:var(--c-text3);font-size:0.75rem;">${s.company_name}</span></th>`).join('')}
    </tr>
  `;

  // Define rows: [label, extractor, format, higherIsBetter]
  const rows = [
    ['Verdict',          s => s.verdict,                              v => verdictHtml(v),            null],
    ['Confidence',       s => s.confidence,                           v => v + '/100',                 true],
    ['Price',            s => s.price_data?.last_price,               v => '₹' + fmtNum(v, 2),        null],
    ['Change Today',     s => s.price_data?.change_pct,               v => fmtPct(v),                  true],
    ['Market Cap (Cr)',  s => s.price_data?.market_cap_cr || parseMetric(s, 'market_cap_cr'),  v => fmtCr(v), true],
    ['P/E',              s => s.price_data?.pe_ratio,                 v => fmtNum(v, 1) + 'x',        false],
    ['Median P/E (5yr)', s => parseMetric(s, 'median_pe'),            v => fmtNum(v, 1) + 'x',        null],
    ['EV/EBITDA',        s => parseMetric(s, 'ev_ebitda'),            v => fmtNum(v, 1) + 'x',        false],
    ['P/BV',             s => parseMetric(s, 'pbv'),                  v => fmtNum(v, 1) + 'x',        false],
    ['52W High',         s => s.price_data?.week_high,                v => '₹' + fmtNum(v, 2),        null],
    ['52W Low',          s => s.price_data?.week_low,                 v => '₹' + fmtNum(v, 2),        null],
    ['RSI-14',           s => parseMetric(s, 'rsi_14'),               v => fmtNum(v, 1),               null],
    ['Revenue CAGR (5yr)',s => parseMetric(s, 'revenue_cagr'),        v => fmtNum(v, 1) + '%',        true],
    ['Profit CAGR (5yr)',s => parseMetric(s, 'profit_cagr'),          v => fmtNum(v, 1) + '%',        true],
    ['Op. Margin',       s => parseMetric(s, 'opm'),                  v => fmtNum(v, 1) + '%',        true],
    ['Net Margin',       s => parseMetric(s, 'npm'),                  v => fmtNum(v, 1) + '%',        true],
    ['Free Cash Flow',   s => parseMetric(s, 'free_cash_flow'),       v => fmtCr(v),                  true],
    ['FCF/PAT',          s => parseMetric(s, 'fcf_to_pat'),           v => fmtNum(v, 2) + 'x',        true],
    ['Debt (Cr)',        s => parseMetric(s, 'debt_latest'),          v => fmtCr(v),                  false],
    ['Div. Yield',       s => parseMetric(s, 'div_yield'),            v => fmtNum(v, 2) + '%',        true],
  ];

  tbody.innerHTML = rows.map(([label, extractor, formatter, higherIsBetter]) => {
    const rawValues = stocks.map(s => {
      try { return extractor(s); } catch { return null; }
    });

    const numericVals = rawValues.map(v => {
      const n = parseFloat(v);
      return isNaN(n) ? null : n;
    });

    // Determine best / worst indices (skip nulls and non-numeric)
    let bestIdx = -1, worstIdx = -1;
    if (higherIsBetter !== null) {
      const validNums = numericVals.filter(v => v !== null);
      if (validNums.length >= 2) {
        const maxVal = Math.max(...validNums);
        const minVal = Math.min(...validNums);
        bestIdx  = numericVals.indexOf(higherIsBetter ? maxVal : minVal);
        worstIdx = numericVals.indexOf(higherIsBetter ? minVal : maxVal);
        if (bestIdx === worstIdx) worstIdx = -1; // all same
      }
    }

    const cells = stocks.map((s, i) => {
      const raw = rawValues[i];
      let display;
      try { display = (raw !== null && raw !== undefined) ? formatter(raw) : 'N/A'; }
      catch { display = 'N/A'; }

      let cls = '';
      if (i === bestIdx)  cls = 'td-best';
      if (i === worstIdx) cls = 'td-worst';
      // Verdict row: no best/worst, just colour by verdict value
      if (label === 'Verdict') cls = '';

      return `<td class="num ${cls}">${display}</td>`;
    }).join('');

    return `<tr><td style="color:var(--c-text2);font-family:var(--font-sans);">${label}</td>${cells}</tr>`;
  }).join('');
}

// ── Radar chart ───────────────────────────────────────────────────────────────

let _radarChart = null;

function renderRadarChart(stocks) {
  const canvas = document.getElementById('compareRadar');
  if (!canvas || typeof Chart === 'undefined') return;

  // Dimensions for the radar — [label, extractor, higherIsBetter]
  const axes = [
    ['Revenue Growth',  s => parseMetric(s, 'revenue_cagr'),  true],
    ['Profit Growth',   s => parseMetric(s, 'profit_cagr'),   true],
    ['Op. Margin',      s => parseMetric(s, 'opm'),           true],
    ['FCF Quality',     s => parseMetric(s, 'fcf_to_pat'),    true],
    ['Low Debt',        s => { const d = parseMetric(s, 'debt_latest'); return d != null ? -d : null; }, true],
    ['Div. Yield',      s => parseMetric(s, 'div_yield'),     true],
    ['Confidence',      s => s.confidence,                    true],
  ];

  // Normalise each axis to 0-100 across the compared stocks
  const rawMatrix = axes.map(([, extractor]) =>
    stocks.map(s => { try { return parseFloat(extractor(s)) || 0; } catch { return 0; } })
  );

  const normalised = rawMatrix.map(vals => {
    const min = Math.min(...vals);
    const max = Math.max(...vals);
    if (max === min) return vals.map(() => 50);
    return vals.map(v => Math.round(((v - min) / (max - min)) * 100));
  });

  // Transpose to per-stock arrays
  const stockDatasets = stocks.map((s, si) => ({
    label: s.symbol,
    data:  normalised.map(axisVals => axisVals[si]),
    borderWidth: 2,
  }));

  // Colour palette
  const palette = [
    { border: '#6366f1', bg: 'rgba(99,102,241,0.15)' },
    { border: '#22c55e', bg: 'rgba(34,197,94,0.15)'  },
    { border: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  ];
  stockDatasets.forEach((ds, i) => {
    ds.borderColor     = palette[i % palette.length].border;
    ds.backgroundColor = palette[i % palette.length].bg;
    ds.pointBackgroundColor = palette[i % palette.length].border;
  });

  if (_radarChart) _radarChart.destroy();

  _radarChart = new Chart(canvas, {
    type: 'radar',
    data: {
      labels:   axes.map(([label]) => label),
      datasets: stockDatasets,
    },
    options: {
      responsive: true,
      plugins: { legend: { labels: { color: '#e2e8f0' } } },
      scales: {
        r: {
          min: 0, max: 100,
          ticks:     { display: false },
          grid:      { color: 'rgba(255,255,255,0.1)' },
          angleLines:{ color: 'rgba(255,255,255,0.1)' },
          pointLabels: { color: '#94a3b8', font: { size: 12 } },
        },
      },
    },
  });
}

function parseMetric(stockData, key) {
  try {
    const m = JSON.parse(stockData.metrics_json || '{}');
    return m[key] ?? null;
  } catch { return null; }
}

function verdictHtml(v) {
  if (!v) return '<span style="color:var(--c-text3)">—</span>';
  const cls = v.toLowerCase() === 'buy' ? 'buy' : v.toLowerCase() === 'avoid' ? 'avoid' : 'hold';
  const icons = { buy: '▲', hold: '●', avoid: '▼' };
  return `<span class="badge badge--${cls}">${icons[cls] || ''} ${v}</span>`;
}
