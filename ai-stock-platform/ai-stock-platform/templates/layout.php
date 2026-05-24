<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'AI Stock Analysis') ?> — StockLens AI</title>
  <meta name="description" content="AI-powered NSE stock analysis. Get instant Buy/Hold/Avoid verdicts with deep fundamental and technical insights.">
  <link rel="stylesheet" href="/public/css/main.css">
  <?php if (!empty($extraCss)): foreach ($extraCss as $css): ?>
  <link rel="stylesheet" href="<?= $css ?>">
  <?php endforeach; endif; ?>
</head>
<body>
<div class="app-wrapper">

  <!-- Navbar -->
  <nav class="navbar">
    <div class="navbar__inner">
      <a href="/" class="navbar__brand">
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
        <a href="/" class="<?= ($page === 'home') ? 'active' : '' ?>">Home</a>
        <a href="/watchlist" class="<?= ($page === 'watchlist') ? 'active' : '' ?>">Watchlist</a>
        <a href="/history" class="<?= ($page === 'history') ? 'active' : '' ?>">History</a>
        <a href="/compare" class="<?= ($page === 'compare') ? 'active' : '' ?>">Compare</a>
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

<!-- Core JS -->
<script src="/public/js/app.js"></script>
<?php if (!empty($extraJs)): foreach ($extraJs as $js): ?>
<script src="<?= $js ?>"></script>
<?php endforeach; endif; ?>
</body>
</html>
