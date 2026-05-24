# StockLens AI — NSE Stock Analysis Platform

AI-powered equity analysis for any NSE-listed stock. Pulls live price data from NSE India and 5 years of financials from Screener.in, then generates a structured Buy/Hold/Avoid analysis with confidence score via OpenRouter AI.

---

## What it does

- **Live price + technicals** — NSE India (price, VWAP, 52W high/low, circuit limits)
- **Fundamentals** — Revenue/profit CAGR, margins, FCF, debt trends from Screener.in
- **Valuation** — P/E vs median, EV/EBITDA, PBV, MCap/Sales (all with historical context)
- **AI Analysis** — 8-section report: Technical → Valuation → Business Quality → Balance Sheet → Cash Flow → Catalysts & Risks → Confidence Score → Verdict
- **Charts** — Price vs DMA, PE vs Median PE, Margin Trends, Cash Flow waterfall
- **Watchlist** — Track stocks, remove with one click
- **History** — All past analyses with filter by verdict
- **Compare** — Side-by-side comparison of 2–3 stocks

---

## Tech Stack

| Layer       | Technology                          |
|-------------|-------------------------------------|
| Backend     | PHP 8.2+                            |
| Database    | MySQL 8.0+                          |
| AI          | OpenRouter (any model)              |
| Data        | NSE India API + Screener.in API     |
| Frontend    | Vanilla JS + Chart.js 4.x           |
| CSS         | Custom (no framework) — dark theme  |
| Web server  | Apache (with mod_rewrite) or Nginx  |

---

## Quick Setup

### 1. Requirements

- PHP 8.2+ with extensions: `pdo_mysql`, `curl`, `json`, `simplexml`
- MySQL 8.0+
- Apache with `mod_rewrite` enabled (or Nginx with rewrite equivalent)
- OpenRouter API key → https://openrouter.ai/keys

### 2. Clone / copy files

```bash
# Copy the ai-stock-platform/ folder to your web root
cp -r ai-stock-platform/ /var/www/html/stocklens/
cd /var/www/html/stocklens/
```

### 3. Create database

```bash
mysql -u root -p -e "CREATE DATABASE ai_stock_platform CHARACTER SET utf8mb4;"
mysql -u root -p ai_stock_platform < database/schema.sql
mysql -u root -p ai_stock_platform < database/seed.sql   # optional — seeds 10 popular NSE stocks
```

### 4. Configure environment

```bash
cp .env.example .env
nano .env
```

Fill in:
```
DB_HOST=localhost
DB_PORT=3306
DB_NAME=ai_stock_platform
DB_USER=your_db_user
DB_PASS=your_db_password

OPENROUTER_API_KEY=sk-or-v1-xxxxxxxxxxxxxxxx
OPENROUTER_MODEL=anthropic/claude-sonnet-4-5

APP_URL=http://your-domain-or-localhost
APP_ENV=production
APP_DEBUG=false
```

### 5. Apache config

Enable `mod_rewrite` and set `AllowOverride All` for the directory. Example VirtualHost:

```apache
<VirtualHost *:80>
    ServerName stocklens.local
    DocumentRoot /var/www/html/stocklens

    <Directory /var/www/html/stocklens>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### 6. Nginx alternative

```nginx
server {
    listen 80;
    server_name stocklens.local;
    root /var/www/html/stocklens;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?route=$uri&$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 7. Permissions

```bash
mkdir -p logs
chmod 755 logs
touch logs/app.log
chmod 644 logs/app.log
```

### 8. Set up cron jobs (optional but recommended)

```bash
crontab -e
```

Add:
```cron
# Refresh watchlist data every 30 minutes
*/30 * * * * php /var/www/html/stocklens/cron/refresh-watchlist.php >> /var/www/html/stocklens/logs/cron.log 2>&1

# Clean old cache daily at midnight
0 0 * * * php /var/www/html/stocklens/cron/cleanup-cache.php >> /var/www/html/stocklens/logs/cron.log 2>&1
```

---

## Choosing an AI Model

Edit `OPENROUTER_MODEL` in `.env`. Recommended options (all work with OpenRouter):

| Model                              | Quality  | Speed  | Cost     |
|------------------------------------|----------|--------|----------|
| `anthropic/claude-sonnet-4-5`      | ⭐⭐⭐⭐⭐ | Medium | Medium   |
| `anthropic/claude-haiku-4-5`       | ⭐⭐⭐⭐   | Fast   | Low      |
| `openai/gpt-4o`                    | ⭐⭐⭐⭐⭐ | Medium | Medium   |
| `openai/gpt-4o-mini`               | ⭐⭐⭐⭐   | Fast   | Very Low |
| `google/gemini-pro-1.5`            | ⭐⭐⭐⭐   | Fast   | Low      |
| `meta-llama/llama-3.1-70b-instruct`| ⭐⭐⭐    | Fast   | Very Low |
| `deepseek/deepseek-r1`             | ⭐⭐⭐⭐   | Medium | Low      |

---

## File Structure

```
ai-stock-platform/
├── .env                        ← Your config (never commit)
├── .env.example                ← Config template
├── .htaccess                   ← Apache rewrite rules
├── index.php                   ← Front controller + router
│
├── config/
│   ├── env.php                 ← .env loader
│   ├── constants.php           ← API URLs, defaults
│   └── database.php            ← PDO singleton
│
├── src/
│   ├── helpers.php             ← logError(), formatCr(), etc.
│   ├── Api/
│   │   ├── NseApi.php          ← NSE India live data
│   │   ├── ScreenerApi.php     ← Screener.in financials + charts
│   │   ├── NewsApi.php         ← Google News RSS
│   │   └── ParallelFetcher.php ← curl_multi_exec wrapper
│   ├── Data/
│   │   ├── DataAggregator.php  ← Orchestrates all API calls + cache
│   │   ├── MetricsComputer.php ← RSI, CAGR, FCF, DMA calculations
│   │   └── PromptBuilder.php   ← Assembles AI prompt
│   ├── Ai/
│   │   └── OpenRouterClient.php← OpenRouter API call + section parser
│   ├── Cache/
│   │   └── MySqlCache.php      ← TTL-based MySQL cache layer
│   └── Models/
│       ├── Company.php
│       ├── Analysis.php
│       └── Watchlist.php
│
├── pages/
│   ├── home.php                ← Landing + search
│   ├── analysis.php            ← Main analysis view
│   ├── watchlist.php           ← Watchlist manager
│   ├── history.php             ← Analysis history
│   ├── compare.php             ← Side-by-side comparison
│   └── 404.php
│
├── templates/
│   └── layout.php              ← HTML shell (navbar, footer)
│
├── api/
│   ├── search.php              ← /api/search?q=
│   ├── analyse.php             ← /api/analyse?symbol=
│   ├── prices.php              ← /api/prices?symbols=
│   └── watchlist.php           ← /api/watchlist (POST)
│
├── public/
│   ├── css/main.css            ← Full design system (dark theme)
│   └── js/
│       ├── app.js              ← Shared: autocomplete, toasts, tabs
│       ├── analysis.js         ← Analysis page controller
│       ├── charts.js           ← Chart.js 4 implementations
│       ├── search.js           ← Home search
│       ├── watchlist.js        ← Watchlist page actions
│       └── compare.js          ← Compare page
│
├── cron/
│   ├── refresh-watchlist.php   ← Runs every 30 min
│   └── cleanup-cache.php       ← Runs daily
│
├── database/
│   ├── schema.sql              ← All CREATE TABLE statements
│   └── seed.sql                ← 10 popular NSE stocks
│
└── logs/
    └── app.log                 ← Application errors
```

---

## How the analysis pipeline works

```
User requests /analysis/RELIANCE
        ↓
index.php → pages/analysis.php (HTML shell rendered)
        ↓
JS calls /api/analyse?symbol=RELIANCE
        ↓
api/analyse.php:
  1. Screener search → get screener_id
  2. Check analysis_history cache (6hr TTL)
  3. If fresh: fetch NSE quote + Screener charts/schedules + news (parallel)
  4. MetricsComputer: RSI-14, CAGR, FCF, DMA, PE vs median
  5. PromptBuilder: assemble 800-word structured prompt
  6. OpenRouterClient: send to AI, parse 8 sections
  7. Save to analysis_history
  8. Return JSON
        ↓
JS: renderHeader() + renderSections() + initCharts() + renderNews()
```

---

## Common Issues

**NSE returns 401/403**
NSE blocks scrapers intermittently. The app automatically retries with a fresh cookie session. If it keeps failing, wait 15 minutes — NSE sometimes rate-limits by IP.

**Screener API returns null**
Some smaller stocks may not have 5-year data. The app handles this gracefully — missing sections show "N/A" and the AI is told to note data gaps.

**AI returns no sections**
Check your OpenRouter API key and credit balance at https://openrouter.ai/activity. Make sure `OPENROUTER_MODEL` is a valid model slug.

**Charts don't render**
Chart.js is loaded from CDN. Ensure the user's browser can reach `cdn.jsdelivr.net`. All 4 charts need the `chart_data` to be non-null.

---

## Security notes

- Never commit `.env` — it contains your API key and DB password
- In production set `APP_DEBUG=false` — this hides raw error messages from users
- Logs are written to `logs/app.log` — rotate with logrotate in production
- All user input (stock symbols) is sanitised via `validateSymbol()` before use

---

## License

MIT — use freely, modify as needed.
