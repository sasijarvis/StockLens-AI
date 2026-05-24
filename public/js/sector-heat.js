// ── sector-heat.js ─────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', loadHeatmap);

async function loadHeatmap() {
  setStates({ loading: true, error: false, empty: false, grid: false, summary: false });

  try {
    const res  = await fetch((window.APP_BASE || '') + '/api/analyse?action=heatmap');
    const json = await res.json();

    if (!res.ok || json.error) {
      showHmError(json.error || 'Failed to load heatmap data.');
      return;
    }

    const rows = json.heatmap || [];
    if (!rows.length) {
      setStates({ loading: false, empty: true });
      return;
    }

    renderHeatmap(rows);
  } catch (e) {
    showHmError('Network error. Please check your connection.');
    console.error(e);
  }
}

function renderHeatmap(rows) {
  const grid = document.getElementById('heatmapGrid');
  if (!grid) return;

  // Summary stats
  const totalStocks = rows.reduce((s, r) => s + Number(r.total || 0), 0);
  const totalBuy    = rows.reduce((s, r) => s + Number(r.buy_count || 0), 0);
  const totalHold   = rows.reduce((s, r) => s + Number(r.hold_count || 0), 0);
  const totalAvoid  = rows.reduce((s, r) => s + Number(r.avoid_count || 0), 0);

  setEl('hmTotalSectors', rows.length);
  setEl('hmTotalStocks',  totalStocks);
  setEl('hmTotalBuy',   `▲ Buy: ${totalBuy}`);
  setEl('hmTotalHold',  `● Hold: ${totalHold}`);
  setEl('hmTotalAvoid', `▼ Avoid: ${totalAvoid}`);

  grid.innerHTML = rows.map(row => {
    const total  = Number(row.total  || 0);
    const buy    = Number(row.buy_count   || 0);
    const hold   = Number(row.hold_count  || 0);
    const avoid  = Number(row.avoid_count || 0);
    const conf   = row.avg_confidence != null ? Number(row.avg_confidence).toFixed(1) : '—';
    const pBuy   = total > 0 ? (buy   / total * 100).toFixed(1) : 0;
    const pHold  = total > 0 ? (hold  / total * 100).toFixed(1) : 0;
    const pAvoid = total > 0 ? (avoid / total * 100).toFixed(1) : 0;

    // Dominant verdict colour for the card border
    const dominant = buy >= hold && buy >= avoid ? 'buy' : avoid >= hold ? 'avoid' : 'hold';
    const lastDate = row.last_analysed
      ? new Date(row.last_analysed).toLocaleDateString('en-IN', {day:'numeric', month:'short'})
      : '';

    return `
      <div class="heatmap-card" style="border-top: 3px solid var(--c-${dominant});">
        <div class="heatmap-card__sector">${row.sector || 'Unknown'}</div>
        <div class="heatmap-card__counts">
          ${buy   > 0 ? `<span class="hm-pill hm-pill--buy">▲ ${buy} Buy</span>`   : ''}
          ${hold  > 0 ? `<span class="hm-pill hm-pill--hold">● ${hold} Hold</span>` : ''}
          ${avoid > 0 ? `<span class="hm-pill hm-pill--avoid">▼ ${avoid} Avoid</span>` : ''}
        </div>
        <div class="heatmap-card__bar-wrap">
          <div class="hm-bar-segment hm-bar-buy"   style="width:${pBuy}%"></div>
          <div class="hm-bar-segment hm-bar-hold"  style="width:${pHold}%"></div>
          <div class="hm-bar-segment hm-bar-avoid" style="width:${pAvoid}%"></div>
        </div>
        <div class="heatmap-card__meta">
          <span>${total} stock${total !== 1 ? 's' : ''} analysed</span>
          <span class="hm-conf">Avg confidence: ${conf}/100</span>
        </div>
        ${lastDate ? `<div style="font-size:0.68rem;color:var(--c-text3);margin-top:4px;">Last updated: ${lastDate}</div>` : ''}
      </div>`;
  }).join('');

  setStates({ loading: false, grid: true, summary: true });
}

function setStates({ loading, error, empty, grid, summary }) {
  const show = (id, visible) => {
    const el = document.getElementById(id);
    if (el) el.style.display = visible ? '' : 'none';
  };
  show('hmLoading',       loading  === true);
  show('hmError',         error    === true);
  show('hmEmpty',         empty    === true);
  show('heatmapGrid',     grid     === true);
  show('heatmapSummary',  summary  === true);
}

function showHmError(msg) {
  setStates({ loading: false, error: true });
  const el = document.getElementById('hmErrorMsg');
  if (el) el.textContent = msg;
}

function setEl(id, val) {
  const el = document.getElementById(id);
  if (el) el.textContent = val;
}
