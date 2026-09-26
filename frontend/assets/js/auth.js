/**
 * Accounts360tech — Auth Module
 * Handles login, register, forgot password screens.
 */

const Auth = (() => {
  let codeDebounce = null;

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
    if (!btn.dataset.label) btn.dataset.label = btn.textContent;
    btn.disabled = loading;
    btn.textContent = loading ? 'Please wait…' : btn.dataset.label;
  }

  function slug(value) {
    return (value || '').toLowerCase().replace(/[^a-z0-9]+/g, '').slice(0, 40);
  }

  function wireCompanyCodeHelper() {
    const companyInput = document.getElementById('reg-company-name');
    const codeInput = document.getElementById('reg-company-code');
    const hintEl = document.getElementById('reg-code-hint');
    if (!companyInput || !codeInput || !hintEl) return;

    let codeTouched = false;
    codeInput.addEventListener('input', () => {
      codeTouched = true;
      const cleaned = slug(codeInput.value);
      if (cleaned !== codeInput.value) codeInput.value = cleaned;
      hintEl.textContent = 'Checking availability...';
      hintEl.style.color = '';
      if (codeDebounce) clearTimeout(codeDebounce);
      if (cleaned.length < 3) {
        hintEl.textContent = 'At least 3 lowercase letters or digits.';
        return;
      }
      codeDebounce = setTimeout(async () => {
        const res = await API.checkSubdomain(cleaned);
        if (!res?.success) {
          hintEl.textContent = 'Could not check right now. Final submit will re-check.';
          return;
        }
        if (res.data.available) {
          hintEl.textContent = 'Available - this will be your company code.';
          hintEl.style.color = '#2e9e5b';
        } else {
          hintEl.textContent = 'Already taken. Try another company code.';
          hintEl.style.color = '#c0392b';
        }
      }, 450);
    });

    companyInput.addEventListener('input', () => {
      if (codeTouched) return;
      const guessed = slug(companyInput.value);
      codeInput.value = guessed;
      codeInput.dispatchEvent(new Event('input'));
    });
  }

  function init() {
    wireCompanyCodeHelper();

    // Panel switches
    document.getElementById('link-register')   ?.addEventListener('click', e => { e.preventDefault(); showPanel('register'); });
    document.getElementById('link-login')       ?.addEventListener('click', e => { e.preventDefault(); showPanel('login'); });
    document.getElementById('link-forgot')      ?.addEventListener('click', e => { e.preventDefault(); showPanel('forgot'); });
    document.getElementById('link-back-login')  ?.addEventListener('click', e => { e.preventDefault(); showPanel('login'); });

    // Login
    document.getElementById('login-form').addEventListener('submit', async e => {
      e.preventDefault();
      clearErrors();
      const company  = document.getElementById('login-company').value.trim().toLowerCase();
      const userName = document.getElementById('login-username').value.trim();
      const password = document.getElementById('login-password').value;
      if (!company || !userName || !password) { setError('auth-error', 'Please enter company name, username, and password.'); return; }
      API.setTenantSubdomain(company);
      setLoading('btn-login', true);
      const res = await API.login(userName, password, company);
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
      const name        = document.getElementById('reg-name').value.trim();
      const companyName = document.getElementById('reg-company-name').value.trim();
      const companyCode = slug(document.getElementById('reg-company-code').value.trim());
      const designation = document.getElementById('reg-designation').value.trim();
      const industry    = document.getElementById('reg-industry').value.trim();
      const country     = document.getElementById('reg-country').value.trim();
      const email       = document.getElementById('reg-email').value.trim();
      const phone       = document.getElementById('reg-phone').value.trim();
      const password    = document.getElementById('reg-password').value;
      const confirm     = document.getElementById('reg-confirm-password').value;
      if (!name || !companyName || !companyCode || !designation || !industry || !country || !email || !password || !confirm) {
        setError('reg-error', 'All required fields (*) must be filled.');
        return;
      }
      if (companyCode.length < 3) {
        setError('reg-error', 'Company code must be at least 3 lowercase letters or digits.');
        return;
      }
      if (password !== confirm) {
        setError('reg-error', 'Passwords do not match.');
        return;
      }
      setLoading('btn-register', true);
      const res = await API.registerTenant({
        org_name: companyName,
        subdomain: companyCode,
        name,
        email,
        password,
        company_name: companyName,
        company_code: companyCode,
        designation,
        industry,
        country,
        phone
      });
      setLoading('btn-register', false);
      if (!res?.success) {
        const msg = res?.error?.fields ? Object.values(res.error.fields).join(' ') : res?.error?.message || 'Registration failed.';
        setError('reg-error', msg);
        return;
      }
      API.setTenantSubdomain(companyCode);
      API.setTokens(res.data.token, res.data.refresh_token || null);
      localStorage.setItem('user', JSON.stringify(res.data.user));
      App.boot(res.data.user);
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
