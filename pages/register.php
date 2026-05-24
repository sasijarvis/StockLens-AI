<?php
if (Auth::check()) {
    header('Location: ' . APP_BASE . '/');
    exit;
}

$pageTitle = 'Create Account';
?>

<div class="auth-page mt-16">
  <div class="auth-card">
    <div class="auth-card__header">
      <div class="auth-card__icon">📈</div>
      <h1 class="auth-card__title">Create your account</h1>
      <p class="auth-card__sub">Track your watchlist and analysis history</p>
    </div>

    <form id="registerForm" class="auth-form">
      <div class="form-group">
        <label class="form-label" for="name">Name <span style="color:var(--c-text3);font-size:0.8em;">(optional)</span></label>
        <input type="text" id="name" name="name" class="form-input" placeholder="Your name" autocomplete="name">
      </div>
      <div class="form-group">
        <label class="form-label" for="email">Email</label>
        <input type="email" id="email" name="email" class="form-input" placeholder="you@example.com" required autocomplete="email">
      </div>
      <div class="form-group">
        <label class="form-label" for="password">Password <span style="color:var(--c-text3);font-size:0.8em;">(min 8 characters)</span></label>
        <input type="password" id="password" name="password" class="form-input" placeholder="••••••••" required autocomplete="new-password" minlength="8">
      </div>

      <div class="form-error" id="registerError" style="display:none;"></div>

      <button type="submit" class="btn btn--primary btn--full" id="registerBtn">Create Account</button>
    </form>

    <div class="auth-card__footer">
      Already have an account? <a href="<?= APP_BASE ?>/login">Sign in</a>
    </div>
  </div>
</div>

<script>
document.getElementById('registerForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const btn = document.getElementById('registerBtn');
  const err = document.getElementById('registerError');
  btn.disabled = true;
  btn.textContent = 'Creating account…';
  err.style.display = 'none';

  const fd = new FormData(this);
  fd.append('action', 'register');

  try {
    const res = await fetch(APP_BASE + '/api/auth', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      window.location.href = APP_BASE + '/watchlist';
    } else {
      err.textContent = data.error || 'Registration failed';
      err.style.display = 'block';
      btn.disabled = false;
      btn.textContent = 'Create Account';
    }
  } catch {
    err.textContent = 'Network error. Please try again.';
    err.style.display = 'block';
    btn.disabled = false;
    btn.textContent = 'Create Account';
  }
});
</script>
