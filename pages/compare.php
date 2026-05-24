<?php
// pages/compare.php
$pageTitle = 'Compare Stocks';
$extraJs   = ['https://cdn.jsdelivr.net/npm/chart.js@4', '/public/js/compare.js'];
$symbols   = array_filter(explode(',', strtoupper($_GET['symbols'] ?? '')));
$symbols   = array_slice(array_map(fn($s) => preg_replace('/[^A-Z0-9&\-]/', '', $s), $symbols), 0, 3);
?>

<div class="mt-16">
  <div class="section-header">
    <h1 class="section-title">Compare Stocks</h1>
    <span style="font-size:0.85rem;color:var(--c-text3);">Side-by-side comparison of 2–3 NSE stocks</span>
  </div>

  <!-- Stock pickers -->
  <div class="compare-inputs" id="compareInputs">
    <?php for ($i = 0; $i < 3; $i++): ?>
    <div class="compare-input-wrap">
      <input
        type="text"
        class="compare-stock-input"
        data-index="<?= $i ?>"
        placeholder="Stock <?= $i + 1 ?> symbol<?= $i > 1 ? ' (optional)' : '' ?>"
        value="<?= htmlspecialchars($symbols[$i] ?? '') ?>"
        autocomplete="off"
      >
      <div class="autocomplete-dropdown compare-autocomplete" id="compareAC<?= $i ?>"></div>
    </div>
    <?php endfor; ?>
    <button class="btn btn--primary" onclick="runCompare()">Compare →</button>
  </div>

  <!-- Results -->
  <div id="compareResults" style="display:none;">
    <!-- Radar chart -->
    <div class="chart-card" style="margin-bottom:24px;">
      <div class="chart-card__header">
        <div class="chart-card__title">Fundamentals Radar</div>
        <span style="font-size:0.75rem;color:var(--c-text3);">Normalised scores (higher = better for each axis)</span>
      </div>
      <div style="max-width:520px;margin:0 auto;padding:16px;">
        <canvas id="compareRadar"></canvas>
      </div>
    </div>

    <div class="table-wrap">
      <table class="data-table compare-table" id="compareTable">
        <thead id="compareHead"></thead>
        <tbody id="compareBody"></tbody>
      </table>
    </div>
  </div>

  <div id="compareLoading" style="display:none;">
    <div class="progress-indicator" style="padding:32px;">
      <div class="progress-spinner"></div>
      <div style="color:var(--c-text3);font-size:0.9rem;">Fetching comparison data…</div>
    </div>
  </div>

  <div id="compareEmpty" <?= empty($symbols) ? '' : 'style="display:none;"' ?>>
    <div class="empty-state">
      <div class="empty-state__icon">⚖️</div>
      <div class="empty-state__title">Enter 2 or 3 stock symbols above</div>
      <div class="empty-state__sub">Compare P/E, margins, FCF, debt and more side by side.</div>
    </div>
  </div>
</div>
