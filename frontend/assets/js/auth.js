/**
 * Accounts360tech — Auth Module
 * Handles login, register, forgot password screens.
 */

const Auth = (() => {

  function show() {
    document.getElementById('screen-auth').classList.remove('hidden');
    document.getElementById('screen-app').classList.add('hidden');
    showPanel('login');
  }

  function hide() {
    document.getElementById('screen-auth').classList.add('hidden');
    document.getElementById('screen-app').classList.remove('hidden');
  }

  function showPanel(name) {
    ['login','register','forgot'].forEach(p => {
      document.getElementById(`form-${p}`).classList.toggle('hidden', p !== name);
    });
    clearErrors();
  }

  function clearErrors() {
    ['auth-error','reg-error','forgot-msg'].forEach(id => {
      const el = document.getElementById(id);
      if (el) { el.textContent = ''; el.className = 'hidden'; }
    });
  }

  function setError(id, msg) {
    const el = document.getElementById(id);
    el.textContent = msg;
    el.className = 'alert alert-error';
  }

  function setLoading(btnId, loading) {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    btn.disabled = loading;
    btn.textContent = loading ? 'Please wait…' : btn.dataset.label || btn.textContent;
  }

  function init() {
    // Panel switches
    document.getElementById('link-register')   ?.addEventListener('click', e => { e.preventDefault(); showPanel('register'); });
    document.getElementById('link-login')       ?.addEventListener('click', e => { e.preventDefault(); showPanel('login'); });
    document.getElementById('link-forgot')      ?.addEventListener('click', e => { e.preventDefault(); showPanel('forgot'); });
    document.getElementById('link-back-login')  ?.addEventListener('click', e => { e.preventDefault(); showPanel('login'); });

    // Login
    document.getElementById('login-form').addEventListener('submit', async e => {
      e.preventDefault();
      clearErrors();
      const email    = document.getElementById('login-email').value.trim();
      const password = document.getElementById('login-password').value;
      if (!email || !password) { setError('auth-error', 'Please enter your email and password.'); return; }
      setLoading('btn-login', true);
      const res = await API.login(email, password);
      setLoading('btn-login', false);
      if (!res?.success) {
        setError('auth-error', res?.error?.message || 'Login failed. Please try again.');
        return;
      }
      API.setTokens(res.data.token, res.data.refresh_token);
      localStorage.setItem('user', JSON.stringify(res.data.user));
      App.boot(res.data.user);
    });

    // Register
    document.getElementById('register-form').addEventListener('submit', async e => {
      e.preventDefault();
      clearErrors();
      const name     = document.getElementById('reg-name').value.trim();
      const email    = document.getElementById('reg-email').value.trim();
      const password = document.getElementById('reg-password').value;
      if (!name || !email || !password) { setError('reg-error', 'All fields are required.'); return; }
      setLoading('btn-register', true);
      const res = await API.register(name, email, password);
      setLoading('btn-register', false);
      if (!res?.success) {
        const msg = res?.error?.fields ? Object.values(res.error.fields).join(' ') : res?.error?.message || 'Registration failed.';
        setError('reg-error', msg);
        return;
      }
      // Auto-login after registration
      const loginRes = await API.login(email, password);
      if (loginRes?.success) {
        API.setTokens(loginRes.data.token, loginRes.data.refresh_token);
        localStorage.setItem('user', JSON.stringify(loginRes.data.user));
        App.boot(loginRes.data.user);
      } else {
        showPanel('login');
        Toast.show('Account created! Please sign in.', 'success');
      }
    });

    // Forgot password
    document.getElementById('forgot-form').addEventListener('submit', async e => {
      e.preventDefault();
      const email = document.getElementById('forgot-email').value.trim();
      if (!email) return;
      const res = await API.forgotPassword(email);
      const msgEl = document.getElementById('forgot-msg');
      msgEl.className = 'alert alert-info';
      msgEl.textContent = res?.data?.message || 'If that email exists, a reset link has been sent.';
    });
  }

  return { show, hide, init };
})();
