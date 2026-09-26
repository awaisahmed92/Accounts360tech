/**
 * Accounts360tech — API Client
 * Wraps all fetch() calls to the PHP backend.
 */

const API = (() => {
  // Auto-detect: production domain uses /backend/public path; local WAMP uses sub-path
  const _origin = window.location.origin;
  const _isLocal = _origin.includes('localhost') || _origin.includes('127.0.0.1');
  const BASE = _isLocal
    ? 'http://localhost/Accounts360tech/backend/public/api/v1'
    : _origin + '/backend/public/api/v1';

  let _token        = localStorage.getItem('token')        || null;
  let _refreshToken = localStorage.getItem('refresh_token') || null;
  let _refreshing   = false;
  let _refreshQueue = [];

  function setTokens(token, refreshToken) {
    _token        = token;
    _refreshToken = refreshToken;
    if (token)        localStorage.setItem('token',         token);
    if (refreshToken) localStorage.setItem('refresh_token', refreshToken);
  }

  function clearTokens() {
    _token = _refreshToken = null;
    localStorage.removeItem('token');
    localStorage.removeItem('refresh_token');
    localStorage.removeItem('user');
  }

  function getToken()        { return _token; }
  function getRefreshToken() { return _refreshToken; }

  async function tryRefresh() {
    if (!_refreshToken) return false;
    try {
      const res = await fetch(`${BASE}/auth/refresh`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refresh_token: _refreshToken }),
      });
      if (!res.ok) { clearTokens(); return false; }
      const data = await res.json();
      _token = data.data.token;
      localStorage.setItem('token', _token);
      return true;
    } catch {
      clearTokens();
      return false;
    }
  }

  async function request(method, path, body = null, isFormData = false) {
    const headers = {};
    if (_token) headers['Authorization'] = `Bearer ${_token}`;
    if (!isFormData && body) headers['Content-Type'] = 'application/json';

    const opts = { method, headers };
    if (body) opts.body = isFormData ? body : JSON.stringify(body);

    let res = await fetch(`${BASE}${path}`, opts);

    // Auto-refresh on 401
    if (res.status === 401 && _refreshToken) {
      if (_refreshing) {
        await new Promise(r => _refreshQueue.push(r));
      } else {
        _refreshing = true;
        const ok = await tryRefresh();
        _refreshing = false;
        _refreshQueue.forEach(r => r());
        _refreshQueue = [];
        if (!ok) { App.logout(); return null; }
      }
      headers['Authorization'] = `Bearer ${_token}`;
      opts.headers = headers;
      res = await fetch(`${BASE}${path}`, opts);
    }

    if (res.status === 204) return { success: true };
    if (res.headers.get('Content-Type')?.includes('text/csv')) {
      return res; // return raw response for CSV download
    }
    return res.json();
  }

  // ── Auth ──────────────────────────────────────────────────
  async function login(email, password)         { return request('POST', '/auth/login', { email, password }); }
  async function register(name, email, password){ return request('POST', '/auth/register', { name, email, password }); }
  async function registerTenant(payload)        { return request('POST', '/tenants/register', payload); }
  async function checkSubdomain(code)           { return request('GET', `/tenants/check-subdomain?s=${encodeURIComponent(code)}`); }
  async function logout(refreshToken)           { return request('POST', '/auth/logout', { refresh_token: refreshToken }); }
  async function forgotPassword(email)          { return request('POST', '/auth/forgot-password', { email }); }

  // ── Documents ─────────────────────────────────────────────
  async function uploadDocuments(formData)      { return request('POST', '/documents/upload', formData, true); }
  async function listDocuments(params = {})     { return request('GET', `/documents?${toQuery(params)}`); }
  async function getDocument(id)                { return request('GET', `/documents/${id}`); }
  async function updateDocument(id, data)       { return request('PATCH', `/documents/${id}`, data); }
  async function approveDocument(id)            { return request('POST', `/documents/${id}/approve`); }
  async function archiveDocument(id, reason='') { return request('POST', `/documents/${id}/archive`, { reason }); }
  async function deleteDocument(id)             { return request('DELETE', `/documents/${id}`); }
  async function downloadDocument(id)           { return request('GET', `/documents/${id}/download`); }
  async function exportDocuments(params = {})   { return request('GET', `/documents/export?${toQuery(params)}`); }

  // ── Admin ──────────────────────────────────────────────────
  async function adminUsers(params = {})        { return request('GET', `/admin/users?${toQuery(params)}`); }
  async function adminUpdateUser(id, data)      { return request('PATCH', `/admin/users/${id}`, data); }
  async function adminLogs(params = {})         { return request('GET', `/admin/logs?${toQuery(params)}`); }
  async function adminStats()                   { return request('GET', '/admin/stats'); }

  // ── Accounts (Chart of Accounts) ──────────────────────────
  async function listAccounts(params = {})      { return request('GET', `/accounts?${toQuery(params)}`); }
  async function createAccount(data)            { return request('POST', '/accounts', data); }
  async function updateAccount(id, data)        { return request('PATCH', `/accounts/${id}`, data); }
  async function deleteAccount(id)              { return request('DELETE', `/accounts/${id}`); }

  // ── Journal Entries ────────────────────────────────────────
  async function listJournalEntries(params = {}){ return request('GET', `/journal?${toQuery(params)}`); }
  async function createJournalEntry(data)       { return request('POST', '/journal', data); }
  async function getJournalEntry(id)            { return request('GET', `/journal/${id}`); }
  async function updateJournalEntry(id, data)   { return request('PATCH', `/journal/${id}`, data); }
  async function postJournalEntry(id)           { return request('POST', `/journal/${id}/post`); }
  async function deleteJournalEntry(id)         { return request('DELETE', `/journal/${id}`); }

  // ── Bank Accounts ──────────────────────────────────────────
  async function listBanks()                    { return request('GET', '/banks'); }
  async function createBank(data)               { return request('POST', '/banks', data); }
  async function getBankTransactions(id, params){ return request('GET', `/banks/${id}/transactions?${toQuery(params)}`); }
  async function importBankCsv(id, formData)    { return request('POST', `/banks/${id}/import`, formData, true); }
  async function reconcileBank(id, data)        { return request('POST', `/banks/${id}/reconcile`, data); }

  // ── Reports ────────────────────────────────────────────────
  async function getProfitLoss(params = {})     { return request('GET', `/reports/profit-loss?${toQuery(params)}`); }
  async function getBalanceSheet(params = {})   { return request('GET', `/reports/balance-sheet?${toQuery(params)}`); }
  async function getTrialBalance(params = {})   { return request('GET', `/reports/trial-balance?${toQuery(params)}`); }

  function toQuery(params) {
    return Object.entries(params).filter(([,v]) => v !== '' && v !== null && v !== undefined)
      .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&');
  }

  function getBaseUrl() { return BASE; }

  return {
    setTokens, clearTokens, getToken, getRefreshToken, getBaseUrl,
    login, register, registerTenant, checkSubdomain, logout, forgotPassword,
    uploadDocuments, listDocuments, getDocument, updateDocument,
    approveDocument, archiveDocument, deleteDocument, downloadDocument, exportDocuments,
    adminUsers, adminUpdateUser, adminLogs, adminStats,
    listAccounts, createAccount, updateAccount, deleteAccount,
    listJournalEntries, createJournalEntry, getJournalEntry, updateJournalEntry, postJournalEntry, deleteJournalEntry,
    listBanks, createBank, getBankTransactions, importBankCsv, reconcileBank,
    getProfitLoss, getBalanceSheet, getTrialBalance,
  };
})();
