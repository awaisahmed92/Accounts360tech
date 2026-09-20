/**
 * Accounts360tech — Review Module
 */

const Review = (() => {
  let currentPage = 1;
  let currentDocId = null;
  let pollTimer = null;
  let currentPreviewUrl = null;

  const POLL_IDS = new Set(); // IDs currently processing

  function init() {
    document.getElementById('review-search').addEventListener('input', debounce(() => { currentPage = 1; load(); }, 400));
    document.getElementById('review-status-filter').addEventListener('change', () => { currentPage = 1; load(); });
    document.getElementById('btn-back-review').addEventListener('click', closeDetail);
    document.getElementById('btn-approve-doc').addEventListener('click', approveDoc);
    document.getElementById('btn-archive-doc').addEventListener('click', () => archiveDoc());
    document.getElementById('btn-delete-doc').addEventListener('click', deleteDoc);
    document.getElementById('extracted-form').addEventListener('submit', saveExtracted);
  }

  async function load() {
    const params = {
      page:    currentPage,
      per_page: 20,
      search:  document.getElementById('review-search').value.trim(),
      status:  document.getElementById('review-status-filter').value,
    };
    const res = await API.listDocuments(params);
    if (!res?.success) { Toast.show('Failed to load documents.', 'error'); return; }
    renderTable(res.data.documents);
    renderPagination('review-pagination', res.data.pagination, p => { currentPage = p; load(); });
    updateReviewBadge(res.data.documents);
    schedulePoll(res.data.documents);
  }

  function updateReviewBadge(docs) {
    const pending = docs.filter(d => d.status === 'ready' && !d.is_approved).length;
    const badge   = document.getElementById('review-badge');
    badge.textContent  = pending > 0 ? pending : '';
    badge.style.display = pending > 0 ? 'inline-block' : 'none';
  }

  function schedulePoll(docs) {
    const processing = docs.filter(d => d.status === 'pending' || d.status === 'processing').map(d => d.id);
    clearTimeout(pollTimer);
    if (processing.length > 0) {
      pollTimer = setTimeout(() => load(), 3000);
    }
  }

  function renderTable(docs) {
    const tbody  = document.getElementById('review-tbody');
    const empty  = document.getElementById('review-empty');
    if (!docs.length) {
      tbody.innerHTML = ''; empty.classList.remove('hidden'); return;
    }
    empty.classList.add('hidden');
    tbody.innerHTML = docs.map(d => `
      <tr>
        <td>
          <div style="display:flex;align-items:center;gap:8px">
            <span class="file-icon" style="width:28px;height:28px;font-size:10px">${fileExt(d.file_name)}</span>
            <span style="font-weight:500">${esc(d.file_name)}</span>
          </div>
        </td>
        <td>${d.supplier_name ? esc(d.supplier_name) : '<span style="color:var(--c-text-3)">—</span>'}</td>
        <td>${d.document_date || '—'}</td>
        <td class="col-num">${d.total_amount != null ? fmtCurrency(d.total_amount, d.currency) : '—'}</td>
        <td><span class="file-status-badge status-${d.status}">${statusLabel(d.status)}</span></td>
        <td>${d.confidence_score != null ? `<span style="color:var(--c-success);font-weight:600">${d.confidence_score}%</span>` : '—'}</td>
        <td class="col-actions">
          ${d.status === 'ready' && !d.is_approved
            ? `<button class="btn btn-primary btn-sm" onclick="Review.openDetail(${d.id})">Review</button>`
            : `<button class="btn btn-ghost btn-sm" onclick="Review.openDetail(${d.id})">View</button>`
          }
        </td>
      </tr>
    `).join('');
  }

  async function openDetail(id) {
    currentDocId = id;
    document.getElementById('review-list-panel').classList.add('hidden');
    document.getElementById('review-detail').classList.remove('hidden');

    const res = await API.getDocument(id);
    if (!res?.success) { Toast.show('Failed to load document.', 'error'); return; }

    const { document: doc, extracted, line_items, duplicate_warning } = res.data;

    // Duplicate warning
    const dupEl = document.getElementById('dup-warning');
    if (duplicate_warning) {
      dupEl.className = 'alert alert-warning';
      dupEl.innerHTML = `⚠ Possible duplicate: <strong>${esc(duplicate_warning.file_name)}</strong> has the same supplier, date, and total.`;
    } else {
      dupEl.className = 'hidden';
    }

    // Confidence badge
    if (extracted?.confidence_score) {
      document.getElementById('conf-badge').textContent = extracted.confidence_score + '%';
    }

    // Populate form
    if (extracted) {
      document.getElementById('f-supplier').value  = extracted.supplier_name || '';
      document.getElementById('f-date').value      = extracted.document_date || '';
      document.getElementById('f-total').value     = extracted.total_amount  || '';
      document.getElementById('f-tax').value       = extracted.tax_amount    || '';
      document.getElementById('f-currency').value  = extracted.currency      || 'USD';
      document.getElementById('f-category').value  = extracted.category      || 'Other / Uncategorized';
    }

    // Approve button state
    const approved = extracted?.is_approved;
    const btnApprove = document.getElementById('btn-approve-doc');
    btnApprove.disabled = approved || doc.status !== 'ready';
    btnApprove.textContent = approved ? 'Approved ✓' : 'Approve';

    // Document preview (authenticated fetch -> blob URL)
    const preview = document.getElementById('doc-preview');
    const _apiBase = API.getBaseUrl ? API.getBaseUrl() : window.location.origin + '/backend/public/api/v1';
    const dlUrl   = `${_apiBase}/documents/${id}/download`;
    await loadPreview(preview, dlUrl, doc.file_type);

    // Line items
    const liSection = document.getElementById('line-items-section');
    const liTbody   = document.getElementById('line-items-tbody');
    if (line_items?.length) {
      liSection.style.display = '';
      liTbody.innerHTML = line_items.map(li => `
        <tr>
          <td>${esc(li.description || '—')}</td>
          <td>${li.quantity ?? '—'}</td>
          <td class="col-num">${li.unit_price != null ? fmtCurrency(li.unit_price, extracted?.currency) : '—'}</td>
          <td class="col-num">${li.line_total != null ? fmtCurrency(li.line_total, extracted?.currency) : '—'}</td>
        </tr>
      `).join('');
    } else {
      liSection.style.display = 'none';
    }
  }

  function closeDetail() {
    clearPreviewUrl();
    currentDocId = null;
    document.getElementById('review-detail').classList.add('hidden');
    document.getElementById('review-list-panel').classList.remove('hidden');
    load();
  }

  function clearPreviewUrl() {
    if (currentPreviewUrl) {
      URL.revokeObjectURL(currentPreviewUrl);
      currentPreviewUrl = null;
    }
  }

  async function loadPreview(previewEl, url, fileType) {
    clearPreviewUrl();
    previewEl.innerHTML = '<div style="padding:10px;color:var(--c-text-3)">Loading preview...</div>';

    const token = API.getToken();
    const res = await fetch(url, {
      headers: token ? { Authorization: `Bearer ${token}` } : {},
    });

    if (!res.ok) {
      previewEl.innerHTML = '<div style="padding:10px;color:var(--c-danger)">Preview unavailable.</div>';
      return;
    }

    const blob = await res.blob();
    currentPreviewUrl = URL.createObjectURL(blob);
    if (fileType === 'application/pdf') {
      previewEl.innerHTML = `<iframe src="${currentPreviewUrl}" title="Document preview"></iframe>`;
    } else {
      previewEl.innerHTML = `<img src="${currentPreviewUrl}" alt="Document" style="max-width:100%" />`;
    }
  }

  async function saveExtracted(e) {
    e.preventDefault();
    if (!currentDocId) return;
    const data = {
      supplier_name: document.getElementById('f-supplier').value.trim(),
      document_date: document.getElementById('f-date').value,
      total_amount:  document.getElementById('f-total').value,
      tax_amount:    document.getElementById('f-tax').value,
      currency:      document.getElementById('f-currency').value,
      category:      document.getElementById('f-category').value,
    };
    const res = await API.updateDocument(currentDocId, data);
    const ind = document.getElementById('save-indicator');
    if (res?.success) {
      ind.classList.remove('hidden'); setTimeout(() => ind.classList.add('hidden'), 2000);
    } else {
      Toast.show(res?.error?.message || 'Save failed.', 'error');
    }
  }

  async function approveDoc() {
    if (!currentDocId) return;
    const res = await API.approveDocument(currentDocId);
    if (res?.success) {
      Toast.show('Document approved!', 'success');
      document.getElementById('btn-approve-doc').disabled = true;
      document.getElementById('btn-approve-doc').textContent = 'Approved ✓';
    } else {
      Toast.show(res?.error?.message || 'Approval failed.', 'error');
    }
  }

  async function archiveDoc() {
    if (!currentDocId) return;
    if (!confirm('Archive this document?')) return;
    const res = await API.archiveDocument(currentDocId);
    if (res?.success) { Toast.show('Document archived.', 'success'); closeDetail(); }
    else Toast.show(res?.error?.message || 'Archive failed.', 'error');
  }

  async function deleteDoc() {
    if (!currentDocId) return;
    if (!confirm('Delete this document? This cannot be undone.')) return;
    const res = await API.deleteDocument(currentDocId);
    if (res?.success) { Toast.show('Document deleted.', 'success'); closeDetail(); }
    else Toast.show(res?.error?.message || 'Delete failed.', 'error');
  }

  function statusLabel(s) {
    return { pending:'Pending', processing:'Processing', ready:'Ready', error:'Error', archived:'Archived' }[s] || s;
  }
  function fileExt(name) { return (name||'').split('.').pop().toUpperCase().slice(0,4); }
  function esc(s) { return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  function fmtCurrency(v, currency = 'USD') {
    try { return new Intl.NumberFormat('en-US', { style:'currency', currency }).format(v); }
    catch { return `${currency} ${parseFloat(v).toFixed(2)}`; }
  }
  function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }
  function renderPagination(id, pg, cb) {
    const el = document.getElementById(id);
    if (!pg || pg.total_pages <= 1) { el.innerHTML = ''; return; }
    let html = `<button ${pg.page===1?'disabled':''} onclick="cb(${pg.page-1})">‹ Prev</button>`;
    for (let i = 1; i <= pg.total_pages; i++) {
      html += `<button class="${i===pg.page?'active':''}" onclick="(${cb.toString()})(${i})">${i}</button>`;
    }
    html += `<button ${pg.page===pg.total_pages?'disabled':''} onclick="(${cb.toString()})(${pg.page+1})">Next ›</button>`;
    html += `<span class="pagination-info">Showing ${(pg.page-1)*pg.per_page+1}–${Math.min(pg.page*pg.per_page, pg.total_count)} of ${pg.total_count}</span>`;
    el.innerHTML = html;
  }

  return { init, load, openDetail };
})();
