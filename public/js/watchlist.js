// watchlist.js
async function removeFromWatchlist(companyId, btn) {
  if (!confirm('Remove from watchlist?')) return;
  try {
    const body = new URLSearchParams({ action: 'remove', company_id: companyId });
    const res  = await fetch((window.APP_BASE || '') + '/api/watchlist', { method: 'POST', body });
    const json = await res.json();
    if (json.ok) {
      const row = document.getElementById('wl-row-' + companyId);
      row?.remove();
      showToast('Removed from watchlist', 'success');
    }
  } catch {
    showToast('Failed to remove', 'error');
  }
}
