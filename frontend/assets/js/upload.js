/**
 * Accounts360tech — Upload Module
 */

const Upload = (() => {
  let stagedFiles = [];

  function init() {
    const dropZone  = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-input');
    const btnUpload = document.getElementById('btn-upload-all');

    dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('over'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('over'));
    dropZone.addEventListener('drop', e => {
      e.preventDefault();
      dropZone.classList.remove('over');
      stageFiles([...e.dataTransfer.files]);
    });
    dropZone.addEventListener('click', e => {
      if (e.target.tagName !== 'LABEL') fileInput.click();
    });
    fileInput.addEventListener('change', () => {
      stageFiles([...fileInput.files]);
      fileInput.value = '';
    });
    btnUpload.addEventListener('click', uploadAll);
  }

  function stageFiles(files) {
    const allowed = ['application/pdf', 'image/jpeg', 'image/png'];
    const maxSize = 20 * 1024 * 1024;
    files.forEach(f => {
      if (!allowed.includes(f.type)) { Toast.show(`${f.name}: unsupported type.`, 'error'); return; }
      if (f.size > maxSize)          { Toast.show(`${f.name}: exceeds 20 MB.`, 'error'); return; }
      if (stagedFiles.length >= 10)  { Toast.show('Maximum 10 files per batch.', 'error'); return; }
      stagedFiles.push({ file: f, status: 'staged', progress: 0, id: null });
    });
    renderQueue();
  }

  function renderQueue() {
    const queue    = document.getElementById('upload-queue');
    const list     = document.getElementById('upload-file-list');
    const noFiles  = stagedFiles.length === 0;
    queue.classList.toggle('hidden', noFiles);
    list.innerHTML = '';
    stagedFiles.forEach((item, idx) => {
      const ext  = item.file.name.split('.').pop().toUpperCase();
      const size = formatSize(item.file.size);
      const div  = document.createElement('div');
      div.className = 'upload-file-item';
      div.innerHTML = `
        <div class="file-icon">${ext}</div>
        <div class="file-meta">
          <div class="file-name">${esc(item.file.name)}</div>
          <div class="file-size">${size}</div>
          <div class="file-progress-bar"><div class="file-progress-fill" style="width:${item.progress}%" id="prog-${idx}"></div></div>
        </div>
        <span class="file-status-badge status-${item.status}">${statusLabel(item.status)}</span>
        ${item.status === 'staged' ? `<button class="btn btn-ghost btn-sm" onclick="Upload.removeFile(${idx})">✕</button>` : ''}
      `;
      list.appendChild(div);
    });
  }

  function removeFile(idx) {
    stagedFiles.splice(idx, 1);
    renderQueue();
  }

  async function uploadAll() {
    const toUpload = stagedFiles.filter(f => f.status === 'staged');
    if (!toUpload.length) return;

    const formData = new FormData();
    toUpload.forEach(item => formData.append('files[]', item.file));
    toUpload.forEach(item => item.status = 'processing');
    renderQueue();

    // Simulate progress for UX
    const progressInterval = setInterval(() => {
      toUpload.forEach((item, i) => {
        if (item.progress < 90) { item.progress += 10; }
        const bar = document.getElementById(`prog-${stagedFiles.indexOf(item)}`);
        if (bar) bar.style.width = item.progress + '%';
      });
    }, 150);

    const res = await API.uploadDocuments(formData);
    clearInterval(progressInterval);

    if (!res?.success) {
      toUpload.forEach(item => item.status = 'error');
      Toast.show(res?.error?.message || 'Upload failed.', 'error');
    } else {
      toUpload.forEach(item => { item.progress = 100; item.status = 'done'; });
      Toast.show(`${res.data.uploaded} file(s) uploaded. AI processing started.`, 'success');
      setTimeout(() => {
        stagedFiles = stagedFiles.filter(f => f.status !== 'done');
        renderQueue();
        loadRecent();
      }, 1500);
    }
    renderQueue();
  }

  async function loadRecent() {
    const res = await API.listDocuments({ per_page: 8, sort_by: 'created_at', sort_dir: 'desc' });
    const list = document.getElementById('recent-list');
    if (!res?.success || !res.data.documents.length) {
      list.innerHTML = '<div class="empty-state">No uploads yet. Start by dropping a file above.</div>';
      return;
    }
    list.innerHTML = res.data.documents.map(doc => `
      <div class="recent-item" onclick="Review.openDetail(${doc.id}); App.switchView('review')">
        <div class="file-icon">${fileExt(doc.file_name)}</div>
        <div class="file-meta">
          <div class="file-name">${esc(doc.file_name)}</div>
          <div class="file-size">${doc.supplier_name ? esc(doc.supplier_name) : 'Processing…'} ${doc.document_date ? '· ' + doc.document_date : ''}</div>
        </div>
        <span class="file-status-badge status-${doc.status}">${statusLabel(doc.status)}</span>
      </div>
    `).join('');

    // Update review badge
    const pending = res.data.documents.filter(d => d.status === 'ready' && !d.is_approved).length;
    const badge = document.getElementById('review-badge');
    if (pending > 0) { badge.textContent = pending; badge.style.display = 'inline-block'; }
    else { badge.style.display = 'none'; }
  }

  function formatSize(bytes) {
    if (bytes < 1024)       return bytes + ' B';
    if (bytes < 1048576)    return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
  }

  function statusLabel(s) {
    return { staged:'Staged', processing:'Uploading', done:'Uploaded', pending:'Pending', ready:'Ready', error:'Error', archived:'Archived' }[s] || s;
  }

  function fileExt(name) { return (name || '').split('.').pop().toUpperCase().slice(0,4); }
  function esc(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

  return { init, loadRecent, removeFile };
})();
