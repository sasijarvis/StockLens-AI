<?php
$symbol = $GLOBALS['route_param'] ?? '';

if (!$symbol) {
    header('Location: ' . APP_BASE . '/');
    exit;
}

try { $symbol = validateSymbol($symbol); }
catch (Throwable) { header('Location: ' . APP_BASE . '/'); exit; }

$pageTitle = $symbol . ' Analysis';
$extraJs   = [
    'https://cdn.jsdelivr.net/npm/chart.js@4',
    'https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3/dist/chartjs-adapter-date-fns.bundle.min.js',
    APP_BASE . '/public/js/charts.js',
    APP_BASE . '/public/js/analysis.js',
];
?>

<div id="analysisApp" data-symbol="<?= htmlspecialchars($symbol) ?>">

  <!-- ── Loading state ── -->
  <div id="loadingState" class="card mt-16">
    <div class="progress-indicator">
      <div class="progress-spinner"></div>
      <div>
        <div style="font-size:1rem;font-weight:600;margin-bottom:8px;">Analysing <?= htmlspecialchars($symbol) ?>…</div>
        <div style="font-size:0.85rem;color:var(--c-text3);" id="loadingMsg">Initialising…</div>
      </div>
      <div class="progress-steps" id="progressSteps">
        <div class="progress-step" data-step="price">
          <div class="progress-step__dot"></div>
          <span>Fetching live price from NSE</span>
        </div>
        <div class="progress-step" data-step="fundamentals">
          <div class="progress-step__dot"></div>
          <span>Loading financial statements</span>
        </div>
        <div class="progress-step" data-step="charts">
          <div class="progress-step__dot"></div>
          <span>Pulling chart &amp; valuation data</span>
        </div>
        <div class="progress-step" data-step="news">
          <div class="progress-step__dot"></div>
          <span>Scanning latest news</span>
        </div>
        <div class="progress-step" data-step="ai">
          <div class="progress-step__dot"></div>
          <span>Running AI analysis</span>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Error state ── -->
  <div id="errorState" style="display:none;" class="mt-16">
    <div class="empty-state">
      <div class="empty-state__icon">⚠️</div>
      <div class="empty-state__title" id="errorTitle">Something went wrong</div>
      <div class="empty-state__sub" id="errorMsg">Please try again.</div>
      <div style="margin-top:20px;">
        <button class="btn btn--primary" onclick="location.reload()">Try Again</button>
        <a href="/" class="btn btn--ghost" style="margin-left:8px;">← Back</a>
      </div>
    </div>
  </div>

  <!-- ── Main content (hidden until data loads) ── -->
  <div id="mainContent" style="display:none;">

    <!-- Stock Header -->
    <div class="stock-header mt-16" id="stockHeader">
      <div class="stock-header__top">
        <div class="stock-header__name-block">
          <div class="stock-header__name" id="stockName">—</div>
          <div class="stock-header__meta">
            <span id="stockSector">—</span>
            <span id="stockIndices">—</span>
          </div>
        </div>
        <div class="stock-header__price-block">
          <div class="stock-header__price num" id="stockPrice">—</div>
          <div class="stock-header__change num" id="stockChange">—</div>
        </div>
      </div>

      <!-- Key Metrics Strip -->
      <div class="metrics-strip" id="metricsStrip">
        <?php
        $metrics = [
          ['id'=>'m52wHigh',   'label'=>'52W High'],
          ['id'=>'m52wLow',    'label'=>'52W Low'],
          ['id'=>'mPE',        'label'=>'P/E'],
          ['id'=>'mSectorPE',  'label'=>'Sector PE'],
          ['id'=>'mMktCap',    'label'=>'Mkt Cap'],
          ['id'=>'mDivYield',  'label'=>'Div Yield'],
          ['id'=>'mRSI',       'label'=>'RSI-14'],
        ];
        foreach ($metrics as $m): ?>
        <div class="metric-box">
          <div class="metric-box__label"><?= $m['label'] ?></div>
          <div class="metric-box__value num" id="<?= $m['id'] ?>">—</div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Action buttons -->
      <div class="flex gap-8" style="flex-wrap:wrap;align-items:center;">
        <button class="btn btn--ghost btn--sm" id="watchlistBtn" onclick="toggleWatchlist()">☆ Add to Watchlist</button>
        <button class="btn btn--ghost btn--sm" id="reanalyseBtn" onclick="reanalyse()">↻ Re-analyse</button>

        <!-- Model selector -->
        <select id="modelSelector" class="model-select" title="AI model for analysis"
                onchange="localStorage.setItem('selectedModel', this.value)">
          <option value="fast">⚡ Fast (Gemini Flash)</option>
          <option value="balanced">⚖️ Balanced (Claude Haiku)</option>
          <option value="best" selected>★ Best (Claude Sonnet)</option>
        </select>

        <span class="model-badge" id="modelBadge" style="margin-left:auto;"></span>
        <span style="font-size:0.75rem;color:var(--c-text3);align-self:center;" id="cacheNote"></span>
      </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
      <button class="tab-btn active" onclick="switchTab('analysis', this)">📊 Analysis</button>
      <button class="tab-btn" onclick="switchTab('charts', this)">📈 Charts</button>
      <button class="tab-btn" onclick="switchTab('peers', this)">🏢 Peers</button>
      <button class="tab-btn" onclick="switchTab('news', this)">📰 News</button>
    </div>

    <!-- Tab: Analysis -->
    <div class="tab-panel active" id="tab-analysis">

      <!-- ROW 1: Verdict + Conviction Matrix side by side on wide screens -->
      <div class="ai-row ai-row--halves">

        <!-- Verdict card -->
        <div class="analysis-card verdict-card" id="verdictCard">
          <div class="analysis-card__header">
            <div class="analysis-card__title"><span class="analysis-card__icon">⚖️</span> Verdict</div>
            <span class="badge" id="verdictBadge">—</span>
          </div>
          <div class="verdict-card__label" id="verdictLabel">—</div>
          <div class="analysis-card__body" id="verdictBody">—</div>
          <div class="confidence-bar-wrap" id="confidenceWrap" style="display:none;">
            <div class="confidence-bar-label">
              <span>Analyst Confidence</span>
              <span id="confidenceScore">—</span>
            </div>
            <div class="confidence-bar">
              <div class="confidence-bar__fill" id="confidenceFill" style="width:0%"></div>
            </div>
          </div>
        </div>

        <!-- Conviction Matrix card -->
        <div class="analysis-card" id="convictionCard">
          <div class="analysis-card__header">
            <div class="analysis-card__title"><span class="analysis-card__icon">🎯</span> Conviction Matrix</div>
          </div>
          <div class="conviction-matrix" id="convictionMatrix">
            <?php foreach (['TECHNICAL','VALUATION','QUALITY','NEWS','MOMENTUM'] as $dim): ?>
            <div class="conviction-dim">
              <div class="conviction-dim__header">
                <span class="conviction-dim__label"><?= $dim ?></span>
                <span class="conviction-dim__score" id="conv-<?= strtolower($dim) ?>">—</span>
              </div>
              <div class="conviction-bar">
                <div class="conviction-bar__fill" id="conv-bar-<?= strtolower($dim) ?>" style="width:0%"></div>
              </div>
              <div class="conviction-dim__reason" id="conv-reason-<?= strtolower($dim) ?>"></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

      </div><!-- /ai-row halves -->

      <!-- ROW 2: Trade Setup + Scenario Analysis -->
      <div class="ai-row ai-row--halves">

        <!-- Trade Setup card -->
        <div class="analysis-card trade-setup-card" id="tradeSetupCard">
          <div class="analysis-card__header">
            <div class="analysis-card__title"><span class="analysis-card__icon">📐</span> Trade Setup</div>
            <span class="ts-rr-badge" id="tsRRBadge" style="display:none;"></span>
          </div>
          <div id="tradeSetupBody">
            <div class="skeleton skeleton-text"></div>
            <div class="skeleton skeleton-text"></div>
          </div>
        </div>

        <!-- Scenario Analysis card -->
        <div class="analysis-card" id="scenarioCard">
          <div class="analysis-card__header">
            <div class="analysis-card__title"><span class="analysis-card__icon">🔭</span> Scenario Analysis</div>
          </div>
          <div class="scenarios-grid" id="scenariosGrid">
            <div class="skeleton skeleton-text"></div>
          </div>
        </div>

      </div><!-- /ai-row halves -->

      <!-- ROW 3: Support & Resistance + Verdict Change Tracker -->
      <div class="ai-row ai-row--halves">

        <!-- Support & Resistance card -->
        <div class="analysis-card" id="srCard">
          <div class="analysis-card__header">
            <div class="analysis-card__title"><span class="analysis-card__icon">📏</span> Support &amp; Resistance</div>
          </div>
          <div id="srLevelsBody">
            <div class="skeleton skeleton-text"></div>
          </div>
        </div>

        <!-- Verdict Change Tracker card -->
        <div class="analysis-card" id="verdictHistoryCard">
          <div class="analysis-card__header">
            <div class="analysis-card__title"><span class="analysis-card__icon">📅</span> Verdict History</div>
          </div>
          <div class="verdict-timeline" id="verdictTimeline">
            <div class="skeleton skeleton-text"></div>
          </div>
        </div>

      </div><!-- /ai-row halves -->

      <!-- ROW 4: News Sentiment Pulse (full width) -->
      <div class="analysis-card" id="newsSentimentCard">
        <div class="analysis-card__header">
          <div class="analysis-card__title"><span class="analysis-card__icon">📡</span> News Sentiment Pulse</div>
          <div class="sentiment-overall" id="sentimentOverall" style="display:none;">
            <span class="sentiment-overall__label" id="sentimentLabel">—</span>
            <span class="sentiment-overall__score" id="sentimentScore">—</span>
          </div>
        </div>
        <div class="sentiment-gauge-wrap" id="sentimentGaugeWrap" style="display:none;">
          <div class="sentiment-gauge">
            <div class="sentiment-gauge__track">
              <div class="sentiment-gauge__fill" id="sentimentGaugeFill" style="width:50%"></div>
            </div>
            <div class="sentiment-gauge__labels">
              <span>Bearish</span><span>Neutral</span><span>Bullish</span>
            </div>
          </div>
        </div>
        <div class="sentiment-list" id="sentimentList">
          <div class="skeleton skeleton-text"></div>
        </div>
      </div>

      <!-- ROW 5: Standard analysis sections (full width each) -->
      <?php
      $sections = [
        ['key'=>'price_and_technical_setup', 'title'=>'Price & Technical Setup',  'icon'=>'📊'],
        ['key'=>'support_and_resistance',    'title'=>'Support & Resistance (AI)', 'icon'=>'📏'],
        ['key'=>'valuation',                 'title'=>'Valuation',                'icon'=>'💰'],
        ['key'=>'business_quality',          'title'=>'Business Quality',         'icon'=>'🏭'],
        ['key'=>'balance_sheet_health',      'title'=>'Balance Sheet Health',     'icon'=>'🏦'],
        ['key'=>'cash_flow_quality',         'title'=>'Cash Flow Quality',        'icon'=>'💵'],
        ['key'=>'catalysts_and_risks',       'title'=>'Catalysts & Risks',        'icon'=>'⚡'],
      ];
      foreach ($sections as $s): ?>
      <div class="analysis-card analysis-card--primary" id="section-<?= $s['key'] ?>">
        <div class="analysis-card__header">
          <div class="analysis-card__title">
            <span class="analysis-card__icon"><?= $s['icon'] ?></span>
            <?= $s['title'] ?>
          </div>
        </div>
        <div class="analysis-card__body">
          <div class="skeleton skeleton-text"></div>
          <div class="skeleton skeleton-text"></div>
          <div class="skeleton skeleton-text"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Tab: Charts -->
    <div class="tab-panel" id="tab-charts">
      <div class="chart-grid">
        <div class="chart-card">
          <div class="chart-card__header">
            <div class="chart-card__title">Price vs DMA</div>
            <div class="chart-timerange" id="priceRange">
              <button onclick="filterChart('price',30,this)">1M</button>
              <button onclick="filterChart('price',90,this)">3M</button>
              <button onclick="filterChart('price',180,this)">6M</button>
              <button class="active" onclick="filterChart('price',365,this)">1Y</button>
            </div>
          </div>
          <div class="chart-canvas-wrap"><canvas id="chart-price"></canvas></div>
        </div>
        <div class="chart-card">
          <div class="chart-card__header">
            <div class="chart-card__title">PE vs Median PE</div>
          </div>
          <div class="chart-canvas-wrap"><canvas id="chart-pe"></canvas></div>
        </div>
        <div class="chart-card">
          <div class="chart-card__header">
            <div class="chart-card__title">Margin Trends</div>
            <div class="chart-timerange">
              <button onclick="filterChart('margins',365,this)">1Y</button>
              <button onclick="filterChart('margins',1095,this)">3Y</button>
              <button class="active" onclick="filterChart('margins',1825,this)">5Y</button>
            </div>
          </div>
          <div class="chart-canvas-wrap"><canvas id="chart-margins"></canvas></div>
        </div>
        <div class="chart-card">
          <div class="chart-card__header">
            <div class="chart-card__title">Cash Flow</div>
          </div>
          <div class="chart-canvas-wrap"><canvas id="chart-cashflow"></canvas></div>
        </div>
      </div>
    </div>

    <!-- Tab: Peers -->
    <div class="tab-panel" id="tab-peers">
      <div class="card">
        <div class="table-wrap">
          <table class="data-table" id="peersTable">
            <thead id="peersHead"></thead>
            <tbody id="peersBody">
              <tr><td colspan="8" style="text-align:center;color:var(--c-text3);padding:24px;">Loading peers…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Tab: News -->
    <div class="tab-panel" id="tab-news">
      <div class="card">
        <div class="news-list" id="newsList">
          <div style="text-align:center;padding:24px;color:var(--c-text3);">Loading news…</div>
        </div>
      </div>
    </div>

  </div><!-- /mainContent -->
</div><!-- /analysisApp -->
