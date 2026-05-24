<?php
$pageTitle = 'NSE Stock Analysis';
$extraJs   = ['/public/js/search.js'];
$recent    = Analysis::getRecent(6);
?>

<div class="hero">
  <div class="hero__eyebrow">Powered by AI · NSE India</div>
  <h1 class="hero__title">
    Instant equity analysis<br>
    for <span>any NSE stock</span>
  </h1>
  <p class="hero__sub">
    Deep fundamental + technical analysis in seconds. Buy, Hold, or Avoid — with the numbers to back it up.
  </p>

  <div class="hero__search" id="heroSearchWrap">
    <input
      type="text"
      class="hero__search-input"
      id="heroSearch"
      placeholder="Enter NSE symbol or company name… e.g. RELIANCE"
      autocomplete="off"
      autofocus
    >
    <button class="hero__search-btn" id="heroSearchBtn">→</button>
    <div class="autocomplete-dropdown" id="heroAutocomplete"></div>
  </div>

  <div class="hero__hints">
    <span class="hero__hint" data-symbol="RELIANCE">RELIANCE</span>
    <span class="hero__hint" data-symbol="TCS">TCS</span>
    <span class="hero__hint" data-symbol="INFY">INFY</span>
    <span class="hero__hint" data-symbol="HDFCBANK">HDFCBANK</span>
    <span class="hero__hint" data-symbol="TATAMOTORS">TATAMOTORS</span>
    <span class="hero__hint" data-symbol="WIPRO">WIPRO</span>
  </div>
</div>

<?php if (!empty($recent)): ?>
<div class="mt-24">
  <div class="section-header">
    <h2 class="section-title">Recent Analyses</h2>
  </div>
  <div class="recent-grid">
    <?php foreach ($recent as $r):
      $vc = verdictClass($r['verdict'] ?? 'hold');
    ?>
    <a href="/analysis/<?= htmlspecialchars($r['nse_symbol']) ?>" class="recent-card">
      <div class="recent-card__top">
        <span class="recent-card__symbol"><?= htmlspecialchars($r['nse_symbol']) ?></span>
        <span class="badge badge--<?= $vc ?>">
          <?= verdictIcon($r['verdict'] ?? 'hold') ?> <?= htmlspecialchars($r['verdict'] ?? 'Hold') ?>
        </span>
      </div>
      <div class="recent-card__name"><?= htmlspecialchars($r['company_name']) ?></div>
      <div class="recent-card__sector"><?= htmlspecialchars($r['sector'] ?? '') ?></div>
      <div class="recent-card__bottom">
        <span class="recent-card__price num">
          <?php if ($r['price_at_time']): ?>Rs <?= number_format((float)$r['price_at_time'], 2) ?><?php endif; ?>
        </span>
        <span class="recent-card__time"><?= timeAgo($r['created_at']) ?></span>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<script>
// Quick-hint chips
document.querySelectorAll('.hero__hint').forEach(el => {
  el.addEventListener('click', () => {
    window.location.href = '/analysis/' + el.dataset.symbol;
  });
});
</script>
