<?php
// pages/watchlist.php
$pageTitle  = 'Watchlist';
$extraJs    = ['/public/js/watchlist.js'];
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
          <th>Last Verdict</th>
          <th>Confidence</th>
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
            <a href="/analysis/<?= htmlspecialchars($s['nse_symbol']) ?>" style="color:var(--c-text);">
              <?= htmlspecialchars($s['company_name']) ?>
            </a>
            <div style="font-size:0.75rem;color:var(--c-text3);"><?= htmlspecialchars($s['sector'] ?? '') ?></div>
          </td>
          <td>
            <span class="num" style="color:var(--c-primary);"><?= htmlspecialchars($s['nse_symbol']) ?></span>
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
            <span style="font-size:0.8rem;color:var(--c-text3);">
              <?= $s['last_analysis_at'] ? timeAgo($s['last_analysis_at']) : 'Never' ?>
            </span>
          </td>
          <td>
            <div class="flex gap-8">
              <a href="/analysis/<?= htmlspecialchars($s['nse_symbol']) ?>" class="btn btn--ghost btn--sm">Analyse</a>
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
