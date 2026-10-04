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
    if (name === 'register') loadCaptcha();
  }

  async function loadCaptcha() {
    const image = document.getElementById('reg-captcha-image');
    const token = document.getElementById('reg-captcha-token');
    const answer = document.getElementById('reg-captcha');
    if (!image || !token) return;
    const res = await API.signupCaptcha();
    if (!res?.success || !res.data?.image || !res.data?.token) {
      setError('reg-error', 'Could not load the verification code.');
      return;
    }
    image.src = res.data.image;
    token.value = res.data.token;
    if (answer) answer.value = '';
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
        const companyName = document.getElementById('reg-company-name')?.value.trim() || '';
        const res = await API.checkSubdomain(cleaned, companyName);
        if (!res?.success) {
          hintEl.textContent = 'Could not check right now. Final submit will re-check.';
          return;
        }
        if (res.data.available && res.data.existing_client) {
          hintEl.textContent = res.data.message || 'This company is already on 360tech. Accounts will open on the same record.';
          hintEl.style.color = '#2e9e5b';
        } else if (res.data.available) {
          hintEl.textContent = 'Available - this will be your company code.';
          hintEl.style.color = '#2e9e5b';
        } else {
          hintEl.textContent = res.data.message || 'Already taken. Try another company code.';
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

  const countryDial = {
    Pakistan: 'PK',
    'United Arab Emirates': 'AE',
    'Saudi Arabia': 'SA',
    Qatar: 'QA',
    Oman: 'OM',
    Kuwait: 'KW',
    Bahrain: 'BH',
    'United Kingdom': 'GB',
    'United States': 'US',
    Canada: 'CA',
    Australia: 'AU'
  };

  const phoneCountries = [
    ['AE', 'United Arab Emirates', '+971'], ['AF', 'Afghanistan', '+93'], ['AR', 'Argentina', '+54'],
    ['AT', 'Austria', '+43'], ['AU', 'Australia', '+61'], ['BD', 'Bangladesh', '+880'],
    ['BE', 'Belgium', '+32'], ['BH', 'Bahrain', '+973'], ['BR', 'Brazil', '+55'],
    ['CA', 'Canada', '+1'], ['CH', 'Switzerland', '+41'], ['CN', 'China', '+86'],
    ['DE', 'Germany', '+49'], ['DK', 'Denmark', '+45'], ['EG', 'Egypt', '+20'],
    ['ES', 'Spain', '+34'], ['FR', 'France', '+33'], ['GB', 'United Kingdom', '+44'],
    ['GR', 'Greece', '+30'], ['HK', 'Hong Kong', '+852'], ['ID', 'Indonesia', '+62'],
    ['IE', 'Ireland', '+353'], ['IN', 'India', '+91'], ['IT', 'Italy', '+39'],
    ['JP', 'Japan', '+81'], ['KE', 'Kenya', '+254'], ['KR', 'South Korea', '+82'],
    ['KW', 'Kuwait', '+965'], ['LK', 'Sri Lanka', '+94'], ['MY', 'Malaysia', '+60'],
    ['MX', 'Mexico', '+52'], ['NG', 'Nigeria', '+234'], ['NL', 'Netherlands', '+31'],
    ['NO', 'Norway', '+47'], ['NP', 'Nepal', '+977'], ['NZ', 'New Zealand', '+64'],
    ['OM', 'Oman', '+968'], ['PH', 'Philippines', '+63'], ['PK', 'Pakistan', '+92'],
    ['PL', 'Poland', '+48'], ['QA', 'Qatar', '+974'], ['SA', 'Saudi Arabia', '+966'],
    ['SE', 'Sweden', '+46'], ['SG', 'Singapore', '+65'], ['TH', 'Thailand', '+66'],
    ['TR', 'Turkey', '+90'], ['US', 'United States', '+1'], ['ZA', 'South Africa', '+27']
  ];

  function setPhoneCountry(iso) {
    const row = phoneCountries.find(country => country[0] === iso) || phoneCountries.find(country => country[0] === 'US');
    const hidden = document.getElementById('reg-phone-code');
    const flag = document.getElementById('reg-phone-flag');
    const dial = document.getElementById('reg-phone-dial');
    if (!hidden || !flag || !dial || !row) return;
    hidden.value = row[0];
    hidden.dataset.dial = row[2];
    flag.src = `assets/flags/${row[0].toLowerCase()}.svg`;
    flag.alt = row[1];
    dial.textContent = row[2];
  }

  function wireFlagPicker() {
    const button = document.getElementById('reg-phone-code-btn');
    const menu = document.getElementById('reg-phone-menu');
    if (!button || !menu) return;
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search country';
    search.className = 'flag-picker-search';
    search.setAttribute('aria-label', 'Search country');
    const list = document.createElement('div');
    menu.appendChild(search);
    menu.appendChild(list);

    function render(query) {
      const needle = query.trim().toLowerCase();
      list.replaceChildren();
      phoneCountries
        .filter(country => !needle || country[1].toLowerCase().includes(needle) || country[2].includes(needle) || country[0].toLowerCase().includes(needle))
        .sort((a, b) => a[1].localeCompare(b[1]))
        .forEach(country => {
          const item = document.createElement('button');
          item.type = 'button';
          item.className = 'flag-picker-item';
          item.setAttribute('role', 'option');
          if (document.getElementById('reg-phone-code')?.value === country[0]) item.setAttribute('aria-selected', 'true');
          const image = document.createElement('img');
          image.src = `assets/flags/${country[0].toLowerCase()}.svg`;
          image.alt = '';
          image.width = 22;
          image.height = 16;
          const name = document.createElement('span');
          name.textContent = country[1];
          const dial = document.createElement('strong');
          dial.textContent = country[2];
          item.append(image, name, dial);
          item.addEventListener('click', () => {
            setPhoneCountry(country[0]);
            menu.classList.add('hidden');
            button.setAttribute('aria-expanded', 'false');
          });
          list.appendChild(item);
        });
    }

    render('');
    search.addEventListener('input', () => render(search.value));
    button.addEventListener('click', event => {
      event.stopPropagation();
      const open = menu.classList.toggle('hidden') === false;
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) {
        search.value = '';
        render('');
        search.focus();
      }
    });
    menu.addEventListener('click', event => event.stopPropagation());
    document.addEventListener('click', () => {
      menu.classList.add('hidden');
      button.setAttribute('aria-expanded', 'false');
    });
  }

  function wirePhoneCountry() {
    const country = document.getElementById('reg-country');
    if (!country) return;
    country.addEventListener('change', () => {
      const next = countryDial[country.value];
      if (next) setPhoneCountry(next);
    });
  }

  function composePhone() {
    const code = document.getElementById('reg-phone-code');
    const input = document.getElementById('reg-phone');
    if (!code || !input) return '';
    const dial = code.dataset.dial || '';
    let digits = (input.value || '').replace(/\D/g, '');
    if (!digits) return '';
    const codeDigits = dial.replace(/\D/g, '');
    if (codeDigits && digits.startsWith(codeDigits)) digits = digits.slice(codeDigits.length);
    digits = digits.replace(/^0+/, '');
    return digits ? `${dial} ${digits}` : '';
  }

  function init() {
    wireCompanyCodeHelper();
    wireFlagPicker();
    wirePhoneCountry();
    document.getElementById('reg-captcha-refresh')?.addEventListener('click', () => { loadCaptcha(); });

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
      const phone       = composePhone();
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
      const captchaToken = document.getElementById('reg-captcha-token')?.value || '';
      const captchaAnswer = document.getElementById('reg-captcha')?.value.trim() || '';
      if (!captchaToken || captchaAnswer.length < 4) {
        setError('reg-error', 'Type the characters shown in the verification image.');
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
        phone,
        captcha_token: captchaToken,
        captcha_answer: captchaAnswer
      });
      setLoading('btn-register', false);
      if (!res?.success) {
        const msg = res?.error?.fields ? Object.values(res.error.fields).join(' ') : res?.error?.message || 'Registration failed.';
        setError('reg-error', msg);
        loadCaptcha();
        return;
      }
      API.setTenantSubdomain(res.data.tenant?.subdomain || companyCode);
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
