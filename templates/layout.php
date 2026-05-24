<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'AI Stock Analysis') ?> — StockLens AI</title>
  <meta name="description" content="AI-powered NSE stock analysis. Get instant Buy/Hold/Avoid verdicts with deep fundamental and technical insights.">
  <link rel="stylesheet" href="<?= APP_BASE ?>/public/css/main.css">
  <?php if (!empty($extraCss)): foreach ($extraCss as $css): ?>
  <link rel="stylesheet" href="<?= $css ?>">
  <?php endforeach; endif; ?>
</head>
<body>
<div class="app-wrapper">

  <!-- Navbar -->
  <nav class="navbar">
    <div class="navbar__inner">
      <a href="<?= APP_BASE ?>" class="navbar__brand">
        <div class="navbar__brand-icon">📈</div>
        StockLens
      </a>

      <!-- Navbar search (hidden on mobile, hero has its own) -->
      <div class="navbar__search" id="navSearchWrap">
        <span class="navbar__search-icon">🔍</span>
        <input
          type="text"
          class="navbar__search-input"
          id="navSearch"
          placeholder="Search NSE symbol or company…"
          autocomplete="off"
        >
        <div class="autocomplete-dropdown" id="navAutocomplete"></div>
      </div>

      <nav class="navbar__nav">
        <a href="<?= APP_BASE ?>/" class="<?= ($page === 'home') ? 'active' : '' ?>">Home</a>
        <a href="<?= APP_BASE ?>/watchlist" class="<?= ($page === 'watchlist') ? 'active' : '' ?>">Watchlist</a>
        <a href="<?= APP_BASE ?>/history" class="<?= ($page === 'history') ? 'active' : '' ?>">History</a>
        <a href="<?= APP_BASE ?>/compare" class="<?= ($page === 'compare') ? 'active' : '' ?>">Compare</a>
        <a href="<?= APP_BASE ?>/heatmap" class="<?= ($page === 'heatmap') ? 'active' : '' ?>">🔥 Heatmap</a>
        <?php if (Auth::check()): $authUser = Auth::user(); ?>
          <div class="navbar__user">
            <span class="navbar__user-name"><?= htmlspecialchars($authUser['name'] ?: explode('@', $authUser['email'])[0]) ?></span>
            <button class="btn btn--ghost btn--sm" onclick="signOut()">Sign out</button>
          </div>
        <?php else: ?>
          <a href="<?= APP_BASE ?>/login" class="btn btn--ghost btn--sm <?= in_array($page, ['login','register']) ? 'active' : '' ?>">Sign in</a>
        <?php endif; ?>
      </nav>
    </div>
  </nav>

  <!-- Main content injected by pages -->
  <main class="main-content">
    <?= $content ?>
  </main>

  <!-- Footer -->
  <footer class="footer">
    <p>StockLens AI • Powered by <a href="https://openrouter.ai" target="_blank">OpenRouter</a> • Data: NSE India &amp; Screener.in</p>
    <p style="margin-top:4px;">⚠️ For informational purposes only. Not investment advice. Always do your own research.</p>
  </footer>
</div>

<!-- Toast container -->
<div class="toast-container" id="toastContainer"></div>

<!-- Base URL for all JS API calls -->
<script>window.APP_BASE = '<?= APP_BASE ?>';</script>
<!-- Core JS -->
<script src="<?= APP_BASE ?>/public/js/app.js"></script>
<script>
async function signOut() {
  const fd = new FormData(); fd.append('action', 'logout');
  await fetch(APP_BASE + '/api/auth', { method: 'POST', body: fd });
  window.location.href = APP_BASE + '/';
}
</script>
<?php if (!empty($extraJs)): foreach ($extraJs as $js): ?>
<script src="<?= $js ?>"></script>
<?php endforeach; endif; ?>
</body>
</html>
