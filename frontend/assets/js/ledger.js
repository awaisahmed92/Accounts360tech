/**
 * Accounts360tech — Ledger Module
 */

const Ledger = (() => {
  let currentPage = 1;
  let sortBy  = 'created_at';
  let sortDir = 'desc';

  function init() {
    document.getElementById('ledger-search').addEventListener('input',   debounce(() => { currentPage = 1; load(); }, 400));
    document.getElementById('ledger-category').addEventListener('change', () => { currentPage = 1; load(); });
    document.getElementById('ledger-from').addEventListener('change',    () => { currentPage = 1; load(); });
    document.getElementById('ledger-to').addEventListener('change',      () => { currentPage = 1; load(); });
    document.getElementById('btn-export').addEventListener('click', exportCSV);

    // Sortable headers
    document.querySelectorAll('#ledger-table th[data-col]').forEach(th => {
      th.addEventListener('click', () => {
        const col = th.dataset.col;
        if (sortBy === col) sortDir = sortDir === 'asc' ? 'desc' : 'asc';
        else { sortBy = col; sortDir = 'desc'; }
        document.querySelectorAll('#ledger-table th').forEach(t => t.classList.remove('sort-asc','sort-desc'));
        th.classList.add(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
        currentPage = 1; load();
      });
    });
  }

  function buildParams(page) {
    return {
      page,
      per_page: 25,
      sort_by:  sortBy,
      sort_dir: sortDir,
      search:   document.getElementById('ledger-search').value.trim(),
      category: document.getElementById('ledger-category').value,
      date_from: document.getElementById('ledger-from').value,
      date_to:   document.getElementById('ledger-to').value,
    };
  }

  async function load() {
    const res = await API.listDocuments(buildParams(currentPage));
    if (!res?.success) { Toast.show('Failed to load ledger.', 'error'); return; }

    const { documents, pagination } = res.data;
    renderTable(documents);
    renderPagination(pagination);
    renderSummary(documents);
  }

  function renderTable(docs) {
    const tbody = document.getElementById('ledger-tbody');
    const empty = document.getElementById('ledger-empty');
    if (!docs.length) {
      tbody.innerHTML = '';
      empty.classList.remove('hidden');
      return;
    }
    empty.classList.add('hidden');
    tbody.innerHTML = docs.map(d => `
      <tr>
        <td>${d.document_date || '—'}</td>
        <td>
          <div style="font-weight:500">${esc(d.supplier_name || '—')}</div>
          <div style="font-size:12px;color:var(--c-text-3)">${esc(d.file_name)}</div>
        </td>
        <td><span style="background:var(--c-bg);padding:2px 8px;border-radius:10px;font-size:12px">${esc(d.category || '—')}</span></td>
        <td>${d.currency || 'USD'}</td>
        <td class="col-num" style="font-weight:600">${d.total_amount != null ? fmtNumber(d.total_amount) : '—'}</td>
        <td class="col-num">${d.tax_amount != null ? fmtNumber(d.tax_amount) : '—'}</td>
        <td>
          <span class="file-status-badge status-${d.status}">${statusLabel(d.status)}</span>
          ${d.is_approved ? '<span class="file-status-badge status-ready" style="margin-left:4px">✓ Approved</span>' : ''}
        </td>
        <td class="col-actions">
          <button class="btn btn-ghost btn-sm" onclick="Review.openDetail(${d.id}); App.switchView('review')">View</button>
        </td>
      </tr>
    `).join('');
  }

  function renderSummary(docs) {
    const sumEl = document.getElementById('ledger-summary');
    if (!docs.length) { sumEl.classList.add('hidden'); return; }
    sumEl.classList.remove('hidden');
    const totalSpend   = docs.reduce((a, d) => a + parseFloat(d.total_amount || 0), 0);
    const totalTax     = docs.reduce((a, d) => a + parseFloat(d.tax_amount   || 0), 0);
    const approvedCount= docs.filter(d => d.is_approved).length;
    document.getElementById('sum-total').textContent   = docs.length;
    document.getElementById('sum-spend').textContent   = '$' + totalSpend.toFixed(2);
    document.getElementById('sum-tax').textContent     = '$' + totalTax.toFixed(2);
    document.getElementById('sum-approved').textContent= approvedCount;
  }

  function renderPagination(pg) {
    const el = document.getElementById('ledger-pagination');
    if (!pg || pg.total_pages <= 1) { el.innerHTML = ''; return; }
    let html = `<button ${pg.page===1?'disabled':''} onclick="Ledger._go(${pg.page-1})">‹ Prev</button>`;
    const range = 2;
    for (let i = 1; i <= pg.total_pages; i++) {
      if (i === 1 || i === pg.total_pages || (i >= pg.page - range && i <= pg.page + range)) {
        html += `<button class="${i===pg.page?'active':''}" onclick="Ledger._go(${i})">${i}</button>`;
      } else if (i === pg.page - range - 1 || i === pg.page + range + 1) {
        html += `<button disabled>…</button>`;
      }
    }
    html += `<button ${pg.page===pg.total_pages?'disabled':''} onclick="Ledger._go(${pg.page+1})">Next ›</button>`;
    html += `<span class="pagination-info">${pg.total_count} records</span>`;
    el.innerHTML = html;
  }

  function _go(page) { currentPage = page; load(); }

  async function exportCSV() {
    const params = { ...buildParams(1) };
    delete params.page; delete params.per_page;
    const res = await API.exportDocuments(params);
    if (!res) return;
    if (res instanceof Response) {
      const blob = await res.blob();
      const url  = URL.createObjectURL(blob);
      const a    = document.createElement('a');
      a.href = url;
      a.download = `documents_export_${new Date().toISOString().slice(0,10)}.csv`;
      a.click();
      URL.revokeObjectURL(url);
    } else {
      Toast.show('Export failed.', 'error');
    }
  }

  function statusLabel(s) {
    return { pending:'Pending', processing:'Processing', ready:'Ready', error:'Error', archived:'Archived' }[s] || s;
  }
  function esc(s) { return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  function fmtNumber(v) { return parseFloat(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }

  return { init, load, _go };
})();
