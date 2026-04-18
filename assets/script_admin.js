// ========== 状态与工具 ==========
let plState = [];
let jsonSyncing = false;
let guiSyncing = false;
let currentSettings = {};
let isSortMode = false;
let dragState = { indices: [], isDragging: false, lastY: 0 };
let placeholderRow = null;
// 修复批量上传计数器
let uploadBatch = { total: 0, completed: 0 };

const esc = (s) => String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]||m));

function showToast(msg, type = 'success', options = {}) {
    const container = document.getElementById('toast-container');
    if (!container) return;
    const toastEl = document.createElement('div');
    toastEl.className = `toast align-items-center text-white bg-${type} border-0 mb-2`;
    toastEl.setAttribute('role', 'alert');
    toastEl.innerHTML = `<div class="d-flex"><div class="toast-body">${esc(msg)}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    container.appendChild(toastEl);

    const bsOptions = { delay: 2500, autohide: true, ...options };
    const bsToast = new bootstrap.Toast(toastEl, bsOptions);
    bsToast.show();
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove(), { once: true });
    return bsToast;
}

// ========== 渲染与同步 ==========
function renderTable() {
    const tbody = document.getElementById('playlistBody');
    const empty = document.getElementById('emptyState');
    if (!tbody || !empty) return;
    tbody.innerHTML = '';
    if (!plState.length) { empty.classList.remove('d-none'); return; }
    empty.classList.add('d-none');
    plState.forEach((item, i) => {
        const tr = document.createElement('tr');
        tr.setAttribute('data-idx', i);
        const actionsHtml = isSortMode
            ? `<span class="drag-handle text-muted"><i class="bi bi-grip-vertical fs-5"></i></span>`
            : `<button class="btn btn-sm btn-outline-primary me-1" onclick="editTrack(${i})"><i class="bi bi-pencil"></i></button><button class="btn btn-sm btn-outline-danger" onclick="removeTrack(${i})"><i class="bi bi-trash"></i></button>`;

        tr.innerHTML = `
            <td><input type="checkbox" class="form-check-input pl-check" data-idx="${i}"></td>
            <td class="fw-medium text-truncate" style="max-width:140px" title="${esc(item.name||'')}">${esc(item.name || '未知曲目')}</td>
            <td class="text-muted text-truncate" style="max-width:120px" title="${esc(item.artist||'')}">${esc(item.artist || '未知歌手')}</td>
            <td class="d-none d-md-table-cell font-monospace small text-truncate" title="${esc(item.url||'')}">${esc(item.url || '')}</td>
            <td class="d-none d-md-table-cell font-monospace small text-truncate" title="${esc(item.lrc||'')}">${esc(item.lrc || '')}</td>
            <td class="d-none d-md-table-cell font-monospace small text-truncate" title="${esc(item.pic||'')}">${esc(item.pic || '')}</td>
            <td class="text-nowrap sticky-col align-middle">${actionsHtml}</td>`;
        tbody.appendChild(tr);
        if (isSortMode) initDragEvents(tr);
    });
    syncToJSON();
}

function toggleSortMode() {
    isSortMode = !isSortMode;
    const btn = document.getElementById('sortToggleBtn');
    if (!btn) return;
    btn.innerHTML = isSortMode ? `<i class="bi bi-check-lg"></i> 确认排序` : `<i class="bi bi-arrow-down-up"></i> 顺序编辑`;
    btn.classList.toggle('btn-warning', isSortMode);
    btn.classList.toggle('btn-outline-secondary', !isSortMode);
    renderTable();
}

function initDragEvents(row) {
    const handle = row.querySelector('.drag-handle');
    if (!handle) return;
    handle.addEventListener('mousedown', e => startDrag(e, row));
    handle.addEventListener('touchstart', e => startDrag(e, row), {passive: false});
}

function startDrag(e, row) {
    e.preventDefault();
    const idx = parseInt(row.dataset.idx);
    const checks = [...document.querySelectorAll('.pl-check:checked')].map(c => parseInt(c.dataset.idx));
    dragState.indices = (checks.includes(idx) && checks.length > 1) ? checks.sort((a,b)=>a-b) : [idx];
    dragState.isDragging = true;
    dragState.lastY = e.clientY ?? e.touches?.[0]?.clientY;
    window._dragToastInstance = showToast(`正在拖动 ${dragState.indices.length} 首曲目`, 'info', { autohide: false, delay: 0 });

    dragState.indices.forEach(i => {
        const tr = document.querySelector(`#playlistBody tr[data-idx="${i}"]`);
        if (tr) tr.classList.add('dragging-picked'); 
    });

    if (!placeholderRow) {
        placeholderRow = document.createElement('tr');
        placeholderRow.className = 'drag-placeholder';
        placeholderRow.innerHTML = `<td colspan="7" class="drag-placeholder-text text-primary"><i class="bi bi-arrow-down-circle me-2"></i>释放以放置于此</td>`;
    }

    document.addEventListener('mousemove', onDragMove);
    document.addEventListener('mouseup', onDragEnd);
    document.addEventListener('touchmove', onDragMove, {passive: false});
    document.addEventListener('touchend', onDragEnd);
}

function onDragMove(e) {
    if (!dragState.isDragging) return;
    e.preventDefault();
    const clientY = e.clientY ?? e.touches?.[0]?.clientY;
    if (Math.abs(clientY - dragState.lastY) < 5) return;
    dragState.lastY = clientY;

    const tbody = document.getElementById('playlistBody');
    const rows = [...tbody.querySelectorAll('tr:not(.dragging-picked):not(.drag-placeholder)')];
    let targetRow = null;
    for (const row of rows) {
        const rect = row.getBoundingClientRect();
        if (clientY < rect.top + rect.height / 2) { targetRow = row; break; }
    }
    if (targetRow) tbody.insertBefore(placeholderRow, targetRow);
    else tbody.appendChild(placeholderRow);
}

function onDragEnd(e) {
    document.removeEventListener('mousemove', onDragMove);
    document.removeEventListener('mouseup', onDragEnd);
    document.removeEventListener('touchmove', onDragMove);
    document.removeEventListener('touchend', onDragEnd);
    if (window._dragToastInstance) { window._dragToastInstance.hide(); window._dragToastInstance = null; }

    dragState.indices.forEach(i => {
        const tr = document.querySelector(`#playlistBody tr[data-idx="${i}"]`);
        if (tr) tr.classList.remove('dragging-picked'); 
    });

    if (placeholderRow && placeholderRow.parentNode) {
        const allRows = [...document.querySelectorAll('#playlistBody tr')];
        let dropIndex = 0;
        for (const r of allRows) { if (r === placeholderRow) break; if (r.hasAttribute('data-idx')) dropIndex++; }

        const count = dragState.indices.length;
        const movedItems = dragState.indices.map(i => plState[i]);
        [...dragState.indices].sort((a,b)=>b-a).forEach(i => plState.splice(i, 1));
        
        const originalMin = Math.min(...dragState.indices);
        if (originalMin < dropIndex) dropIndex -= count;
        dropIndex = Math.max(0, Math.min(plState.length, dropIndex));
        plState.splice(dropIndex, 0, ...movedItems);
        renderTable(); syncToJSON(); showToast('顺序已更新', 'info');
    }
    if (placeholderRow) { placeholderRow.remove(); placeholderRow = null; }
    dragState.isDragging = false; dragState.indices = [];
}

function syncToJSON() {
    if (guiSyncing) return; jsonSyncing = true;
    const editor = document.getElementById('jsonEditor');
    if (editor) editor.value = JSON.stringify(plState, null, 2);
    jsonSyncing = false;
}
function syncFromJSON() {
    if (jsonSyncing) return;
    try {
        const parsed = JSON.parse(document.getElementById('jsonEditor').value);
        if (!Array.isArray(parsed)) throw new Error('根元素必须是数组');
        guiSyncing = true;
        plState = parsed.map(i => ({name:i.name||'',artist:i.artist||'',url:i.url||'',pic:i.pic||'',lrc:i.lrc||''}));
        renderTable(); guiSyncing = false;
    } catch(e) { /* 忽略无效 JSON 输入 */ }
}

// ========== 增删改查 ==========
function openAddModal() {
    document.getElementById('trackModalTitle').textContent = '添加曲目';
    document.getElementById('editIndex').value = '-1';
    ['inpName','inpArtist','inpUrl','inpLrc','inpPic'].forEach(id => document.getElementById(id).value = '');
    new bootstrap.Modal(document.getElementById('trackModal')).show();
}
function editTrack(i) {
    const item = plState[i];
    document.getElementById('trackModalTitle').textContent = '编辑曲目';
    document.getElementById('editIndex').value = i;
    document.getElementById('inpName').value = item.name||'';
    document.getElementById('inpArtist').value = item.artist||'';
    document.getElementById('inpUrl').value = item.url||'';
    document.getElementById('inpLrc').value = item.lrc||'';
    document.getElementById('inpPic').value = item.pic||'';
    new bootstrap.Modal(document.getElementById('trackModal')).show();
}
function saveTrack() {
    const idx = parseInt(document.getElementById('editIndex').value);
    const defaults = currentSettings || {};
    const newItem = {
        name: document.getElementById('inpName').value.trim() || defaults.default_title || '未知曲目',
        artist: document.getElementById('inpArtist').value.trim() || defaults.default_artist || '未知歌手',
        url: document.getElementById('inpUrl').value.trim() || defaults.default_url || '',
        lrc: document.getElementById('inpLrc').value.trim() || defaults.default_lrc || '',
        pic: document.getElementById('inpPic').value.trim() || defaults.default_cover || ''
    };
    if (idx === -1) plState.push(newItem); else plState[idx] = newItem;
    bootstrap.Modal.getInstance(document.getElementById('trackModal')).hide();
    renderTable(); showToast(idx === -1 ? '已添加' : '已更新');
}
function removeTrack(i) {
    if (!confirm('确定移除该曲目？')) return;
    plState.splice(i, 1); renderTable();
}
function batchDelete() {
    const checks = [...document.querySelectorAll('.pl-check:checked')];
    if (!checks.length) return showToast('请先勾选曲目', 'warning');
    if (!confirm(`确定删除选中的 ${checks.length} 首曲目？`)) return;
    const idxs = checks.map(c => parseInt(c.dataset.idx)).sort((a,b)=>b-a);
    idxs.forEach(i => plState.splice(i, 1)); renderTable(); showToast('已批量删除');
}
function savePlaylist() {
    const currentPlaylist = document.querySelector('select[name="playlist"] option:checked')?.value || 'default';
    fetch(`?ajax=1&action=save_playlist&playlist=${encodeURIComponent(currentPlaylist)}`, {
        method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({data: plState})
    }).then(r=>r.json()).then(res=>{ if(res.success) showToast(res.msg); else showToast(res.msg, 'danger'); }).catch(e=>showToast('保存失败: ' + e.message, 'danger'));
}

// ========== 智能添加 ==========
async function autoAddTracks() {
    try {
        const res = await fetch(`?ajax=1&action=scan_files`).then(r=>r.json());
        if (!res.success) return showToast(res.msg, 'danger');
        const {audio, lrc, cover} = res.data;
        if (!audio.length) return showToast('未找到音频文件', 'warning');
        const newTracks = [];
        audio.forEach(aPath => {
            const base = aPath.replace(/\.[^/.]+$/, "");
            const fileName = base.split('/').pop();
            const matchLrc = lrc.find(f => f.replace(/\.[^/.]+$/, "") === base) || '';
            const matchCover = cover.find(f => f.replace(/\.[^/.]+$/, "") === base) || '';
            
            let name = currentSettings.default_title || '未知曲目';
            let artist = currentSettings.default_artist || '未知歌手';
            const sep = currentSettings.parse_separator || '-';
            const fmt = currentSettings.parse_format || 'title_artist';
            const parts = fileName.split(sep).map(s=>s.trim());
            if (parts.length >= 2) [name, artist] = fmt === 'title_artist' ? [parts[0], parts[1]] : [parts[1], parts[0]];
            
            newTracks.push({
                name, artist,
                url: `./localmedia/${aPath}`,
                lrc: matchLrc ? `./localmedia/${matchLrc}` : (currentSettings.default_lrc || ''),
                pic: matchCover ? `./localmedia/${matchCover}` : (currentSettings.default_cover || '')
            });
        });
        await handleDuplicateBatch(newTracks);
    } catch(e) { showToast('智能添加失败: ' + e.message, 'danger'); }
}

// ========== JSON 导入导出 ==========
function exportJson() {
    const blob = new Blob([JSON.stringify(plState, null, 2)], {type:'application/json'});
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
    a.download = (document.querySelector('select[name="playlist"] option:checked')?.textContent || 'playlist') + '.json';
    a.click();
}
function importJson() {
    const input = document.createElement('input'); input.type='file'; input.accept='.json';
    input.onchange = e => {
        const f = e.target.files[0]; if(!f) return;
        const r = new FileReader();
        r.onload = async () => {
            try {
                const d = JSON.parse(r.result);
                if(Array.isArray(d)) {
                    const formatted = d.map(i => ({name: i.name || '', artist: i.artist || '', url: i.url || '', pic: i.pic || '', lrc: i.lrc || ''}));
                    await handleDuplicateBatch(formatted);
                } else showToast('JSON 根元素必须是数组', 'danger');
            } catch { showToast('JSON 格式无效', 'danger'); }
        };
        r.readAsText(f);
    };
    input.click();
}

// ========== 重复项处理 ==========
function normalizePlaylistNames() {
    const seen = {};
    plState.forEach(item => {
        if (!item.name) item.name = currentSettings.default_title || '未知曲目';
        let name = item.name;
        if (seen[name]) {
            let counter = 1;
            let newName = `${name} (${counter})`;
            while (seen[newName]) counter++, newName = `${name} (${counter})`;
            item.name = newName; seen[newName] = true;
        } else seen[name] = true;
    });
}
function askDuplicateAction() {
    return new Promise(resolve => {
        const modalEl = document.getElementById('duplicateModal');
        if (!modalEl) return resolve('cancel');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        const btnOver = document.getElementById('btnDupOverwrite');
        const btnMerge = document.getElementById('btnDupMerge');
        const btnCancel = document.getElementById('btnDupCancel');

        btnOver.replaceWith(btnOver.cloneNode(true));
        btnMerge.replaceWith(btnMerge.cloneNode(true));
        btnCancel.replaceWith(btnCancel.cloneNode(true));
        
        const newOver = document.getElementById('btnDupOverwrite');
        const newMerge = document.getElementById('btnDupMerge');
        const newCancel = document.getElementById('btnDupCancel');

        newOver.onclick = () => { modal.hide(); resolve('overwrite'); };
        newMerge.onclick = () => { modal.hide(); resolve('merge'); };
        newCancel.onclick = () => { modal.hide(); resolve('cancel'); };
        modalEl.addEventListener('hidden.bs.modal', () => resolve('cancel'), { once: true });
        modal.show();
    });
}
async function handleDuplicateBatch(newItems) {
    if (!newItems.length) return;
    const existingUrls = new Set(plState.map(i => i.url).filter(Boolean));
    const hasConflict = newItems.some(ni => existingUrls.has(ni.url));
    let action = 'merge';
    if (hasConflict) action = await askDuplicateAction();
    if (action === 'cancel') return showToast('已取消操作', 'warning');

    if (action === 'overwrite') plState = newItems;
    else {
        const toAdd = newItems.filter(ni => !existingUrls.has(ni.url));
        plState.push(...toAdd);
    }
    normalizePlaylistNames(); renderTable();
    showToast(action === 'overwrite' ? '已覆盖更新' : '已去重合并');
}

// ========== 备份管理 ==========
async function showBackups() {
    const modal = new bootstrap.Modal(document.getElementById('backupModal')); modal.show();
    const list = document.getElementById('backupList'); list.innerHTML = '<div class="text-muted">加载中...</div>';
    try {
        const res = await fetch(`?ajax=1&action=list_backups`).then(r=>r.json());
        if(!res.success) return list.innerHTML = `<div class="text-muted">${esc(res.msg)}</div>`;
        list.innerHTML = res.data.length ? res.data.map(f => 
            `<div class="list-group-item d-flex justify-content-between align-items-center"><span class="small text-truncate" style="max-width:50%">${esc(f)}</span>
            <div class="d-flex gap-1"><button class="btn btn-sm btn-outline-primary" onclick="restoreBackup('${esc(f)}')">恢复</button>
            <button class="btn btn-sm btn-outline-secondary" onclick="downloadBackup('${esc(f)}')">下载</button>
            <button class="btn btn-sm btn-outline-danger" onclick="deleteBackup('${esc(f)}')">删除</button></div></div>`
        ).join('') : '<div class="text-muted">暂无备份</div>';
    } catch { list.innerHTML = '<div class="text-muted">请求失败</div>'; }
}
function createBackup() {
    const fd = new FormData();
    fd.append('playlist_name', document.querySelector('select[name="playlist"] option:checked')?.textContent || 'default');
    fd.append('playlist_data', JSON.stringify(plState));
    fetch(`?ajax=1&action=backup_playlist`, {method:'POST', body:fd}).then(r=>r.json()).then(res=>{ if(res.success) { showToast(res.msg); showBackups(); } else showToast(res.msg, 'danger'); });
}
function restoreBackup(file) {
    if(!confirm('恢复将覆盖当前歌单数据，是否继续？')) return;
    const fd = new FormData(); fd.append('file', file);
    fetch(`?ajax=1&action=restore_backup`, {method:'POST', body:fd}).then(r=>r.json()).then(res=>{
        if(res.success) { try { plState = JSON.parse(res.data).map(i=>({...i})); renderTable(); bootstrap.Modal.getInstance(document.getElementById('backupModal')).hide(); showToast('恢复成功'); } catch { showToast('备份文件损坏', 'danger'); } }
        else showToast(res.msg, 'danger');
    });
}
function downloadBackup(file) {
    const fd = new FormData(); fd.append('file', file);
    fetch(`?ajax=1&action=restore_backup`, {method:'POST', body:fd}).then(r=>r.json()).then(res=>{
        if(res.success) { const b=new Blob([res.data],{type:'application/json'}); const a=document.createElement('a'); a.href=URL.createObjectURL(b); a.download=file; a.click(); }
    });
}
function deleteBackup(file) {
    if(!confirm(`确定删除备份【${file}】？此操作不可恢复。`)) return;
    const fd = new FormData(); fd.append('file', file);
    fetch(`?ajax=1&action=delete_backup`, {method:'POST', body:fd}).then(r=>r.json()).then(res=>{ if(res.success) { showToast(res.msg); showBackups(); } else showToast(res.msg, 'danger'); });
}

// ========== 设置管理 ==========
async function loadSettings() {
    try { const res = await fetch(`?ajax=1&action=get_settings`).then(r=>r.json()); if(res.success) currentSettings = res.data || {}; } catch {}
    const f = document.getElementById('settingsForm'); if (!f) return;
    f.playlist_dir.value = currentSettings.playlist_dir || ''; f.localmedia_dir.value = currentSettings.localmedia_dir || '';
    f.default_cover.value = currentSettings.default_cover || ''; f.default_lrc.value = currentSettings.default_lrc || '';
    f.default_url.value = currentSettings.default_url || ''; f.default_title.value = currentSettings.default_title || '';
    f.default_artist.value = currentSettings.default_artist || ''; f.parse_separator.value = currentSettings.parse_separator || '';
    f.parse_format.value = currentSettings.parse_format || 'title_artist';
}
function saveSettings() {
    const f = document.getElementById('settingsForm');
    const data = {
        playlist_dir: f.playlist_dir.value.trim() || './playlists', localmedia_dir: f.localmedia_dir.value.trim() || './localmedia',
        default_cover: f.default_cover.value.trim(), default_lrc: f.default_lrc.value.trim(), default_url: f.default_url.value.trim(),
        default_title: f.default_title.value.trim(), default_artist: f.default_artist.value.trim(),
        parse_separator: f.parse_separator.value.trim(), parse_format: f.parse_format.value
    };
    fetch(`?ajax=1&action=save_settings`, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(data)})
    .then(r=>r.json()).then(res=>{ if(res.success) { currentSettings = data; showToast(res.msg); } else showToast(res.msg, 'danger'); });
}

// ========== 歌单文件管理 ==========
function createPlaylist() {
    const name = prompt('请输入新歌单名称（仅支持字母、数字、-、_）:');
    if (!name) return;
    const fd = new FormData(); fd.append('name', name);
    fetch(`?ajax=1&action=create_playlist`, {method:'POST', body:fd}).then(r=>r.json()).then(res=>{ if(res.success) { showToast(res.msg); setTimeout(()=>location.search=`?tab=playlist&playlist=${res.name}`, 500); } else showToast(res.msg, 'danger'); }).catch(()=>showToast('请求失败', 'danger'));
}
function renamePlaylist(oldName) {
    const newName = prompt('请输入新名称:', oldName);
    if (!newName || newName === oldName) return;
    const fd = new FormData(); fd.append('old', oldName); fd.append('new', newName);
    fetch(`?ajax=1&action=rename_playlist`, {method:'POST', body:fd}).then(r=>r.json()).then(res=>{ if(res.success) { showToast(res.msg); setTimeout(()=>location.search=`?tab=playlist&playlist=${res.new_name}`, 500); } else showToast(res.msg, 'danger'); }).catch(()=>showToast('请求失败', 'danger'));
}
function deletePlaylist(name) {
    if (!confirm(`确定删除歌单【${name}】？此操作不可恢复。`)) return;
    const fd = new FormData(); fd.append('name', name);
    fetch(`?ajax=1&action=delete_playlist`, {method:'POST', body:fd}).then(r=>r.json()).then(res=>{ if(res.success) { showToast(res.msg); setTimeout(()=>location.href='?tab=playlist', 500); } else showToast(res.msg, 'danger'); }).catch(()=>showToast('请求失败', 'danger'));
}

// ========== 文件管理 ==========
document.getElementById('fmSelectAll')?.addEventListener('change', function() {
    document.querySelectorAll('input[name="sel[]"]').forEach(c => c.checked = this.checked);
});

function fmBatch(action) {
    const sel = [...document.querySelectorAll('input[name="sel[]"]:checked')].map(c => c.value);
    if (sel.length === 0) return showToast('请先勾选项目', 'warning');
    if (action === 'delete') {
        if (!confirm('确定删除选中项？此操作不可恢复。')) return;
        const fd = new FormData(); fd.append('items', JSON.stringify(sel));
        const curDir = document.querySelector('#fmForm input[name="fm_dir"]').value;
        fetch(`?ajax=1&fm_dir=${encodeURIComponent(curDir)}&action=delete`, {method:'POST', body:fd}).then(r=>r.json()).then(res=>{ showToast(res.msg); if(res.success) location.reload(); });
    } else if (action === 'download') {
        const form = document.createElement('form'); form.method = 'POST'; form.action = '';
        const dirInput = document.createElement('input'); dirInput.type = 'hidden'; dirInput.name = 'batch_download_dir'; dirInput.value = document.querySelector('#fmForm input[name="fm_dir"]').value;
        const itemsInput = document.createElement('input'); itemsInput.type = 'hidden'; itemsInput.name = 'batch_download_items'; itemsInput.value = JSON.stringify(sel);
        form.appendChild(dirInput); form.appendChild(itemsInput); document.body.appendChild(form); form.submit();
    }
}

document.querySelectorAll('.rename-btn').forEach(btn => btn.addEventListener('click', function() {
    const old = this.dataset.name;
    const newName = prompt('输入新名称:', old);
    if (newName && newName !== old) {
        const fd = new FormData(); fd.append('old', old); fd.append('new', newName);
        const curDir = document.querySelector('#fmForm input[name="fm_dir"]').value;
        fetch(`?ajax=1&fm_dir=${encodeURIComponent(curDir)}&action=rename`, {method:'POST', body:fd}).then(r=>r.json()).then(res=>{ showToast(res.msg); if(res.success) location.reload(); });
    }
}));

// 修复独立删除按钮的事件绑定问题
document.addEventListener('click', function(e) {
    const deleteBtn = e.target.closest('.delete-btn');
    if (deleteBtn) {
        const name = deleteBtn.dataset.name;
        if (!confirm(`确定删除【${name}】？此操作不可恢复。`)) return;
        const fd = new FormData(); fd.append('items', JSON.stringify([name]));
        const curDir = document.querySelector('#fmForm input[name="fm_dir"]').value;
        fetch(`?ajax=1&fm_dir=${encodeURIComponent(curDir)}&action=delete`, {method:'POST', body:fd})
        .then(r=>r.json()).then(res=>{ if(res.success) { showToast(res.msg); location.reload(); } else showToast(res.msg, 'danger'); });
    }
});

function createFolder() {
    const input = document.getElementById('newFolderName');
    const name = input.value.trim();
    if (!name) return showToast('请输入文件夹名称', 'warning');
    const fd = new FormData(); fd.append('folder_name', name);
    const curDir = document.querySelector('#fmForm input[name="fm_dir"]').value;
    fetch(`?ajax=1&fm_dir=${encodeURIComponent(curDir)}&action=create_folder`, {method:'POST', body:fd}).then(r=>r.json()).then(res=>{ showToast(res.msg); if(res.success) { input.value = ''; location.reload(); } });
}

const dropZone = document.getElementById('drop-zone');
const fileInput = document.getElementById('fileInput');
const progressCont = document.getElementById('progress-container');

if (dropZone) {
    ['dragenter', 'dragover'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.add('border-primary', 'bg-primary-subtle'); }));
    ['dragleave', 'drop'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.remove('border-primary', 'bg-primary-subtle'); }));
    dropZone.addEventListener('drop', e => handleFiles(e.dataTransfer.files));
}
if (fileInput) fileInput.addEventListener('change', e => handleFiles(e.target.files));

function handleFiles(files) { 
    if (!files.length) return; 
    uploadBatch.total = files.length; 
    uploadBatch.completed = 0; 
    Array.from(files).forEach(uploadFile); 
}

function uploadFile(file) {
    if (!dropZone || !progressCont) return;
    const id = 'prog_' + Math.random().toString(36).substr(2);
    progressCont.insertAdjacentHTML('beforeend', `<div id="${id}" class="d-flex align-items-center gap-2 mb-1 small"><span class="text-truncate" style="max-width:50%">${esc(file.name)}</span><div class="progress flex-grow-1" style="height:6px"><div class="progress-bar" role="progressbar" style="width:0%"></div></div><span>0%</span></div>`);
    
    const fd = new FormData(); fd.append('fm_files', file);
    // 获取当前选中的冲突处理模式
    const modeRadio = document.querySelector('input[name="conflict_mode"]:checked');
    fd.append('conflict_mode', modeRadio ? modeRadio.value : 'overwrite');

    const xhr = new XMLHttpRequest();
    xhr.open('POST', `?ajax=1&fm_dir=${encodeURIComponent(document.querySelector('#fmForm input[name="fm_dir"]').value)}&action=upload`);
    xhr.upload.onprogress = e => {
        if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 100);
            const el = document.getElementById(id);
            if(el) { el.querySelector('.progress-bar').style.width = pct + '%'; el.querySelector('span:last-child').textContent = pct + '%'; }
        }
    };
    xhr.onload = () => {
        const el = document.getElementById(id);
        try {
            if (xhr.status === 200) {
                const res = JSON.parse(xhr.responseText);
                if(el) el.querySelector('span:last-child').textContent = res.success ? '完成' : '失败';
            } else { if(el) el.querySelector('span:last-child').textContent = `HTTP ${xhr.status}`; }
        } catch { if(el) el.querySelector('span:last-child').textContent = '解析错误'; }

        // 仅当本批次全部完成时才刷新
        uploadBatch.completed++;
        if (uploadBatch.completed === uploadBatch.total) {
            setTimeout(() => location.reload(), 600);
        }
    };
    xhr.onerror = () => { 
        const el = document.getElementById(id); if(el) el.querySelector('span:last-child').textContent = '网络错误';
        uploadBatch.completed++;
        if (uploadBatch.completed === uploadBatch.total) setTimeout(() => location.reload(), 600);
    };
    xhr.send(fd);
}

// ========== 初始化与事件绑定 ==========
document.addEventListener('DOMContentLoaded', () => {
    loadSettings();
    document.querySelectorAll('.copy-btn').forEach(b => b.addEventListener('click', function() {
        navigator.clipboard.writeText(this.dataset.url).then(() => {
            const o = this.innerHTML; this.innerHTML = '<i class="bi bi-check2"></i>'; setTimeout(() => this.innerHTML = o, 1000);
        });
    }));

    if (document.getElementById('playlistTable')) {
        try { const raw = document.getElementById('jsonEditor')?.value || '[]'; plState = JSON.parse(raw).map(i => ({...i})); } catch { plState = []; }
        renderTable();
        document.getElementById('jsonEditor')?.addEventListener('input', syncFromJSON);
        document.getElementById('plSelectAll')?.addEventListener('change', function() { document.querySelectorAll('.pl-check').forEach(c => c.checked = this.checked); });
    }
});