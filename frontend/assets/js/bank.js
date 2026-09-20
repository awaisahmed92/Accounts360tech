/**
 * Accounts360tech — Bank Accounts & Reconciliation Module
 */

const BankModule = (() => {
  let currentBankId   = null;
  let currentBankName = '';
  let txnPage         = 1;

  function init() {
    document.getElementById('btn-new-bank').addEventListener('click', () => {
      document.getElementById('new-bank-form').classList.toggle('hidden');
    });
    document.getElementById('btn-cancel-bank').addEventListener('click', () => {
      document.getElementById('new-bank-form').classList.add('hidden');
      clearBankForm();
    });
    document.getElementById('btn-save-bank').addEventListener('click', saveBankAccount);
    document.getElementById('btn-back-bank').addEventListener('click', backToAccounts);
    document.getElementById('btn-import-csv').addEventListener('click', () => {
      document.getElementById('csv-drop-zone').classList.toggle('hidden');
    });
    document.getElementById('btn-reconcile-selected').addEventListener('click', reconcileSelected);
    document.getElementById('txn-check-all').addEventListener('change', e => {
      document.querySelectorAll('.txn-check').forEach(cb => { cb.checked = e.target.checked; });
    });
    document.getElementById('txn-reconciled-filter').addEventListener('change', () => { txnPage=1; loadTransactions(); });
    document.getElementById('txn-from').addEventListener('change',   () => { txnPage=1; loadTransactions(); });
    document.getElementById('txn-to').addEventListener('change',     () => { txnPage=1; loadTransactions(); });

    // CSV file input
    const csvInput = document.getElementById('csv-file-input');
    csvInput.addEventListener('change', () => importCsv(csvInput.files[0]));
    const csvDrop  = document.getElementById('csv-drop-zone');
    csvDrop.addEventListener('dragover', e => e.preventDefault());
    csvDrop.addEventListener('drop', e => { e.preventDefault(); importCsv(e.dataTransfer.files[0]); });
  }

  async function load() {
    const res = await API.listBanks();
    if (!res?.success) { Toast.show('Failed to load bank accounts.', 'error'); return; }
    renderBankCards(res.data.bank_accounts);
  }

  function renderBankCards(accounts) {
    const grid = document.getElementById('bank-cards-grid');
    if (!accounts.length) {
      grid.innerHTML = '<div class="empty-state">No bank accounts yet. Add one to get started.</div>';
      return;
    }
    grid.innerHTML = accounts.map(a => `
      <div class="bank-card" onclick="BankModule.openBank(${a.id}, '${esc(a.name)}')">
        <div class="bank-card-header">
          <div>
            <div class="bank-card-name">${esc(a.name)}</div>
            <div style="font-size:12px;color:var(--c-text-3)">${esc(a.bank_name||'')} ${a.account_number ? '···'+esc(a.account_number) : ''}</div>
          </div>
          <span class="file-status-badge status-pending">${esc(a.currency)}</span>
        </div>
        <div class="bank-card-balance">${fmtCurrency(a.current_balance, a.currency)}</div>
        <div class="bank-card-footer">
          <span>${a.txn_count} transactions</span>
          ${a.unreconciled_count > 0
            ? `<span class="file-status-badge status-error">${a.unreconciled_count} unreconciled</span>`
            : '<span class="file-status-badge status-ready">All reconciled</span>'
          }
        </div>
      </div>
    `).join('');
  }

  function openBank(id, name) {
    currentBankId   = id;
    currentBankName = name;
    txnPage         = 1;
    document.getElementById('bank-accounts-panel').classList.add('hidden');
    document.getElementById('bank-txn-panel').classList.remove('hidden');
    document.getElementById('bank-txn-title').textContent = name + ' — Transactions';
    loadTransactions();
  }

  async function loadTransactions() {
    const params = {
      page:        txnPage,
      per_page:    50,
      reconciled:  document.getElementById('txn-reconciled-filter').value,
      date_from:   document.getElementById('txn-from').value,
      date_to:     document.getElementById('txn-to').value,
    };
    const res = await API.getBankTransactions(currentBankId, params);
    if (!res?.success) { Toast.show('Failed to load transactions.', 'error'); return; }
    renderTransactions(res.data.transactions);
    renderTxnPagination(res.data.pagination);

    // Update balance display from bank account list
    const bRes = await API.listBanks();
    if (bRes?.success) {
      const ba = bRes.data.bank_accounts.find(b => b.id === currentBankId);
      if (ba) {
        document.getElementById('bank-balance-display').textContent = fmtCurrency(ba.current_balance, ba.currency);
        document.getElementById('bank-unrec-count').textContent = ba.unreconciled_count;
      }
    }
  }

  function renderTransactions(txns) {
    const tbody = document.getElementById('txn-tbody');
    const empty = document.getElementById('txn-empty');
    if (!txns.length) { tbody.innerHTML=''; empty.classList.remove('hidden'); return; }
    empty.classList.add('hidden');
    tbody.innerHTML = txns.map(t => `
      <tr class="${t.is_reconciled ? 'txn-reconciled' : ''}">
        <td>${!t.is_reconciled ? `<input type="checkbox" class="txn-check" value="${t.id}" />` : '<span style="color:var(--c-success)">✓</span>'}</td>
        <td>${t.txn_date}</td>
        <td>${esc(t.description)}</td>
        <td style="color:var(--c-text-3)">${esc(t.reference||'—')}</td>
        <td class="col-num debit-cell">${t.type==='debit'  ? fmtCurrency(t.amount) : ''}</td>
        <td class="col-num credit-cell">${t.type==='credit' ? fmtCurrency(t.amount) : ''}</td>
        <td><span class="file-status-badge ${t.is_reconciled?'status-ready':'status-pending'}">${t.is_reconciled?'Reconciled':'Pending'}</span></td>
      </tr>
    `).join('');
  }

  async function reconcileSelected() {
    const checked = [...document.querySelectorAll('.txn-check:checked')].map(cb => parseInt(cb.value));
    if (!checked.length) { Toast.show('Select transactions to reconcile.', 'error'); return; }
    const res = await API.reconcileBank(currentBankId, { transaction_ids: checked });
    if (res?.success) { Toast.show(`${res.data.count} transaction(s) reconciled.`, 'success'); loadTransactions(); }
    else Toast.show(res?.error?.message || 'Reconciliation failed.', 'error');
  }

  async function importCsv(file) {
    if (!file) return;
    const formData = new FormData();
    formData.append('file', file);
    Toast.show('Importing CSV…');
    const res = await API.importBankCsv(currentBankId, formData);
    document.getElementById('csv-drop-zone').classList.add('hidden');
    if (res?.success) {
      Toast.show(`Imported ${res.data.imported} transactions.${res.data.errors.length?' ('+res.data.errors.length+' errors)':''}`, 'success');
      loadTransactions();
    } else {
      Toast.show(res?.error?.message || 'Import failed.', 'error');
    }
  }

  async function saveBankAccount() {
    const name    = document.getElementById('nb-name').value.trim();
    const bank    = document.getElementById('nb-bank').value.trim();
    const number  = document.getElementById('nb-number').value.trim();
    const currency= document.getElementById('nb-currency').value;
    const opening = document.getElementById('nb-opening').value;
    if (!name) { Toast.show('Account name is required.', 'error'); return; }
    const res = await API.createBank({ name, bank_name: bank||null, account_number: number||null, currency, opening_balance: opening });
    if (res?.success) { Toast.show('Bank account added.', 'success'); document.getElementById('new-bank-form').classList.add('hidden'); clearBankForm(); load(); }
    else Toast.show(res?.error?.message || 'Failed.', 'error');
  }

  function backToAccounts() {
    currentBankId = null;
    document.getElementById('bank-txn-panel').classList.add('hidden');
    document.getElementById('bank-accounts-panel').classList.remove('hidden');
    load();
  }

  function clearBankForm() {
    ['nb-name','nb-bank','nb-number'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('nb-opening').value  = '0';
    document.getElementById('nb-currency').value = 'USD';
  }

  function renderTxnPagination(pg) {
    const el = document.getElementById('txn-pagination');
    if (!pg || pg.total_pages <= 1) { el.innerHTML=''; return; }
    let html = `<button ${pg.page===1?'disabled':''} onclick="BankModule._go(${pg.page-1})">‹</button>`;
    html += `<span class="pagination-info">Page ${pg.page} of ${pg.total_pages} (${pg.total_count} transactions)</span>`;
    html += `<button ${pg.page===pg.total_pages?'disabled':''} onclick="BankModule._go(${pg.page+1})">›</button>`;
    el.innerHTML = html;
  }

  function _go(page) { txnPage=page; loadTransactions(); }

  function fmtCurrency(v, currency='USD') {
    try { return new Intl.NumberFormat('en-US',{style:'currency',currency}).format(v); }
    catch { return parseFloat(v).toFixed(2); }
  }
  function esc(s) { return String(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

  return { init, load, openBank, _go };
})();
