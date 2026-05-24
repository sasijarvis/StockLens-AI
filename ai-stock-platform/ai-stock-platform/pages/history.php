<?php
// pages/history.php
$pageTitle = 'Analysis History';
$filter    = $_GET['verdict'] ?? '';
$page_num  = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 20;
$offset    = ($page_num - 1) * $perPage;

$rows  = Analysis::getHistory($perPage, $offset, $filter ?: null);
$total = Analysis::countHistory($filter ?: null);
$pages = (int)ceil($total / $perPage);
?>

<div class="mt-16">
  <div class="section-header">
    <h1 class="section-title">Analysis History</h1>
    <span style="font-size:0.85rem;color:var(--c-text3);"><?= $total ?> analyses</span>
  </div>

  <!-- Filter bar -->
  <div class="filter-bar">
    <?php
    $filters = ['' => 'All', 'Buy' => 'Buy', 'Hold' => 'Hold', 'Avoid' => 'Avoid'];
    foreach ($filters as $val => $label):
      $active = ($filter === $val);
      $cls = $active ? ($val ? 'active-' . strtolower($val) : 'active') : '';
    ?>
    <button
      class="filter-btn <?= $cls ?>"
      onclick="window.location.href='/history<?= $val ? '?verdict=' . $val : '' ?>'"
    ><?= htmlspecialchars($label) ?></button>
    <?php endforeach; ?>
  </div>

  <?php if (empty($rows)): ?>
  <div class="empty-state">
    <div class="empty-state__icon">📋</div>
    <div class="empty-state__title">No analyses yet</div>
    <div class="empty-state__sub">Run your first stock analysis to see it here.</div>
    <div style="margin-top:20px;"><a href="/" class="btn btn--primary">Search Stocks</a></div>
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Company</th>
          <th>Symbol</th>
          <th>Price at Time</th>
          <th>Verdict</th>
          <th>Confidence</th>
          <th>Model</th>
          <th>View</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $vc = verdictClass($r['verdict'] ?? 'hold');
        ?>
        <tr>
          <td style="color:var(--c-text3);font-size:0.8rem;"><?= date('d M Y H:i', strtotime($r['created_at'])) ?></td>
          <td>
            <?= htmlspecialchars($r['company_name']) ?>
            <div style="font-size:0.75rem;color:var(--c-text3);"><?= htmlspecialchars($r['sector'] ?? '') ?></div>
          </td>
          <td><span class="num" style="color:var(--c-primary);"><?= htmlspecialchars($r['nse_symbol']) ?></span></td>
          <td class="num">Rs <?= $r['price_at_time'] ? number_format((float)$r['price_at_time'], 2) : '—' ?></td>
          <td>
            <?php if ($r['verdict']): ?>
            <span class="badge badge--<?= $vc ?>"><?= verdictIcon($r['verdict']) ?> <?= htmlspecialchars($r['verdict']) ?></span>
            <?php else: echo '—'; endif; ?>
          </td>
          <td class="num"><?= $r['confidence_score'] !== null ? $r['confidence_score'] . '/100' : '—' ?></td>
          <td>
            <span class="model-badge"><?= htmlspecialchars(explode('/', $r['model_used'] ?? 'N/A')[1] ?? ($r['model_used'] ?? 'N/A')) ?></span>
          </td>
          <td>
            <a href="/analysis/<?= htmlspecialchars($r['nse_symbol']) ?>" class="btn btn--ghost btn--sm">View →</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($pages > 1): ?>
  <div class="pagination">
    <?php if ($page_num > 1): ?>
    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page_num - 1])) ?>" class="page-btn">←</a>
    <?php endif; ?>
    <?php for ($i = max(1, $page_num - 2); $i <= min($pages, $page_num + 2); $i++): ?>
    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"
       class="page-btn <?= $i === $page_num ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page_num < $pages): ?>
    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page_num + 1])) ?>" class="page-btn">→</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
