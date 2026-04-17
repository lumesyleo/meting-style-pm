<?php
// ================= 配置区域 =================
define('FM_ROOT', __DIR__ . '/assets');
define('PLAYLIST_DIR', __DIR__ . '/playlists');
define('SETTINGS_FILE', __DIR__ . '/settings.json');
define('BACKUP_DIR', PLAYLIST_DIR . '/backups');
define('ADMIN_USER', 'admin');
define('ADMIN_PASS_HASH', password_hash('123456', PASSWORD_DEFAULT));
define('INDEX_PAGE', 'index.html');

// 全局默认值配置（设置页未保存时自动 fallback 至此）
define('DEFAULT_COVER', './assets/default.jpg');
define('DEFAULT_LRC', './assets/default.lrc');
define('DEFAULT_TITLE', '未知曲目');
define('DEFAULT_ARTIST', '未知歌手');
define('PARSE_SEPARATOR', '-');
define('PARSE_FORMAT', 'title_artist'); // title_artist | artist_title
// ============================================
if (!class_exists('ZipArchive')) die('错误：服务器未启用 ZipArchive 扩展。');
session_start();

// ========== 安全工具函数 ==========
function fm_sanitize_path($rel_path) {
    $root = realpath(FM_ROOT);
    if (!$root) mkdir(FM_ROOT, 0755, true);
    $root = realpath(FM_ROOT);
    $target = $rel_path === '' ? $root : realpath($root . '/' . trim($rel_path, '/\\'));
    if (!$target || strpos($target, $root) !== 0) return false;
    return $target;
}
function fm_clean_name($name) {
    $name = basename($name);
    $clean = preg_replace('/[\\\\\/\?\*:|<>"\'\x00-\x1f]/u', '_', $name);
    return $clean ?: 'unnamed_file';
}
function fm_rmdir($dir) {
    if (!is_dir($dir)) return;
    $items = array_diff(scandir($dir), ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . '/' . $item;
        is_dir($path) ? fm_rmdir($path) : unlink($path);
    }
    rmdir($dir);
}
function esc_html($str) { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }

// ========== 初始化目录 ==========
foreach ([FM_ROOT, PLAYLIST_DIR, BACKUP_DIR] as $dir) if (!is_dir($dir)) mkdir($dir, 0755, true);

// ========== AJAX 路由 ==========
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $fm_cur = $_GET['fm_dir'] ?? '';
    $fm_path = fm_sanitize_path($fm_cur);
    $resp = ['success' => false, 'msg' => '未知操作'];
    try {
        $action = $_GET['action'] ?? '';
        if ($action === 'upload') {
            if (!$fm_path) throw new Exception('无效目录路径');
            $files = $_FILES['fm_files'] ?? [];
            if (empty($files['tmp_name'])) throw new Exception('未接收到文件数据');
            if (!is_array($files['tmp_name'])) {
                $files = ['tmp_name'=>[$files['tmp_name']], 'name'=>[$files['name']], 'error'=>[$files['error']], 'size'=>[isset($files['size'])?$files['size']:0]];
            }
            $count = 0;
            foreach ($files['tmp_name'] as $i => $tmp) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $dest = $fm_path . '/' . fm_clean_name($files['name'][$i]);
                    if (!move_uploaded_file($tmp, $dest)) throw new Exception("移动失败: " . basename($files['name'][$i]));
                    $count++;
                }
            }
            $resp = ['success'=>true, 'msg'=>"成功上传 {$count} 个文件"];
        } elseif ($action === 'rename') {
            if (!$fm_path) throw new Exception('无效目录路径');
            $old = fm_clean_name($_POST['old'] ?? ''); $new = fm_clean_name($_POST['new'] ?? '');
            if ($old === '' || $new === '') throw new Exception('文件名不能为空');
            $src = $fm_path . '/' . $old; $dst = $fm_path . '/' . $new;
            if (!file_exists($src)) throw new Exception('源文件不存在');
            if (file_exists($dst)) throw new Exception('目标名称已存在');
            rename($src, $dst); $resp = ['success'=>true, 'msg'=>'重命名成功'];
        } elseif ($action === 'create_folder') {
            if (!$fm_path) throw new Exception('无效目录路径');
            $folder_name = fm_clean_name($_POST['folder_name'] ?? '');
            if ($folder_name === '') throw new Exception('文件夹名称不能为空');
            $new_dir = $fm_path . '/' . $folder_name;
            if (file_exists($new_dir)) throw new Exception('该名称已存在');
            if (!mkdir($new_dir, 0755)) throw new Exception('创建失败');
            $resp = ['success'=>true, 'msg'=>'文件夹创建成功'];
        } elseif ($action === 'delete') {
            if (!$fm_path) throw new Exception('无效目录路径');
            $items = json_decode($_POST['items'] ?? '[]', true);
            if (!is_array($items)) throw new Exception('参数格式错误');
            $count = 0;
            foreach ($items as $item) {
                $p = $fm_path . '/' . basename($item);
                if (is_dir($p)) { fm_rmdir($p); $count++; } elseif (is_file($p)) { unlink($p); $count++; }
            }
            $resp = ['success'=>true, 'msg'=>"已删除 {$count} 项"];
        } elseif ($action === 'scan_files') {
            $files = ['audio' => [], 'lrc' => [], 'cover' => []];
            $root = realpath(FM_ROOT);
            if ($root) {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
                        $rel = substr($file->getPathname(), strlen($root) + 1);
                        $rel = str_replace('\\', '/', $rel);
                        if (in_array($ext, ['mp3', 'wav', 'flac', 'ogg', 'm4a'])) $files['audio'][] = $rel;
                        elseif ($ext === 'lrc') $files['lrc'][] = $rel;
                        elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'])) $files['cover'][] = $rel;
                    }
                }
            }
            $resp = ['success' => true, 'data' => $files];
        } elseif ($action === 'save_playlist') {
            $input = json_decode(file_get_contents('php://input'), true);
            $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['playlist'] ?? 'default');
            if (!is_array($input['data'] ?? null)) throw new Exception('数据结构错误');
            file_put_contents(PLAYLIST_DIR . "/{$name}.json", json_encode($input['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $resp = ['success'=>true, 'msg'=>'歌单已保存'];
        } elseif ($action === 'get_settings') {
            $defaults = [
                'default_cover' => DEFAULT_COVER, 'default_lrc' => DEFAULT_LRC,
                'default_title' => DEFAULT_TITLE, 'default_artist' => DEFAULT_ARTIST,
                'parse_separator' => PARSE_SEPARATOR, 'parse_format' => PARSE_FORMAT
            ];
            $saved = file_exists(SETTINGS_FILE) ? json_decode(file_get_contents(SETTINGS_FILE), true) : [];
            $resp = ['success'=>true, 'data'=>array_merge($defaults, $saved ?: [])];
        } elseif ($action === 'save_settings') {
            $input = json_decode(file_get_contents('php://input'), true);
            file_put_contents(SETTINGS_FILE, json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $resp = ['success'=>true, 'msg'=>'设置已保存'];
        } elseif ($action === 'backup_playlist') {
            $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['playlist_name'] ?? 'default');
            $data = $_POST['playlist_data'] ?? '[]';
            $file = BACKUP_DIR . "/{$name}_" . date('Ymd_His') . '.json';
            file_put_contents($file, $data);
            $resp = ['success'=>true, 'msg'=>'备份成功'];
        } elseif ($action === 'list_backups') {
            $files = array_filter(scandir(BACKUP_DIR), fn($f) => pathinfo($f, PATHINFO_EXTENSION) === 'json');
            natsort($files); $resp = ['success'=>true, 'data'=>array_values($files)];
        } elseif ($action === 'restore_backup') {
            $file = basename($_POST['file'] ?? '');
            $path = BACKUP_DIR . "/{$file}";
            $resp = file_exists($path) ? ['success'=>true, 'data'=>file_get_contents($path)] : ['success'=>false, 'msg'=>'备份文件不存在'];
        }
    } catch (Exception $e) { $resp['msg'] = $e->getMessage(); }
    echo json_encode($resp); exit;
}

// ========== 批量下载处理 ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_download_items'])) {
    $fm_dir = $_POST['batch_download_dir'] ?? ''; $fm_path = fm_sanitize_path($fm_dir);
    $items = json_decode($_POST['batch_download_items'], true);
    if (!$fm_path || !is_array($items)) exit('请求参数错误');
    $zip = new ZipArchive(); $tmp_file = tempnam(sys_get_temp_dir(), 'batch_');
    if ($zip->open($tmp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        foreach ($items as $item) {
            $item = basename($item); $target = $fm_path . '/' . $item;
            if (is_file($target)) $zip->addFile($target, $item);
            elseif (is_dir($target)) {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($iterator as $file) if ($file->isFile()) $zip->addFile($file->getPathname(), $item . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($target)+1)));
            }
        }
        $zip->close();
        if (filesize($tmp_file) > 22) {
            header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="files_' . date('Ymd_His') . '.zip"');
            header('Content-Length: ' . filesize($tmp_file)); readfile($tmp_file);
        } else echo "<script>alert('未勾选有效文件或文件夹');history.back();</script>";
        unlink($tmp_file); exit;
    } else exit('ZIP 创建失败');
}

// ========== 登录/登出处理 ==========
if (isset($_GET['logout'])) { session_destroy(); header('Location: ' . $_SERVER['PHP_SELF']); exit; }
if (empty($_SESSION['fm_logged'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_login'])) {
        $u = $_POST['username'] ?? ''; $p = $_POST['password'] ?? '';
        if ($u === ADMIN_USER && password_verify($p, ADMIN_PASS_HASH)) { $_SESSION['fm_logged'] = true; header('Location: ' . $_SERVER['PHP_SELF']); exit; }
        $login_error = '用户名或密码错误';
    }
?>
<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>登录</title>
<link href="https://unpkg.com/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>body{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);min-height:100vh;display:flex;align-items:center;justify-content:center}.card{max-width:400px;width:100%;padding:2rem;border-radius:12px;background:#fff}</style>
</head><body><div class="card shadow"><div class="card-body"><h4 class="text-center mb-4">音乐服务器后台</h4>
<?php if (!empty($login_error)): ?><div class="alert alert-danger"><?php echo esc_html($login_error) ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="do_login" value="1"><div class="mb-3"><label class="form-label">用户名</label><input type="text" name="username" class="form-control" required autofocus></div>
<div class="mb-3"><label class="form-label">密码</label><input type="password" name="password" class="form-control" required></div>
<button type="submit" class="btn btn-primary w-100">登录</button></form></div></div></body></html>
<?php exit; }

// ========== 歌单与设置数据加载 ==========
$playlist_files = glob(PLAYLIST_DIR . '/*.json');
$playlists = array_map(fn($f) => pathinfo($f, PATHINFO_FILENAME), $playlist_files);
$selected = $_GET['playlist'] ?? ($playlists[0] ?? 'default');
$selected = preg_replace('/[^a-zA-Z0-9_-]/', '', $selected);
if (empty($playlists)) {
    file_put_contents(PLAYLIST_DIR . '/default.json', json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $playlists = ['default']; $selected = 'default';
}
$playlist_data = file_exists(PLAYLIST_DIR . "/{$selected}.json") ? file_get_contents(PLAYLIST_DIR . "/{$selected}.json") : '[]';
$active_tab = $_GET['tab'] ?? 'playlist';

// ========== 文件管理数据 ==========
$fm_cur = $_GET['fm_dir'] ?? ''; $fm_path = fm_sanitize_path($fm_cur);
if (!$fm_path) { $fm_path = realpath(FM_ROOT); $fm_cur = ''; }
$fm_items = array_diff(scandir($fm_path), ['.', '..']); natcasesort($fm_items);
$fm_all = array_merge(array_filter($fm_items, fn($i) => is_dir($fm_path . '/' . $i)), array_filter($fm_items, fn($i) => is_file($fm_path . '/' . $i)));
$fm_parent = $fm_cur === '' ? '' : implode('/', array_slice(explode('/', trim($fm_cur, '/')), 0, -1));

// ========== API 预览数据 ==========
$api_list = [];
foreach ($playlist_files as $f) {
    $rel = substr($f, strlen(__DIR__) + 1); $rel = str_replace('\\', '/', $rel);
    $api_list[] = ['name' => pathinfo($f, PATHINFO_FILENAME), 'url' => (isset($_SERVER['HTTPS'])?'https':'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/api.php?url=./' . urlencode($rel)];
}
?>
<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>音乐服务器管理</title>
<link href="https://unpkg.com/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://unpkg.com/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head><body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-3 sticky-top">
<div class="container-fluid">
    <a class="navbar-brand" href="#">音乐服务器后台</a>
    <button class="navbar-toggler border-0" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu"><span class="navbar-toggler-icon"></span></button>
    <div class="offcanvas offcanvas-lg offcanvas-start text-bg-dark" tabindex="-1" id="sidebarMenu">
        <div class="offcanvas-header border-bottom border-secondary"><h5 class="offcanvas-title">导航菜单</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#sidebarMenu"></button></div>
        <div class="offcanvas-body d-flex flex-column flex-lg-row align-items-lg-center">
            <ul class="navbar-nav flex-grow-1 flex-lg-row mb-2 mb-lg-0 gap-2">
                <li class="nav-item"><a class="nav-link <?php echo $active_tab==='playlist'?'active bg-primary text-white rounded':''?>" href="?tab=playlist">歌单管理</a></li>
                <li class="nav-item"><a class="nav-link <?php echo $active_tab==='files'?'active bg-primary text-white rounded':''?>" href="?tab=files">文件管理</a></li>
                <li class="nav-item"><a class="nav-link <?php echo $active_tab==='api'?'active bg-primary text-white rounded':''?>" href="?tab=api">API 预览</a></li>
                <li class="nav-item"><a class="nav-link <?php echo $active_tab==='preview'?'active bg-primary text-white rounded':''?>" href="?tab=preview">主页预览</a></li>
                <li class="nav-item"><a class="nav-link <?php echo $active_tab==='settings'?'active bg-primary text-white rounded':''?>" href="?tab=settings">系统设置</a></li>
            </ul>
            <a href="?logout" class="btn btn-outline-light btn-sm ms-lg-auto mt-2 mt-lg-0">退出登录</a>
        </div>
    </div>
</div>
</nav>

<div class="container pb-5">
    <div id="toast-container" class="position-fixed bottom-0 end-0 p-3" style="z-index:1055"></div>

    <?php if ($active_tab === 'playlist'): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0"><i class="bi bi-music-note-beam me-2"></i>歌单管理</h5>
            <div class="d-flex gap-2 align-items-center">
                <form method="get" class="d-flex gap-2 m-0"><input type="hidden" name="tab" value="playlist"><select name="playlist" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($playlists as $pl): ?><option value="<?php echo esc_html($pl) ?>" <?php echo $pl===$selected?'selected':'' ?>><?php echo esc_html($pl) ?></option><?php endforeach; ?></select></form>
                <button class="btn btn-sm btn-outline-success" onclick="openAddModal()"><i class="bi bi-plus-lg"></i> 添加</button>
                <button class="btn btn-sm btn-outline-primary" onclick="autoAddTracks()"><i class="bi bi-lightning-charge"></i> 智能添加</button>
                <button class="btn btn-sm btn-outline-danger" onclick="batchDelete()"><i class="bi bi-trash"></i> 批量删除</button>
                <button class="btn btn-sm btn-success" onclick="savePlaylist()"><i class="bi bi-save"></i> 保存歌单</button>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-secondary" onclick="importJson()"><i class="bi bi-upload"></i> 导入</button>
                    <button class="btn btn-outline-secondary" onclick="exportJson()"><i class="bi bi-download"></i> 导出</button>
                    <button class="btn btn-outline-secondary" onclick="showBackups()"><i class="bi bi-archive"></i> 备份</button>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="playlistTable">
                    <thead class="table-light"><tr>
                        <th width="40"><input type="checkbox" id="plSelectAll" class="form-check-input"></th>
                        <th width="25%">曲名</th><th width="15%">艺术家</th><th width="20%">音频路径</th><th width="15%">歌词</th><th width="15%">封面</th><th width="10%">操作</th>
                    </tr></thead>
                    <tbody id="playlistBody"></tbody>
                </table>
            </div>
            <div id="emptyState" class="text-center py-5 text-muted d-none">暂无曲目，请点击“添加”或“智能添加”</div>
        </div>
        <div class="card-footer bg-transparent">
            <details class="small">
                <summary class="text-muted cursor-pointer"><i class="bi bi-code-slash"></i> 高级 JSON 编辑（双向同步）</summary>
                <textarea id="jsonEditor" class="form-control font-monospace mt-2 bg-light" rows="6" spellcheck="false" placeholder="JSON 格式数据..."><?php echo esc_html($playlist_data) ?></textarea>
            </details>
        </div>
    </div>

    <!-- 模态框：添加/编辑 -->
    <div class="modal fade" id="trackModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="trackModalTitle">添加曲目</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <input type="hidden" id="editIndex" value="-1">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">曲名</label><input type="text" id="inpName" class="form-control"></div>
                <div class="col-md-6"><label class="form-label">艺术家</label><input type="text" id="inpArtist" class="form-control"></div>
                <div class="col-md-6"><label class="form-label">音频路径</label><input type="text" id="inpUrl" class="form-control"></div>
                <div class="col-md-6"><label class="form-label">歌词路径</label><input type="text" id="inpLrc" class="form-control"></div>
                <div class="col-md-12"><label class="form-label">封面路径</label><input type="text" id="inpPic" class="form-control"></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button><button type="button" class="btn btn-primary" onclick="saveTrack()">保存</button></div>
    </div></div></div>

    <!-- 模态框：备份管理 -->
    <div class="modal fade" id="backupModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">备份管理</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <button class="btn btn-outline-primary btn-sm mb-3" onclick="createBackup()"><i class="bi bi-plus-circle"></i> 立即备份</button>
            <div class="list-group" id="backupList"><div class="text-muted">加载中...</div></div>
        </div>
    </div></div></div>
    <?php endif; ?>

    <?php if ($active_tab === 'files'): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between"><h5 class="mb-0">文件管理</h5><small class="text-muted">当前: /<?php echo esc_html($fm_cur ?: 'assets') ?></small></div>
        <div class="card-body">
            <nav class="breadcrumb mb-2"><a class="breadcrumb-item" href="?tab=files&fm_dir=">根目录</a><?php if ($fm_cur): $parts = explode('/', trim($fm_cur, '/')); $path=''; foreach ($parts as $i => $p): $path .= ($i?'/':'') . $p; ?><a class="breadcrumb-item" href="?tab=files&fm_dir=<?php echo urlencode($path) ?>"><?php echo esc_html($p) ?></a><?php endforeach; endif; ?></nav>
            <div id="drop-zone" class="border border-2 border-dashed rounded p-4 text-center bg-light mb-3" style="cursor:pointer"><i class="bi bi-cloud-arrow-up fs-2 text-primary"></i><p class="mb-0 mt-2">拖拽文件到此处，或 <a href="#" onclick="document.getElementById('fileInput').click()">点击选择</a></p><input type="file" id="fileInput" multiple hidden><div id="progress-container" class="mt-2"></div></div>
            <div class="d-flex gap-2 mb-3 align-items-center"><input type="text" id="newFolderName" class="form-control form-control-sm" placeholder="新文件夹名" maxlength="50"><button type="button" class="btn btn-outline-success btn-sm text-nowrap" onclick="createFolder()">新建</button></div>
            <form id="fmForm"><input type="hidden" name="fm_dir" value="<?php echo esc_html($fm_cur) ?>"><div class="table-responsive">
            <table class="table table-hover table-sm align-middle"><thead><tr><th width="30"><input type="checkbox" id="fmSelectAll" class="form-check-input"></th><th>名称</th><th>大小</th><th width="180">操作</th></tr></thead><tbody>
            <?php if ($fm_cur !== ''): ?><tr><td></td><td colspan="3"><a href="?tab=files&fm_dir=<?php echo urlencode($fm_parent) ?>" class="text-decoration-none fw-bold"><i class="bi bi-folder2-open"></i> 上级目录</a></td></tr><?php endif; ?>
            <?php foreach ($fm_all as $item): $is_dir = is_dir($fm_path . '/' . $item); $next = $fm_cur === '' ? $item : $fm_cur . '/' . $item; $raw_size = $is_dir ? 0 : filesize($fm_path . '/' . $item); $size = $is_dir ? '-' : ($raw_size < 1048576 ? round($raw_size/1024,1).' KB' : round($raw_size/1048576,2).' MB'); ?>
            <tr data-name="<?php echo esc_html($item) ?>"><td><input type="checkbox" name="sel[]" value="<?php echo esc_html($item) ?>" class="form-check-input"></td>
            <td><?php if ($is_dir): ?><a href="?tab=files&fm_dir=<?php echo urlencode($next) ?>" class="text-decoration-none fw-bold"><i class="bi bi-folder"></i> <?php echo esc_html($item) ?></a><?php else: ?><i class="bi bi-file-earmark-music"></i> <?php echo esc_html($item) ?><?php endif; ?></td>
            <td class="text-muted small"><?php echo $size ?></td>
            <td class="text-nowrap"><button type="button" class="btn btn-sm btn-outline-secondary rename-btn me-1" data-name="<?php echo esc_html($item) ?>"><i class="bi bi-pencil"></i></button><button type="button" class="btn btn-sm btn-outline-danger delete-btn me-1" data-name="<?php echo esc_html($item) ?>"><i class="bi bi-trash"></i></button><a href="?fm_download=<?php echo urlencode($item) ?>&fm_dir=<?php echo urlencode($fm_cur) ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-download"></i></a></td></tr>
            <?php endforeach; ?><?php if (empty($fm_all)): ?><tr><td colspan="4" class="text-center text-muted py-4">目录为空</td></tr><?php endif; ?>
            </tbody></table></div>
            <div class="d-flex gap-2 mt-2"><button type="button" class="btn btn-outline-danger btn-sm" onclick="fmBatch('delete')"><i class="bi bi-trash"></i> 批量删除</button><button type="button" class="btn btn-outline-success btn-sm" onclick="fmBatch('download')"><i class="bi bi-download"></i> 批量下载</button></div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($active_tab === 'api'): ?>
    <div class="card shadow-sm mb-4"><div class="card-header"><h5 class="mb-0">API 接口预览</h5></div>
    <div class="card-body"><div class="row g-3">
    <?php foreach ($api_list as $api): ?><div class="col-12"><div class="d-flex align-items-center gap-2 p-2 bg-light rounded"><span class="text-truncate flex-grow-1"><strong><?php echo esc_html($api['name']) ?>.json</strong><br><small class="text-muted"><?php echo esc_html($api['url']) ?></small></span><button class="btn btn-sm btn-outline-primary copy-btn" data-url="<?php echo esc_html($api['url']) ?>">复制</button></div></div><?php endforeach; ?>
    <?php if (empty($api_list)): ?><p class="text-muted">暂无歌单文件</p><?php endif; ?>
    </div><hr><p class="small text-muted">调用示例：<code>fetch('API_URL').then(r=>r.json()).then(console.log)</code></p></div></div>
    <?php endif; ?>

    <?php if ($active_tab === 'preview'): ?>
    <div class="card shadow-sm mb-4"><div class="card-header"><h5 class="mb-0">主页预览</h5></div><div class="card-body p-0"><iframe src="<?php echo esc_html(INDEX_PAGE) ?>" style="width:100%;height:650px;border:0"></iframe></div></div>
    <?php endif; ?>

    <?php if ($active_tab === 'settings'): ?>
    <div class="card shadow-sm mb-4"><div class="card-header"><h5 class="mb-0"><i class="bi bi-gear"></i> 系统设置</h5></div>
    <div class="card-body"><form id="settingsForm">
        <h6 class="border-bottom pb-2">默认值配置</h6>
        <div class="row g-3 mb-3">
            <div class="col-md-4"><label class="form-label">默认封面路径</label><input type="text" name="default_cover" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">默认歌词路径</label><input type="text" name="default_lrc" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">默认曲名</label><input type="text" name="default_title" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">默认艺术家</label><input type="text" name="default_artist" class="form-control"></div>
        </div>
        <h6 class="border-bottom pb-2 mt-4">智能解析规则</h6>
        <div class="row g-3 mb-3">
            <div class="col-md-4"><label class="form-label">分隔符</label><input type="text" name="parse_separator" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">文件名格式</label><select name="parse_format" class="form-select"><option value="title_artist">曲名 - 艺术家</option><option value="artist_title">艺术家 - 曲名</option></select></div>
        </div>
        <button type="button" class="btn btn-primary" onclick="saveSettings()"><i class="bi bi-save"></i> 保存设置</button>
    </form></div></div>
    <?php endif; ?>
</div>

<script src="https://unpkg.com/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ================= 状态与工具 =================
let plState = [];
let jsonSyncing = false;
let guiSyncing = false;
let currentSettings = {};

const esc = (s) => String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]||m));

function showToast(msg, type='success') {
    const container = document.getElementById('toast-container');
    const toastEl = document.createElement('div');
    toastEl.className = `toast align-items-center text-white bg-${type} border-0 mb-2`;
    toastEl.setAttribute('role', 'alert');
    toastEl.innerHTML = `<div class="d-flex"><div class="toast-body">${esc(msg)}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    container.appendChild(toastEl);
    const bsToast = new bootstrap.Toast(toastEl, { delay: 2500 });
    bsToast.show();
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}

// ================= 渲染与同步 =================
function renderTable() {
    const tbody = document.getElementById('playlistBody');
    const empty = document.getElementById('emptyState');
    if (!tbody || !empty) return;
    
    tbody.innerHTML = '';
    if (!plState.length) { empty.classList.remove('d-none'); return; }
    empty.classList.add('d-none');
    
    plState.forEach((item, i) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="checkbox" class="form-check-input pl-check" data-idx="${i}"></td>
            <td><input type="text" class="form-control form-control-sm" value="${esc(item.name||'')}" data-idx="${i}" data-key="name"></td>
            <td><input type="text" class="form-control form-control-sm" value="${esc(item.artist||'')}" data-idx="${i}" data-key="artist"></td>
            <td><input type="text" class="form-control form-control-sm" value="${esc(item.url||'')}" data-idx="${i}" data-key="url"></td>
            <td><input type="text" class="form-control form-control-sm" value="${esc(item.lrc||'')}" data-idx="${i}" data-key="lrc"></td>
            <td><input type="text" class="form-control form-control-sm" value="${esc(item.pic||'')}" data-idx="${i}" data-key="pic"></td>
            <td class="text-nowrap">
                <button class="btn btn-sm btn-outline-primary" onclick="editTrack(${i})"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-outline-danger" onclick="removeTrack(${i})"><i class="bi bi-trash"></i></button>
            </td>`;
        tbody.appendChild(tr);
    });
    bindTableInputs();
    syncToJSON();
}

function syncToJSON() {
    if (guiSyncing) return;
    jsonSyncing = true;
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
        renderTable();
        guiSyncing = false;
    } catch(e) { /* 忽略无效 JSON 输入 */ }
}

function bindTableInputs() {
    document.querySelectorAll('#playlistBody input[data-key]').forEach(inp => {
        inp.addEventListener('input', function() {
            const idx = this.dataset.idx, key = this.dataset.key;
            if (plState[idx]) { plState[idx][key] = this.value; syncToJSON(); }
        });
    });
}

// ================= 增删改查 =================
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
    const newItem = {
        name: document.getElementById('inpName').value.trim(),
        artist: document.getElementById('inpArtist').value.trim(),
        url: document.getElementById('inpUrl').value.trim(),
        lrc: document.getElementById('inpLrc').value.trim(),
        pic: document.getElementById('inpPic').value.trim()
    };
    if (!newItem.url) return showToast('音频路径不能为空', 'danger');
    if (idx === -1) plState.push(newItem); else plState[idx] = newItem;
    bootstrap.Modal.getInstance(document.getElementById('trackModal')).hide();
    renderTable();
    showToast(idx === -1 ? '已添加' : '已更新');
}

function removeTrack(i) {
    if (!confirm('确定移除该曲目？')) return;
    plState.splice(i, 1);
    renderTable();
}

function batchDelete() {
    const checks = [...document.querySelectorAll('.pl-check:checked')];
    if (!checks.length) return showToast('请先勾选曲目', 'warning');
    if (!confirm(`确定删除选中的 ${checks.length} 首曲目？`)) return;
    const idxs = checks.map(c => parseInt(c.dataset.idx)).sort((a,b)=>b-a);
    idxs.forEach(i => plState.splice(i, 1));
    renderTable();
    showToast('已批量删除');
}

function savePlaylist() {
    const currentPlaylist = document.querySelector('select[name="playlist"] option:checked')?.value || 'default';
    fetch(`?ajax=1&action=save_playlist&playlist=${encodeURIComponent(currentPlaylist)}`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({data: plState})
    }).then(r=>r.json()).then(res=>{
        if(res.success) showToast(res.msg); else showToast(res.msg, 'danger');
    }).catch(e=>showToast('保存失败: ' + e.message, 'danger'));
}

// ================= 智能添加 =================
async function autoAddTracks() {
    try {
        const res = await fetch(`?ajax=1&action=scan_files`).then(r=>r.json());
        if (!res.success) return showToast(res.msg, 'danger');
        const {audio, lrc, cover} = res.data;
        if (!audio.length) return showToast('未找到音频文件', 'warning');

        const usedNames = new Set(plState.map(t=>t.name).filter(Boolean));
        let added = 0;
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
            if (parts.length >= 2) {
                [name, artist] = fmt === 'title_artist' ? [parts[0], parts[1]] : [parts[1], parts[0]];
            }

            if (usedNames.has(name)) {
                let counter = 1;
                while (usedNames.has(`${name} (${counter})`)) counter++;
                name = `${name} (${counter})`;
            }
            usedNames.add(name);

            plState.push({
                name, artist,
                url: `./assets/${aPath}`,
                lrc: matchLrc ? `./assets/${matchLrc}` : (currentSettings.default_lrc || ''),
                pic: matchCover ? `./assets/${matchCover}` : (currentSettings.default_cover || '')
            });
            added++;
        });
        renderTable();
        showToast(`智能添加成功，共 ${added} 首`);
    } catch(e) { showToast('智能添加失败: ' + e.message, 'danger'); }
}

// ================= JSON 导入导出 =================
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
        r.onload = () => {
            try {
                const d = JSON.parse(r.result);
                if(Array.isArray(d)) { plState = d.map(i=>({...i})); renderTable(); showToast('导入成功'); }
            } catch { showToast('JSON 格式无效', 'danger'); }
        };
        r.readAsText(f);
    };
    input.click();
}

// ================= 备份管理 =================
async function showBackups() {
    const modal = new bootstrap.Modal(document.getElementById('backupModal'));
    modal.show();
    const list = document.getElementById('backupList'); list.innerHTML = '<div class="text-muted">加载中...</div>';
    try {
        const res = await fetch(`?ajax=1&action=list_backups`).then(r=>r.json());
        if(!res.success) return list.innerHTML = '<div class="text-danger">'+esc(res.msg)+'</div>';
        list.innerHTML = res.data.length ? res.data.map(f => `
            <div class="list-group-item d-flex justify-content-between align-items-center">
                <span class="small text-truncate" style="max-width:60%">${esc(f)}</span>
                <div><button class="btn btn-sm btn-outline-primary me-1" onclick="restoreBackup('${esc(f)}')">恢复</button><button class="btn btn-sm btn-outline-secondary" onclick="downloadBackup('${esc(f)}')">下载</button></div>
            </div>`).join('') : '<div class="text-muted">暂无备份</div>';
    } catch { list.innerHTML = '<div class="text-danger">请求失败</div>'; }
}

function createBackup() {
    const fd = new FormData();
    fd.append('playlist_name', document.querySelector('select[name="playlist"] option:checked')?.textContent || 'default');
    fd.append('playlist_data', JSON.stringify(plState));
    fetch(`?ajax=1&action=backup_playlist`, {method:'POST', body:fd}).then(r=>r.json()).then(res=>{
        if(res.success) { showToast(res.msg); showBackups(); } else showToast(res.msg, 'danger');
    });
}

function restoreBackup(file) {
    if(!confirm('恢复将覆盖当前歌单数据，是否继续？')) return;
    const fd = new FormData(); fd.append('file', file);
    fetch(`?ajax=1&action=restore_backup`, {method:'POST', body:fd}).then(r=>r.json()).then(res=>{
        if(res.success) {
            try { plState = JSON.parse(res.data).map(i=>({...i})); renderTable(); bootstrap.Modal.getInstance(document.getElementById('backupModal')).hide(); showToast('恢复成功'); }
            catch { showToast('备份文件损坏', 'danger'); }
        } else showToast(res.msg, 'danger');
    });
}

function downloadBackup(file) {
    const fd = new FormData(); fd.append('file', file);
    fetch(`?ajax=1&action=restore_backup`, {method:'POST', body:fd})
    .then(r=>r.json()).then(res=>{
        if(res.success) { const b=new Blob([res.data],{type:'application/json'}); const a=document.createElement('a'); a.href=URL.createObjectURL(b); a.download=file; a.click(); }
    });
}

// ================= 设置管理 =================
async function loadSettings() {
    try {
        const res = await fetch(`?ajax=1&action=get_settings`).then(r=>r.json());
        if(res.success) currentSettings = res.data || {};
    } catch {}
    
    const f = document.getElementById('settingsForm');
    if (!f) return;
    f.default_cover.value = currentSettings.default_cover || '';
    f.default_lrc.value = currentSettings.default_lrc || '';
    f.default_title.value = currentSettings.default_title || '';
    f.default_artist.value = currentSettings.default_artist || '';
    f.parse_separator.value = currentSettings.parse_separator || '';
    f.parse_format.value = currentSettings.parse_format || 'title_artist';
}

function saveSettings() {
    const f = document.getElementById('settingsForm');
    const data = {
        default_cover: f.default_cover.value.trim(),
        default_lrc: f.default_lrc.value.trim(),
        default_title: f.default_title.value.trim(),
        default_artist: f.default_artist.value.trim(),
        parse_separator: f.parse_separator.value.trim(),
        parse_format: f.parse_format.value
    };
    fetch(`?ajax=1&action=save_settings`, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(data)})
    .then(r=>r.json()).then(res=>{
        if(res.success) { currentSettings = data; showToast(res.msg); } else showToast(res.msg, 'danger');
    });
}

// ================= 文件管理 =================
document.getElementById('fmSelectAll')?.addEventListener('change', function () {
    document.querySelectorAll('input[name="sel[]"]').forEach(c => c.checked = this.checked);
});
function fmBatch(action) {
    const sel = [...document.querySelectorAll('input[name="sel[]"]:checked')].map(c => c.value);
    if (sel.length === 0) return showToast('请先勾选项目', 'warning');
    if (action === 'delete') {
        if (!confirm('确定删除选中项？此操作不可恢复。')) return;
        const fd = new FormData(); fd.append('items', JSON.stringify(sel));
        const curDir = document.querySelector('#fmForm input[name="fm_dir"]').value;
        fetch(`?ajax=1&fm_dir=${encodeURIComponent(curDir)}&action=delete`, { method: 'POST', body: fd })
        .then(r => r.json()).then(res => { showToast(res.msg); if (res.success) location.reload(); });
    } else if (action === 'download') {
        const form = document.createElement('form'); form.method = 'POST'; form.action = '';
        const dirInput = document.createElement('input'); dirInput.type = 'hidden'; dirInput.name = 'batch_download_dir'; dirInput.value = document.querySelector('#fmForm input[name="fm_dir"]').value;
        const itemsInput = document.createElement('input'); itemsInput.type = 'hidden'; itemsInput.name = 'batch_download_items'; itemsInput.value = JSON.stringify(sel);
        form.appendChild(dirInput); form.appendChild(itemsInput); document.body.appendChild(form); form.submit();
    }
}
document.querySelectorAll('.rename-btn').forEach(btn => btn.addEventListener('click', function () {
    const old = this.dataset.name;
    const newName = prompt('输入新名称:', old);
    if (newName && newName !== old) {
        const fd = new FormData(); fd.append('old', old); fd.append('new', newName);
        const curDir = document.querySelector('#fmForm input[name="fm_dir"]').value;
        fetch(`?ajax=1&fm_dir=${encodeURIComponent(curDir)}&action=rename`, { method: 'POST', body: fd })
        .then(r => r.json()).then(res => { showToast(res.msg); if (res.success) location.reload(); });
    }
}));
function createFolder() {
    const input = document.getElementById('newFolderName');
    const name = input.value.trim();
    if (!name) return showToast('请输入文件夹名称', 'warning');
    const fd = new FormData(); fd.append('folder_name', name);
    const curDir = document.querySelector('#fmForm input[name="fm_dir"]').value;
    fetch(`?ajax=1&fm_dir=${encodeURIComponent(curDir)}&action=create_folder`, { method: 'POST', body: fd })
    .then(r => r.json()).then(res => { showToast(res.msg); if (res.success) { input.value = ''; location.reload(); } });
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
function handleFiles(files) { if (!files.length) return; Array.from(files).forEach(uploadFile); }
let reloadTriggered = false;
function uploadFile(file) {
    if (!dropZone || !progressCont) return;
    const id = 'prog_' + Math.random().toString(36).substr(2);
    progressCont.insertAdjacentHTML('beforeend', `<div id="${id}" class="d-flex align-items-center gap-2 mb-1 small"><span class="text-truncate" style="max-width:50%">${esc(file.name)}</span><div class="progress flex-grow-1" style="height:6px"><div class="progress-bar" role="progressbar" style="width:0%"></div></div><span>0%</span></div>`);
    const fd = new FormData(); fd.append('fm_files', file);
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
                if (res.success && !reloadTriggered) { reloadTriggered = true; setTimeout(() => location.reload(), 600); }
            } else { if(el) el.querySelector('span:last-child').textContent = `HTTP ${xhr.status}`; }
        } catch { if(el) el.querySelector('span:last-child').textContent = '解析错误'; }
    };
    xhr.onerror = () => { const el = document.getElementById(id); if(el) el.querySelector('span:last-child').textContent = '网络错误'; };
    xhr.send(fd);
}

// ================= 初始化与事件绑定 =================
document.addEventListener('DOMContentLoaded', () => {
    loadSettings();
    
    document.querySelectorAll('.copy-btn').forEach(b => b.addEventListener('click', function () {
        navigator.clipboard.writeText(this.dataset.url).then(() => {
            const o = this.innerHTML; this.innerHTML = '<i class="bi bi-check2"></i>'; setTimeout(() => this.innerHTML = o, 1000);
        });
    }));

    // 仅在歌单页初始化相关逻辑，彻底解决跨 Tab 的 null 报错
    if (document.getElementById('playlistTable')) {
        try { 
            const raw = document.getElementById('jsonEditor')?.value || '[]';
            plState = JSON.parse(raw).map(i=>({...i})); 
        } catch { plState = []; }
        
        renderTable();
        document.getElementById('jsonEditor')?.addEventListener('input', syncFromJSON);
        document.getElementById('plSelectAll')?.addEventListener('change', function() { 
            document.querySelectorAll('.pl-check').forEach(c=>c.checked=this.checked); 
        });
    }
});
</script>
</body></html>