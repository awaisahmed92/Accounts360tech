/**
 * Accounts360tech — Main App Bootstrap & Router
 */

const Toast = (() => {
  let timer;
  function show(msg, type = 'info') {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className = `toast toast-${type}`;
    clearTimeout(timer);
    timer = setTimeout(() => { el.className = 'toast hidden'; }, 3500);
  }
  return { show };
})();

const App = (() => {
  let currentView = null;
  let currentUser = null;

  function boot(user) {
    currentUser = user;
    localStorage.setItem('user', JSON.stringify(user));

    // Update sidebar
    document.getElementById('sidebar-user-name').textContent   = user.name;
    document.getElementById('sidebar-user-role').textContent   = user.role;
    document.getElementById('user-avatar-letter').textContent  = user.name.charAt(0).toUpperCase();

    // Show admin nav for admins
    document.querySelectorAll('.nav-item--admin').forEach(el => {
      el.classList.toggle('hidden', user.role !== 'admin');
    });

    Auth.hide();
    switchView('upload');
    Upload.loadRecent();
  }

  function logout() {
    const rt = API.getRefreshToken();
    API.logout(rt).finally(() => {
      API.clearTokens();
      localStorage.removeItem('user');
      currentUser = null;
      currentView = null;
      Auth.show();
    });
  }

  function switchView(name) {
    document.querySelectorAll('.view').forEach(v => v.classList.add('hidden'));
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));

    const view    = document.getElementById(`view-${name}`);
    const navItem = document.querySelector(`.nav-item[data-view="${name}"]`);
    if (view)    view.classList.remove('hidden');
    if (navItem) navItem.classList.add('active');
    currentView = name;

    switch (name) {
      case 'upload':   Upload.loadRecent();    break;
      case 'review':   Review.load();          break;
      case 'ledger':   Ledger.load();          break;
      case 'admin':    AdminPanel.load();      break;
      case 'accounts': Accounts.load();        break;
      case 'journal':  Journal.load();         break;
      case 'bank':     BankModule.load();      break;
      case 'reports':  Reports.load();         break;
    }
  }

  function init() {
    // Init sub-modules
    Auth.init();
    Upload.init();
    Review.init();
    Ledger.init();
    AdminPanel.init();
    Accounts.init();
    Journal.init();
    BankModule.init();
    Reports.init();

    // Nav links
    document.querySelectorAll('.nav-item[data-view]').forEach(link => {
      link.addEventListener('click', e => {
        e.preventDefault();
        switchView(link.dataset.view);
      });
    });

    // Inline view switch links (e.g. "View review queue →")
    document.querySelectorAll('a[data-view]').forEach(link => {
      link.addEventListener('click', e => {
        e.preventDefault();
        switchView(link.dataset.view);
      });
    });

    // Logout
    document.getElementById('btn-logout').addEventListener('click', logout);

    // Check for existing session
    const savedUser  = localStorage.getItem('user');
    const savedToken = API.getToken();

    if (savedUser && savedToken) {
      try {
        const user = JSON.parse(savedUser);
        boot(user);
      } catch {
        Auth.show();
      }
    } else {
      Auth.show();
    }
  }

  return { boot, logout, switchView, init };
})();

// Start
document.addEventListener('DOMContentLoaded', () => App.init());
