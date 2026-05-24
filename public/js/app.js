// ── StockLens AI — app.js (shared utilities) ──────────────────────

// ── Toast Notifications ──
function showToast(msg, type = 'info') {
  const container = document.getElementById('toastContainer');
  if (!container) return;
  const toast = document.createElement('div');
  const icons = { success: '✓', error: '✕', info: 'ℹ' };
  toast.className = `toast toast--${type}`;
  toast.innerHTML = `<span>${icons[type] || icons.info}</span><span>${msg}</span>`;
  container.appendChild(toast);
  setTimeout(() => toast.remove(), 3500);
}

// ── Autocomplete ──
function createAutocomplete(inputEl, dropdownEl, onSelect) {
  let debounceTimer = null;
  let selectedIndex = -1;
  let currentItems  = [];

  inputEl.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    const q = inputEl.value.trim();
    if (q.length < 1) { closeDropdown(); return; }
    debounceTimer = setTimeout(() => fetchSuggestions(q), 300);
  });

  inputEl.addEventListener('keydown', e => {
    if (!dropdownEl.classList.contains('open')) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); selectItem(selectedIndex + 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); selectItem(selectedIndex - 1); }
    else if (e.key === 'Enter') { e.preventDefault(); if (selectedIndex >= 0) confirmItem(selectedIndex); }
    else if (e.key === 'Escape') closeDropdown();
  });

  document.addEventListener('click', e => {
    if (!inputEl.contains(e.target) && !dropdownEl.contains(e.target)) closeDropdown();
  });

  async function fetchSuggestions(q) {
    try {
      const res  = await fetch((window.APP_BASE || '') + '/api/search?q=' + encodeURIComponent(q));
      const data = await res.json();
      currentItems = data;
      renderDropdown(data);
    } catch { closeDropdown(); }
  }

  function renderDropdown(items) {
    if (!items.length) { closeDropdown(); return; }
    dropdownEl.innerHTML = items.map((item, i) => `
      <div class="autocomplete-item" data-index="${i}">
        <span class="autocomplete-item__symbol">${item.symbol}</span>
        <span class="autocomplete-item__name">${item.name}</span>
        <span class="autocomplete-item__sector">${item.sector || ''}</span>
      </div>
    `).join('');
    dropdownEl.classList.add('open');
    selectedIndex = -1;
    dropdownEl.querySelectorAll('.autocomplete-item').forEach((el, i) => {
      el.addEventListener('mousedown', e => { e.preventDefault(); confirmItem(i); });
    });
  }

  function selectItem(idx) {
    const items = dropdownEl.querySelectorAll('.autocomplete-item');
    selectedIndex = Math.max(0, Math.min(idx, items.length - 1));
    items.forEach((el, i) => el.classList.toggle('selected', i === selectedIndex));
  }

  function confirmItem(idx) {
    const item = currentItems[idx];
    if (!item) return;
    inputEl.value = item.symbol;
    closeDropdown();
    onSelect(item);
  }

  function closeDropdown() {
    dropdownEl.classList.remove('open');
    dropdownEl.innerHTML = '';
    selectedIndex = -1;
  }
}

// ── Tabs ──
function switchTab(tabId, btn) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + tabId)?.classList.add('active');
  btn?.classList.add('active');
}

// ── Number Formatting ──
function fmtNum(n, decimals = 2) {
  if (n === null || n === undefined || n === 'N/A') return 'N/A';
  return Number(n).toLocaleString('en-IN', { maximumFractionDigits: decimals });
}

function fmtCr(n) {
  if (n === null || n === undefined) return 'N/A';
  const v = Number(n);
  if (Math.abs(v) >= 100000) return '₹' + (v / 100000).toFixed(1) + 'L Cr';
  return '₹' + fmtNum(v, 0) + ' Cr';
}

function fmtPct(n) {
  if (n === null || n === undefined) return 'N/A';
  const v = Number(n);
  return (v > 0 ? '+' : '') + v.toFixed(2) + '%';
}

function colourClass(n) {
  const v = Number(n);
  if (isNaN(v)) return '';
  return v > 0 ? 'num--up' : v < 0 ? 'num--down' : 'num--neutral';
}

// ── Navbar search init ──
document.addEventListener('DOMContentLoaded', () => {
  const navInput = document.getElementById('navSearch');
  const navAC    = document.getElementById('navAutocomplete');
  if (navInput && navAC) {
    createAutocomplete(navInput, navAC, item => {
      window.location.href = (window.APP_BASE || '') + '/analysis/' + item.symbol;
    });
  }
});
