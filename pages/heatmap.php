<?php
$pageTitle = 'Sector Heat Map';
$extraJs   = [APP_BASE . '/public/js/sector-heat.js'];
?>

<div class="mt-16">

  <div style="margin-bottom:20px;">
    <h1 style="font-size:1.4rem;font-weight:700;margin-bottom:4px;">Sector Heat Map</h1>
    <p style="color:var(--c-text3);font-size:0.875rem;">Latest AI verdict distribution across all analysed sectors. Updates each time a stock is re-analysed.</p>
  </div>

  <!-- Summary bar -->
  <div class="card" id="heatmapSummary" style="display:none;margin-bottom:20px;">
    <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:center;">
      <div>
        <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text3);margin-bottom:2px;">Total Sectors</div>
        <div class="num" id="hmTotalSectors" style="font-size:1.3rem;font-weight:700;">—</div>
      </div>
      <div>
        <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text3);margin-bottom:2px;">Total Stocks</div>
        <div class="num" id="hmTotalStocks" style="font-size:1.3rem;font-weight:700;">—</div>
      </div>
      <div style="margin-left:auto;display:flex;gap:12px;">
        <span class="hm-pill hm-pill--buy" id="hmTotalBuy">Buy: —</span>
        <span class="hm-pill hm-pill--hold" id="hmTotalHold">Hold: —</span>
        <span class="hm-pill hm-pill--avoid" id="hmTotalAvoid">Avoid: —</span>
      </div>
    </div>
  </div>

  <!-- Loading state -->
  <div id="hmLoading" style="text-align:center;padding:60px;color:var(--c-text3);">
    <div class="progress-spinner" style="margin:0 auto 12px;"></div>
    <div>Loading sector data…</div>
  </div>

  <!-- Error state -->
  <div id="hmError" style="display:none;" class="empty-state">
    <div class="empty-state__icon">⚠️</div>
    <div class="empty-state__title">Could not load heatmap</div>
    <div class="empty-state__sub" id="hmErrorMsg">Please try again.</div>
    <button class="btn btn--primary" style="margin-top:16px;" onclick="loadHeatmap()">Retry</button>
  </div>

  <!-- Heatmap grid -->
  <div class="heatmap-grid" id="heatmapGrid" style="display:none;"></div>

  <!-- Empty state -->
  <div id="hmEmpty" style="display:none;" class="empty-state">
    <div class="empty-state__icon">📊</div>
    <div class="empty-state__title">No sector data yet</div>
    <div class="empty-state__sub">Analyse some stocks first to see the sector breakdown here.</div>
    <a href="/" class="btn btn--primary" style="margin-top:16px;">Analyse a Stock</a>
  </div>

</div>
