// search.js — home page search
document.addEventListener('DOMContentLoaded', () => {
  const heroInput = document.getElementById('heroSearch');
  const heroAC    = document.getElementById('heroAutocomplete');
  const heroBtn   = document.getElementById('heroSearchBtn');

  if (heroInput && heroAC) {
    createAutocomplete(heroInput, heroAC, item => {
      window.location.href = '/analysis/' + item.symbol;
    });

    heroBtn?.addEventListener('click', () => {
      const val = heroInput.value.trim().toUpperCase();
      if (val) window.location.href = '/analysis/' + val;
    });

    heroInput.addEventListener('keydown', e => {
      if (e.key === 'Enter') {
        const val = heroInput.value.trim().toUpperCase();
        if (val) window.location.href = '/analysis/' + val;
      }
    });
  }
});
