<?php
// Redirect if already logged in
if (Auth::check()) {
    header('Location: ' . APP_BASE . '/');
    exit;
}

$pageTitle  = 'Sign In';
$redirect   = htmlspecialchars($_GET['redirect'] ?? '/');
?>

<div class="auth-page mt-16">
  <div class="auth-card">
    <div class="auth-card__header">
      <div class="auth-card__icon">📈</div>
      <h1 class="auth-card__title">Sign in to StockLens</h1>
      <p class="auth-card__sub">Access your watchlist and analysis history</p>
    </div>

    <form id="loginForm" class="auth-form">
      <div class="form-group">
        <label class="form-label" for="email">Email</label>
        <input type="email" id="email" name="email" class="form-input" placeholder="you@example.com" required autocomplete="email">
      </div>
      <div class="form-group">
        <label class="form-label" for="password">Password</label>
        <input type="password" id="password" name="password" class="form-input" placeholder="••••••••" required autocomplete="current-password">
      </div>

      <div class="form-error" id="loginError" style="display:none;"></div>

      <button type="submit" class="btn btn--primary btn--full" id="loginBtn">Sign In</button>
    </form>

    <div class="auth-card__footer">
      Don't have an account? <a href="<?= APP_BASE ?>/register">Create one</a>
    </div>
  </div>
</div>

<script>
document.getElementById('loginForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const btn = document.getElementById('loginBtn');
  const err = document.getElementById('loginError');
  btn.disabled = true;
  btn.textContent = 'Signing in…';
  err.style.display = 'none';

  const fd = new FormData(this);
  fd.append('action', 'login');

  try {
    const res = await fetch(APP_BASE + '/api/auth', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      window.location.href = <?= json_encode($redirect) ?>;
    } else {
      err.textContent = data.error || 'Login failed';
      err.style.display = 'block';
      btn.disabled = false;
      btn.textContent = 'Sign In';
    }
  } catch {
    err.textContent = 'Network error. Please try again.';
    err.style.display = 'block';
    btn.disabled = false;
    btn.textContent = 'Sign In';
  }
});
</script>
