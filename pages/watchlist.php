<?php
Auth::require(); // redirect to /login if not authenticated

$pageTitle  = 'Watchlist';
$extraJs    = [APP_BASE . '/public/js/watchlist.js'];
$stocks     = Watchlist::getAll();
?>

<div class="mt-16">
  <div class="section-header">
    <h1 class="section-title">My Watchlist</h1>
    <span style="font-size:0.85rem;color:var(--c-text3);"><?= count($stocks) ?> stocks</span>
  </div>

  <?php if (empty($stocks)): ?>
  <div class="empty-state">
    <div class="empty-state__icon">☆</div>
    <div class="empty-state__title">No stocks in watchlist yet</div>
    <div class="empty-state__sub">Search for any NSE stock and click "Add to Watchlist" to track it here.</div>
    <div style="margin-top:20px;"><a href="/" class="btn btn--primary">Search Stocks</a></div>
  </div>

  <?php else: ?>
  <div class="table-wrap">
    <table class="data-table" id="watchlistTable">
      <thead>
        <tr>
          <th>Company</th>
          <th>Symbol</th>
          <th>Price</th>
          <th>Last Verdict</th>
          <th>Confidence</th>
          <th>Price Alerts</th>
          <th>Last Analysis</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($stocks as $s):
          $vc = verdictClass($s['last_verdict'] ?? 'hold');
        ?>
        <tr id="wl-row-<?= $s['company_id'] ?>">
          <td>
            <a href="<?= APP_BASE ?>/analysis/<?= htmlspecialchars($s['nse_symbol']) ?>" style="color:var(--c-text);">
              <?= htmlspecialchars($s['company_name']) ?>
            </a>
            <div style="font-size:0.75rem;color:var(--c-text3);"><?= htmlspecialchars($s['sector'] ?? '') ?></div>
          </td>
          <td>
            <span class="num" style="color:var(--c-primary);"><?= htmlspecialchars($s['nse_symbol']) ?></span>
          </td>
          <td>
            <span class="num wl-price" data-id="<?= $s['company_id'] ?>" data-symbol="<?= htmlspecialchars($s['nse_symbol']) ?>">—</span>
          </td>
          <td>
            <?php if ($s['last_verdict']): ?>
            <span class="badge badge--<?= $vc ?>"><?= verdictIcon($s['last_verdict']) ?> <?= htmlspecialchars($s['last_verdict']) ?></span>
            <?php else: ?>
            <span style="color:var(--c-text3);font-size:0.85rem;">No analysis</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($s['last_confidence'] !== null): ?>
            <span class="num"><?= $s['last_confidence'] ?>/100</span>
            <?php else: echo '—'; endif; ?>
          </td>
          <td>
            <div class="alert-wrap" id="alerts-<?= $s['company_id'] ?>">
              <div class="alert-display" onclick="editAlert(<?= $s['company_id'] ?>)">
                <?php if ($s['alert_price_above'] || $s['alert_price_below']): ?>
                  <?php if ($s['alert_price_above']): ?>
                  <span class="alert-tag alert-tag--above">▲ ₹<?= number_format((float)$s['alert_price_above'], 2) ?></span>
                  <?php endif; ?>
                  <?php if ($s['alert_price_below']): ?>
                  <span class="alert-tag alert-tag--below">▼ ₹<?= number_format((float)$s['alert_price_below'], 2) ?></span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="alert-tag alert-tag--none">+ Set alert</span>
                <?php endif; ?>
              </div>
              <form class="alert-form" id="alert-form-<?= $s['company_id'] ?>" style="display:none;"
                    onsubmit="saveAlert(event, <?= $s['company_id'] ?>)">
                <input type="number" step="0.01" placeholder="Above ₹"
                       class="form-input form-input--sm" name="alert_above"
                       value="<?= htmlspecialchars($s['alert_price_above'] ?? '') ?>">
                <input type="number" step="0.01" placeholder="Below ₹"
                       class="form-input form-input--sm" name="alert_below"
                       value="<?= htmlspecialchars($s['alert_price_below'] ?? '') ?>">
                <button type="submit" class="btn btn--primary btn--sm">Save</button>
                <button type="button" class="btn btn--ghost btn--sm" onclick="cancelAlert(<?= $s['company_id'] ?>)">✕</button>
              </form>
            </div>
          </td>
          <td>
            <span style="font-size:0.8rem;color:var(--c-text3);">
              <?= $s['last_analysis_at'] ? timeAgo($s['last_analysis_at']) : 'Never' ?>
            </span>
          </td>
          <td>
            <div class="flex gap-8">
              <a href="<?= APP_BASE ?>/analysis/<?= htmlspecialchars($s['nse_symbol']) ?>" class="btn btn--ghost btn--sm">Analyse</a>
              <button class="btn btn--danger btn--sm" onclick="removeFromWatchlist(<?= $s['company_id'] ?>, this)">✕ Remove</button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<script>
function editAlert(id) {
  document.querySelector('#alerts-' + id + ' .alert-display').style.display = 'none';
  document.getElementById('alert-form-' + id).style.display = 'flex';
}
function cancelAlert(id) {
  document.querySelector('#alerts-' + id + ' .alert-display').style.display = '';
  document.getElementById('alert-form-' + id).style.display = 'none';
}
async function saveAlert(e, companyId) {
  e.preventDefault();
  const form  = e.target;
  const above = form.alert_above.value || null;
  const below = form.alert_below.value || null;
  const fd = new FormData();
  fd.append('action', 'update_alert');
  fd.append('company_id', companyId);
  if (above) fd.append('alert_above', above);
  if (below) fd.append('alert_below', below);
  const res  = await fetch(APP_BASE + '/api/watchlist', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.ok) {
    location.reload();
  } else {
    showToast(data.error || 'Failed to save alert', 'error');
  }
}
</script>
