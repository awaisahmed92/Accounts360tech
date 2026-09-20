/**
 * Accounts360tech — Financial Reports Module
 */

const Reports = (() => {
  let activeReport = 'pl'; // pl | bs | tb

  function init() {
    document.querySelectorAll('.tab[data-report]').forEach(tab => {
      tab.addEventListener('click', () => {
        document.querySelectorAll('.tab[data-report]').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        activeReport = tab.dataset.report;
        toggleControls();
        // Auto-run on tab switch
        runReport();
      });
    });

    document.getElementById('report-period-preset').addEventListener('change', applyPreset);
    document.getElementById('btn-run-report').addEventListener('click', runReport);
    document.getElementById('btn-run-bs').addEventListener('click', runReport);
    document.getElementById('btn-print-report').addEventListener('click', () => window.print());
    document.getElementById('btn-print-bs').addEventListener('click',     () => window.print());

    // Init dates
    applyPreset();
    toggleControls();
  }

  function toggleControls() {
    const isPeriod = activeReport !== 'bs';
    document.getElementById('report-period-controls').classList.toggle('hidden', !isPeriod);
    document.getElementById('report-as-of-controls').classList.toggle('hidden',   isPeriod);
  }

  function applyPreset() {
    const preset = document.getElementById('report-period-preset').value;
    const today  = new Date();
    let from, to;
    switch (preset) {
      case 'this_month':
        from = new Date(today.getFullYear(), today.getMonth(), 1);
        to   = today;
        break;
      case 'last_month':
        from = new Date(today.getFullYear(), today.getMonth()-1, 1);
        to   = new Date(today.getFullYear(), today.getMonth(), 0);
        break;
      case 'this_quarter': {
        const q = Math.floor(today.getMonth()/3);
        from = new Date(today.getFullYear(), q*3, 1);
        to   = today;
        break;
      }
      case 'this_year':
        from = new Date(today.getFullYear(), 0, 1);
        to   = today;
        break;
      case 'custom': return;
    }
    document.getElementById('report-from').value = fmt(from);
    document.getElementById('report-to').value   = fmt(to);
    document.getElementById('report-as-of').value= fmt(today);
  }

  async function runReport() {
    document.getElementById('report-output').innerHTML = '<div class="empty-state" style="padding:60px">Loading report…</div>';

    switch (activeReport) {
      case 'pl': await renderPL();  break;
      case 'bs': await renderBS();  break;
      case 'tb': await renderTB();  break;
    }
  }

  async function renderPL() {
    const from = document.getElementById('report-from').value;
    const to   = document.getElementById('report-to').value;
    const res  = await API.getProfitLoss({ date_from: from, date_to: to });
    if (!res?.success) { showError(res); return; }
    const d = res.data;

    document.getElementById('report-output').innerHTML = `
      <div class="report-wrapper" id="report-printable">
        <div class="report-header">
          <div class="report-title">Profit &amp; Loss Statement</div>
          <div class="report-period">For the period: ${d.period.from} to ${d.period.to}</div>
        </div>

        <div class="report-section">
          <div class="report-section-title income-title">Income</div>
          ${d.income.map(row => `
            <div class="report-row">
              <span class="report-acct-code">${esc(row.code)}</span>
              <span class="report-acct-name">${esc(row.name)}</span>
              <span class="report-amount income-amount">${fmt2(row.net_amount)}</span>
            </div>
          `).join('')}
          <div class="report-subtotal">
            <span>Total Income</span>
            <span class="income-amount">${fmt2(d.total_income)}</span>
          </div>
        </div>

        <div class="report-section" style="margin-top:24px">
          <div class="report-section-title expense-title">Expenses</div>
          ${d.expenses.map(row => `
            <div class="report-row">
              <span class="report-acct-code">${esc(row.code)}</span>
              <span class="report-acct-name">${esc(row.name)}</span>
              <span class="report-amount expense-amount">${fmt2(row.net_amount)}</span>
            </div>
          `).join('')}
          <div class="report-subtotal">
            <span>Total Expenses</span>
            <span class="expense-amount">${fmt2(d.total_expenses)}</span>
          </div>
        </div>

        <div class="report-net ${d.net_profit >= 0 ? 'report-net--profit' : 'report-net--loss'}">
          <span>${d.net_profit >= 0 ? 'Net Profit' : 'Net Loss'}</span>
          <span>${fmt2(Math.abs(d.net_profit))}</span>
        </div>
      </div>
    `;
  }

  async function renderBS() {
    const asOf = document.getElementById('report-as-of').value || new Date().toISOString().slice(0,10);
    const res  = await API.getBalanceSheet({ as_of: asOf });
    if (!res?.success) { showError(res); return; }
    const d = res.data;

    document.getElementById('report-output').innerHTML = `
      <div class="report-wrapper" id="report-printable">
        <div class="report-header">
          <div class="report-title">Balance Sheet</div>
          <div class="report-period">As of: ${d.as_of}</div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:32px">
          <!-- Assets -->
          <div>
            <div class="report-section">
              <div class="report-section-title" style="color:var(--c-accent)">Assets</div>
              ${d.assets.map(r => `<div class="report-row"><span class="report-acct-code">${esc(r.code)}</span><span class="report-acct-name">${esc(r.name)}</span><span class="report-amount">${fmt2(r.net_amount)}</span></div>`).join('')}
              <div class="report-subtotal"><span>Total Assets</span><span>${fmt2(d.total_assets)}</span></div>
            </div>
          </div>

          <!-- Liabilities + Equity -->
          <div>
            <div class="report-section">
              <div class="report-section-title" style="color:var(--c-warning)">Liabilities</div>
              ${d.liabilities.map(r => `<div class="report-row"><span class="report-acct-code">${esc(r.code)}</span><span class="report-acct-name">${esc(r.name)}</span><span class="report-amount">${fmt2(r.net_amount)}</span></div>`).join('')}
              <div class="report-subtotal"><span>Total Liabilities</span><span>${fmt2(d.total_liabilities)}</span></div>
            </div>
            <div class="report-section" style="margin-top:20px">
              <div class="report-section-title" style="color:#8b5cf6">Equity</div>
              ${d.equity.map(r => `<div class="report-row"><span class="report-acct-code">${esc(r.code)}</span><span class="report-acct-name">${esc(r.name)}</span><span class="report-amount">${fmt2(r.net_amount)}</span></div>`).join('')}
              <div class="report-row"><span class="report-acct-code">RE</span><span class="report-acct-name">Retained Earnings</span><span class="report-amount">${fmt2(d.retained_earnings)}</span></div>
              <div class="report-subtotal"><span>Total Equity</span><span>${fmt2(d.total_equity)}</span></div>
            </div>
            <div class="report-subtotal" style="margin-top:8px">
              <span>Total Liabilities + Equity</span>
              <span>${fmt2(d.total_liabilities + d.total_equity)}</span>
            </div>
          </div>
        </div>

        ${!d.is_balanced ? '<div class="alert alert-warning" style="margin-top:16px">⚠ Balance sheet is not balanced — check for missing journal entries.</div>' : ''}
      </div>
    `;
  }

  async function renderTB() {
    const from = document.getElementById('report-from').value;
    const to   = document.getElementById('report-to').value;
    const res  = await API.getTrialBalance({ date_from: from, date_to: to });
    if (!res?.success) { showError(res); return; }
    const d = res.data;

    document.getElementById('report-output').innerHTML = `
      <div class="report-wrapper" id="report-printable">
        <div class="report-header">
          <div class="report-title">Trial Balance</div>
          <div class="report-period">For the period: ${d.period.from} to ${d.period.to}</div>
        </div>
        <table class="data-table report-table">
          <thead>
            <tr><th>Code</th><th>Account Name</th><th>Type</th><th class="col-num">Debit</th><th class="col-num">Credit</th></tr>
          </thead>
          <tbody>
            ${d.accounts.map(r => `
              <tr>
                <td><span class="acct-code">${esc(r.code)}</span></td>
                <td>${esc(r.name)}</td>
                <td><span class="report-type-badge report-type-${r.type.toLowerCase()}">${r.type}</span></td>
                <td class="col-num debit-cell">${parseFloat(r.total_debit) > 0 ? fmt2(r.total_debit) : ''}</td>
                <td class="col-num credit-cell">${parseFloat(r.total_credit) > 0 ? fmt2(r.total_credit) : ''}</td>
              </tr>
            `).join('')}
          </tbody>
          <tfoot>
            <tr class="report-subtotal">
              <td colspan="3"><strong>Totals</strong></td>
              <td class="col-num"><strong class="debit-cell">${fmt2(d.grand_debit)}</strong></td>
              <td class="col-num"><strong class="credit-cell">${fmt2(d.grand_credit)}</strong></td>
            </tr>
          </tfoot>
        </table>
        <div class="${d.is_balanced ? 'alert alert-success' : 'alert alert-warning'}" style="margin-top:12px">
          ${d.is_balanced ? '✓ Trial balance is balanced.' : '⚠ Trial balance is NOT balanced. Difference: ' + fmt2(Math.abs(d.grand_debit - d.grand_credit))}
        </div>
      </div>
    `;
  }

  function showError(res) {
    document.getElementById('report-output').innerHTML = `<div class="alert alert-error">${res?.error?.message || 'Failed to load report.'}</div>`;
  }

  function fmt(d)  { return d instanceof Date ? d.toISOString().slice(0,10) : d; }
  function fmt2(v) { return parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function esc(s)  { return String(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

  return { init, load: runReport };
})();
