/**
 * Accounts360tech — Journal Entries Module
 */

const Journal = (() => {
  let currentPage  = 1;
  let currentId    = null;
  let isNewEntry   = false;
  let accountsList = [];  // cached accounts for dropdowns

  function init() {
    document.getElementById('journal-search').addEventListener('input', debounce(() => { currentPage=1; load(); }, 400));
    document.getElementById('journal-status-filter').addEventListener('change', () => { currentPage=1; load(); });
    document.getElementById('journal-from').addEventListener('change', () => load());
    document.getElementById('journal-to').addEventListener('change',   () => load());
    document.getElementById('btn-new-journal').addEventListener('click',   openNew);
    document.getElementById('btn-back-journal').addEventListener('click',  closeDetail);
    document.getElementById('btn-add-line').addEventListener('click',      addLine);
    document.getElementById('btn-save-draft').addEventListener('click',    () => saveEntry('draft'));
    document.getElementById('btn-save-post').addEventListener('click',     () => saveEntry('posted'));
    document.getElementById('btn-post-entry').addEventListener('click',    postEntry);
    document.getElementById('btn-delete-entry').addEventListener('click',  deleteEntry);
  }

  async function load() {
    const params = {
      page:      currentPage,
      per_page:  20,
      search:    document.getElementById('journal-search').value.trim(),
      status:    document.getElementById('journal-status-filter').value,
      date_from: document.getElementById('journal-from').value,
      date_to:   document.getElementById('journal-to').value,
    };
    const res = await API.listJournalEntries(params);
    if (!res?.success) { Toast.show('Failed to load journal.', 'error'); return; }
    renderTable(res.data.entries);
    renderPagination('journal-pagination', res.data.pagination, p => { currentPage=p; load(); });
  }

  function renderTable(entries) {
    const tbody = document.getElementById('journal-tbody');
    const empty = document.getElementById('journal-empty');
    if (!entries.length) { tbody.innerHTML=''; empty.classList.remove('hidden'); return; }
    empty.classList.add('hidden');
    tbody.innerHTML = entries.map(e => `
      <tr style="cursor:pointer" onclick="Journal.openDetail(${e.id})">
        <td style="color:var(--c-text-3)">${e.id}</td>
        <td>${e.entry_date}</td>
        <td style="font-weight:500">${esc(e.reference||'—')}</td>
        <td>${esc(e.description||'')}</td>
        <td class="col-num">${e.total_debit != null ? fmt(e.total_debit) : '—'}</td>
        <td class="col-num">${e.line_count}</td>
        <td><span class="je-status-badge je-${e.status}">${e.status}</span></td>
        <td class="col-actions"><button class="btn btn-ghost btn-sm" onclick="event.stopPropagation();Journal.openDetail(${e.id})">View</button></td>
      </tr>
    `).join('');
  }

  async function loadAccounts() {
    if (accountsList.length) return accountsList;
    const res = await API.listAccounts({});
    if (res?.success) accountsList = res.data.accounts;
    return accountsList;
  }

  async function openDetail(id) {
    currentId  = id;
    isNewEntry = false;
    const res  = await API.getJournalEntry(id);
    if (!res?.success) { Toast.show('Failed to load entry.', 'error'); return; }
    showDetailPanel(res.data, false);
  }

  async function openNew() {
    currentId  = null;
    isNewEntry = true;
    showDetailPanel({ entry: { entry_date: today(), reference:'', description:'', status:'draft' }, lines: [] }, true);
    // Start with 2 blank lines
    addLine(); addLine();
  }

  function showDetailPanel(data, editable) {
    document.getElementById('journal-list-panel').classList.add('hidden');
    document.getElementById('journal-detail').classList.remove('hidden');

    const { entry, lines, total_debit, total_credit, is_balanced } = data;

    document.getElementById('journal-detail-title').textContent = isNewEntry
      ? 'New Journal Entry' : `Entry #${entry.id} — ${entry.status.toUpperCase()}`;
    document.getElementById('je-date').value  = entry.entry_date || today();
    document.getElementById('je-ref').value   = entry.reference  || '';
    document.getElementById('je-desc').value  = entry.description || '';

    const isPosted = entry.status === 'posted';
    document.getElementById('je-date').disabled = isPosted;
    document.getElementById('je-ref').disabled  = isPosted;
    document.getElementById('je-desc').disabled = isPosted;
    document.getElementById('je-edit-controls').style.display  = isPosted ? 'none' : 'flex';
    document.getElementById('btn-post-entry').style.display    = (!isPosted && !isNewEntry) ? 'inline-flex' : 'none';
    document.getElementById('btn-delete-entry').style.display  = (!isPosted && !isNewEntry) ? 'inline-flex' : 'none';

    renderLines(lines, !isPosted);
    renderTotals(total_debit || 0, total_credit || 0, is_balanced);
  }

  async function renderLines(lines, editable) {
    const accounts = await loadAccounts();
    const acctOpts = accounts.map(a => `<option value="${a.id}">${esc(a.code)} — ${esc(a.name)}</option>`).join('');
    const tbody    = document.getElementById('je-lines-tbody');

    if (!editable) {
      tbody.innerHTML = lines.map(l => `
        <tr>
          <td><span class="acct-code">${esc(l.code)}</span> ${esc(l.account_name)}</td>
          <td>${esc(l.description||'')}</td>
          <td class="col-num debit-cell">${parseFloat(l.debit)>0 ? fmt(l.debit) : ''}</td>
          <td class="col-num credit-cell">${parseFloat(l.credit)>0 ? fmt(l.credit) : ''}</td>
          <td></td>
        </tr>
      `).join('');
    } else {
      tbody.innerHTML = lines.map((l, i) => buildLineRow(i, l, acctOpts)).join('');
      tbody.dataset.acctOpts = acctOpts;
      attachLineEvents();
    }
  }

  function buildLineRow(idx, line = {}, acctOpts) {
    const opts = acctOpts || document.getElementById('je-lines-tbody').dataset.acctOpts || '';
    return `
      <tr data-line-idx="${idx}">
        <td>
          <select class="line-account" style="width:100%">
            <option value="">— select account —</option>
            ${opts}
          </select>
        </td>
        <td><input type="text"   class="line-desc"   placeholder="Description" value="${esc(line.description||'')}" style="width:100%"/></td>
        <td><input type="number" class="line-debit"  placeholder="0.00" value="${parseFloat(line.debit||0)||''}"  step="0.01" style="width:90px"/></td>
        <td><input type="number" class="line-credit" placeholder="0.00" value="${parseFloat(line.credit||0)||''}" step="0.01" style="width:90px"/></td>
        <td><button class="btn btn-ghost btn-sm" onclick="Journal.removeLine(this)">✕</button></td>
      </tr>
    `;
  }

  async function addLine() {
    const tbody   = document.getElementById('je-lines-tbody');
    const accounts= await loadAccounts();
    const opts    = accounts.map(a => `<option value="${a.id}">${esc(a.code)} — ${esc(a.name)}</option>`).join('');
    const idx     = tbody.querySelectorAll('tr').length;
    tbody.insertAdjacentHTML('beforeend', buildLineRow(idx, {}, opts));
    tbody.dataset.acctOpts = opts;
    attachLineEvents();
  }

  function removeLine(btn) {
    btn.closest('tr').remove();
    recalcTotals();
  }

  function attachLineEvents() {
    document.querySelectorAll('#je-lines-tbody .line-debit, #je-lines-tbody .line-credit').forEach(inp => {
      inp.addEventListener('input', recalcTotals);
    });
  }

  function recalcTotals() {
    let debit = 0, credit = 0;
    document.querySelectorAll('#je-lines-tbody tr').forEach(tr => {
      debit  += parseFloat(tr.querySelector('.line-debit')?.value  || 0) || 0;
      credit += parseFloat(tr.querySelector('.line-credit')?.value || 0) || 0;
    });
    renderTotals(debit, credit, Math.abs(debit - credit) < 0.01 && debit > 0);
  }

  function renderTotals(debit, credit, balanced) {
    document.getElementById('je-totals').innerHTML = `
      <div class="je-totals-row">
        <span>Total Debits: <strong class="debit-cell">${fmt(debit)}</strong></span>
        <span>Total Credits: <strong class="credit-cell">${fmt(credit)}</strong></span>
        <span class="${balanced ? 'balanced-ok' : 'balanced-err'}">${balanced ? '✓ Balanced' : '⚠ Not balanced'}</span>
      </div>
    `;
    const msgEl = document.getElementById('je-balance-msg');
    if (!balanced && debit > 0) {
      msgEl.className = 'alert alert-warning';
      msgEl.textContent = `Difference: ${fmt(Math.abs(debit - credit))}`;
    } else {
      msgEl.className = 'alert hidden';
    }
  }

  function collectLines() {
    const lines = [];
    document.querySelectorAll('#je-lines-tbody tr').forEach(tr => {
      const accountId = tr.querySelector('.line-account')?.value;
      const desc      = tr.querySelector('.line-desc')?.value || '';
      const debit     = parseFloat(tr.querySelector('.line-debit')?.value  || 0) || 0;
      const credit    = parseFloat(tr.querySelector('.line-credit')?.value || 0) || 0;
      if (accountId && (debit > 0 || credit > 0)) {
        lines.push({ account_id: parseInt(accountId), description: desc, debit, credit, currency: 'USD' });
      }
    });
    return lines;
  }

  async function saveEntry(status) {
    const lines = collectLines();
    if (lines.length < 2) { Toast.show('Add at least 2 lines.', 'error'); return; }
    const totalD = lines.reduce((s,l)=>s+l.debit, 0);
    const totalC = lines.reduce((s,l)=>s+l.credit, 0);
    if (Math.abs(totalD - totalC) >= 0.01) { Toast.show('Entry is not balanced.', 'error'); return; }

    const payload = {
      entry_date:  document.getElementById('je-date').value,
      reference:   document.getElementById('je-ref').value.trim(),
      description: document.getElementById('je-desc').value.trim(),
      status, lines,
    };

    let res;
    if (isNewEntry) {
      res = await API.createJournalEntry(payload);
    } else {
      res = await API.updateJournalEntry(currentId, payload);
    }

    if (res?.success) { Toast.show(`Entry ${isNewEntry?'created':'updated'}.`, 'success'); closeDetail(); }
    else Toast.show(res?.error?.message || 'Save failed.', 'error');
  }

  async function postEntry() {
    if (!currentId) return;
    if (!confirm('Post this entry? It will be locked and cannot be edited.')) return;
    const res = await API.postJournalEntry(currentId);
    if (res?.success) { Toast.show('Entry posted.', 'success'); openDetail(currentId); }
    else Toast.show(res?.error?.message || 'Post failed.', 'error');
  }

  async function deleteEntry() {
    if (!currentId || !confirm('Delete this draft entry?')) return;
    const res = await API.deleteJournalEntry(currentId);
    if (res?.success) { Toast.show('Entry deleted.', 'success'); closeDetail(); }
    else Toast.show(res?.error?.message || 'Delete failed.', 'error');
  }

  function closeDetail() {
    currentId = null;
    document.getElementById('journal-detail').classList.add('hidden');
    document.getElementById('journal-list-panel').classList.remove('hidden');
    load();
  }

  function today() { return new Date().toISOString().slice(0,10); }
  function fmt(v)   { return parseFloat(v).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function esc(s)   { return String(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  function debounce(fn,ms){ let t; return(...a)=>{clearTimeout(t);t=setTimeout(()=>fn(...a),ms);}; }
  function renderPagination(id, pg, cb) {
    const el = document.getElementById(id);
    if (!pg || pg.total_pages <= 1) { el.innerHTML=''; return; }
    let html = `<button ${pg.page===1?'disabled':''} onclick="(${cb.toString()})(${pg.page-1})">‹</button>`;
    for (let i=1;i<=pg.total_pages;i++) html+=`<button class="${i===pg.page?'active':''}" onclick="(${cb.toString()})(${i})">${i}</button>`;
    html+=`<button ${pg.page===pg.total_pages?'disabled':''} onclick="(${cb.toString()})(${pg.page+1})">›</button>`;
    html+=`<span class="pagination-info">${pg.total_count} entries</span>`;
    el.innerHTML = html;
  }

  return { init, load, openDetail, removeLine };
})();
