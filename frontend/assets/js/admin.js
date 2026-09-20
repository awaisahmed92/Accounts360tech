/**
 * Accounts360tech — Admin Module
 */

const AdminPanel = (() => {
  let usersPage = 1;
  let logsPage  = 1;

  function init() {
    document.querySelectorAll('.tab').forEach(tab => {
      tab.addEventListener('click', () => {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(t => t.classList.add('hidden'));
        tab.classList.add('active');
        document.getElementById(`tab-${tab.dataset.tab}`).classList.remove('hidden');
      });
    });
  }

  async function load() {
    await Promise.all([loadStats(), loadUsers(), loadLogs()]);
  }

  async function loadStats() {
    const res = await API.adminStats();
    if (!res?.success) return;
    const s = res.data;
    document.getElementById('stat-today').textContent      = s.documents_today;
    document.getElementById('stat-total').textContent      = s.total_documents;
    document.getElementById('stat-error-rate').textContent = s.error_rate_pct + '%';
    document.getElementById('stat-latency').textContent    = s.avg_ai_latency_ms ? s.avg_ai_latency_ms + 'ms' : '—';
    document.getElementById('stat-queue').textContent      = s.queue_pending;
    document.getElementById('stat-users').textContent      = s.total_users;
  }

  async function loadUsers() {
    const res = await API.adminUsers({ page: usersPage, per_page: 25 });
    if (!res?.success) return;
    const tbody = document.getElementById('admin-users-tbody');
    tbody.innerHTML = res.data.users.map(u => `
      <tr>
        <td>${u.id}</td>
        <td style="font-weight:500">${esc(u.name)}</td>
        <td>${esc(u.email)}</td>
        <td><span class="file-status-badge ${u.role==='admin'?'status-processing':'status-pending'}">${u.role}</span></td>
        <td class="col-num">${u.doc_count}</td>
        <td>${u.last_login_at ? new Date(u.last_login_at).toLocaleString() : '—'}</td>
        <td><span class="file-status-badge ${u.is_active?'status-ready':'status-error'}">${u.is_active?'Active':'Inactive'}</span></td>
        <td class="col-actions">
          <button class="btn btn-ghost btn-sm" onclick="AdminPanel.toggleUser(${u.id},${u.is_active?0:1})">
            ${u.is_active?'Deactivate':'Activate'}
          </button>
        </td>
      </tr>
    `).join('');
    renderAdminPagination('admin-users-pagination', res.data.pagination, p => { usersPage = p; loadUsers(); });
  }

  async function loadLogs() {
    const res = await API.adminLogs({ page: logsPage, per_page: 50 });
    if (!res?.success) return;
    const tbody = document.getElementById('admin-logs-tbody');
    tbody.innerHTML = res.data.logs.map(l => `
      <tr>
        <td style="font-weight:500">${esc(l.original_file_name)}</td>
        <td>${esc(l.user_name)}</td>
        <td class="col-num">${l.attempt_number}</td>
        <td class="col-num">
          <span class="file-status-badge ${l.ai_http_status===200?'status-ready':'status-error'}">${l.ai_http_status||'—'}</span>
        </td>
        <td class="col-num">${l.ai_response_ms ? l.ai_response_ms + 'ms' : '—'}</td>
        <td><span class="file-status-badge ${l.error_message?'status-error':'status-ready'}">${l.error_message?'Failed':'Success'}</span></td>
        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:var(--c-danger)">
          ${l.error_message ? esc(l.error_message) : '—'}
        </td>
        <td style="font-size:12px;color:var(--c-text-3)">${l.started_at ? new Date(l.started_at).toLocaleString() : '—'}</td>
      </tr>
    `).join('');
    renderAdminPagination('admin-logs-pagination', res.data.pagination, p => { logsPage = p; loadLogs(); });
  }

  async function toggleUser(id, isActive) {
    const res = await API.adminUpdateUser(id, { is_active: isActive });
    if (res?.success) { Toast.show(`User ${isActive ? 'activated' : 'deactivated'}.`, 'success'); loadUsers(); }
    else Toast.show(res?.error?.message || 'Update failed.', 'error');
  }

  function renderAdminPagination(id, pg, cb) {
    const el = document.getElementById(id);
    if (!pg || pg.total_pages <= 1) { el.innerHTML = ''; return; }
    let html = `<button ${pg.page===1?'disabled':''} onclick="(${cb.toString()})(${pg.page-1})">‹</button>`;
    html += `<span class="pagination-info">Page ${pg.page} of ${pg.total_pages}</span>`;
    html += `<button ${pg.page===pg.total_pages?'disabled':''} onclick="(${cb.toString()})(${pg.page+1})">›</button>`;
    el.innerHTML = html;
  }

  function esc(s) { return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

  return { init, load, toggleUser };
})();
