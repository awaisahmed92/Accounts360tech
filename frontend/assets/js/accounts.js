/**
 * Accounts360tech — Chart of Accounts Module
 */

const Accounts = (() => {
  const TYPES = ['Asset', 'Liability', 'Equity', 'Income', 'Expense'];
  const TYPE_COLORS = { Asset:'#3b82f6', Liability:'#f59e0b', Equity:'#8b5cf6', Income:'#16a34a', Expense:'#dc2626' };

  function init() {
    document.getElementById('acct-type-filter').addEventListener('change', load);
    document.getElementById('btn-new-account').addEventListener('click', () => {
      document.getElementById('new-account-form').classList.toggle('hidden');
    });
    document.getElementById('btn-cancel-account').addEventListener('click', () => {
      document.getElementById('new-account-form').classList.add('hidden');
      clearForm();
    });
    document.getElementById('btn-save-account').addEventListener('click', saveAccount);
  }

  async function load() {
    const type   = document.getElementById('acct-type-filter').value;
    const params = type ? { type } : {};
    const res    = await API.listAccounts(params);
    if (!res?.success) { Toast.show('Failed to load accounts.', 'error'); return; }
    renderAccounts(res.data.accounts);
  }

  function renderAccounts(accounts) {
    const container = document.getElementById('accounts-container');
    if (!accounts.length) {
      container.innerHTML = '<div class="empty-state">No accounts found.</div>';
      return;
    }

    // Group by type
    const groups = {};
    TYPES.forEach(t => groups[t] = []);
    accounts.forEach(a => { if (groups[a.type]) groups[a.type].push(a); });

    container.innerHTML = TYPES.filter(t => groups[t].length > 0).map(type => `
      <div class="bk-card" style="margin-bottom:16px">
        <div class="bk-card-title" style="color:${TYPE_COLORS[type]}">
          ${type}s
          <span style="color:var(--c-text-3);font-weight:400;font-size:12px;margin-left:8px">${groups[type].length} accounts</span>
        </div>
        <table class="data-table">
          <thead><tr><th style="width:100px">Code</th><th>Name</th><th>Sub-type</th><th>System</th><th class="col-actions">Actions</th></tr></thead>
          <tbody>
            ${groups[type].map(a => `
              <tr>
                <td><span class="acct-code">${esc(a.code)}</span></td>
                <td style="font-weight:500">${esc(a.name)}</td>
                <td style="color:var(--c-text-2)">${esc(a.sub_type || '—')}</td>
                <td>${a.is_system ? '<span class="file-status-badge status-processing">System</span>' : ''}</td>
                <td class="col-actions">
                  ${!a.is_system
                    ? `<button class="btn btn-ghost btn-sm" onclick="Accounts.deleteAccount(${a.id})">Deactivate</button>`
                    : '<span style="color:var(--c-text-3);font-size:12px">Protected</span>'
                  }
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    `).join('');
  }

  async function saveAccount() {
    const code    = document.getElementById('na-code').value.trim();
    const name    = document.getElementById('na-name').value.trim();
    const type    = document.getElementById('na-type').value;
    const subtype = document.getElementById('na-subtype').value.trim();
    if (!code || !name) { Toast.show('Code and name are required.', 'error'); return; }

    const res = await API.createAccount({ code, name, type, sub_type: subtype || null });
    if (!res?.success) { Toast.show(res?.error?.message || 'Failed to create account.', 'error'); return; }
    Toast.show('Account created.', 'success');
    document.getElementById('new-account-form').classList.add('hidden');
    clearForm();
    load();
  }

  async function deleteAccount(id) {
    if (!confirm('Deactivate this account?')) return;
    const res = await API.deleteAccount(id);
    if (res?.success) { Toast.show('Account deactivated.', 'success'); load(); }
    else Toast.show(res?.error?.message || 'Cannot deactivate account.', 'error');
  }

  function clearForm() {
    ['na-code','na-name','na-subtype'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('na-type').value = 'Expense';
  }

  function esc(s) { return String(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

  return { init, load, deleteAccount };
})();
