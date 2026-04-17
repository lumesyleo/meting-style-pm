<?php
// ================= 配置区域 =================
define('FM_ROOT', __DIR__ . '/assets');
define('PLAYLIST_DIR', __DIR__ . '/playlists');
define('ADMIN_USER', 'admin');
define('ADMIN_PASS_HASH', password_hash('123456', PASSWORD_DEFAULT));
define('INDEX_PAGE', 'index.html');
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

// 保留中文、Emoji，仅拦截系统保留字符
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

// ========== 初始化目录 ==========
if (!is_dir(FM_ROOT)) mkdir(FM_ROOT, 0755, true);
if (!is_dir(PLAYLIST_DIR)) mkdir(PLAYLIST_DIR, 0755, true);

// ========== AJAX 路由 ==========
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $fm_cur = $_GET['fm_dir'] ?? '';
    $fm_path = fm_sanitize_path($fm_cur);
    if (!$fm_path) { echo json_encode(['success'=>false, 'msg'=>'无效目录路径']); exit; }

    $resp = ['success' => false, 'msg' => '未知操作'];
    try {
        $action = $_GET['action'] ?? '';
        if ($action === 'upload') {
            $files = $_FILES['fm_files'] ?? [];
            if (empty($files['tmp_name'])) throw new Exception('未接收到文件数据');
            
            // 统一单文件/多文件上传时的 $_FILES 结构
            if (!is_array($files['tmp_name'])) {
                $files = [
                    'tmp_name' => [$files['tmp_name']],
                    'name'     => [$files['name']],
                    'error'    => [$files['error']],
                    'size'     => isset($files['size']) ? [$files['size']] : [0]
                ];
            }
            
            $count = 0;
            foreach ($files['tmp_name'] as $i => $tmp) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $dest = $fm_path . '/' . fm_clean_name($files['name'][$i]);
                    if (!move_uploaded_file($tmp, $dest)) throw new Exception("移动文件失败: " . basename($files['name'][$i]));
                    $count++;
                }
            }
            $resp = ['success'=>true, 'msg'=>"成功上传 {$count} 个文件"];
        } elseif ($action === 'rename') {
            $old = fm_clean_name($_POST['old'] ?? '');
            $new = fm_clean_name($_POST['new'] ?? '');
            if ($old === '' || $new === '') throw new Exception('文件名不能为空');
            $src = $fm_path . '/' . $old;
            $dst = $fm_path . '/' . $new;
            if (!file_exists($src)) throw new Exception('源文件不存在');
            if (file_exists($dst)) throw new Exception('目标名称已存在');
            rename($src, $dst);
            $resp = ['success'=>true, 'msg'=>'重命名成功'];
        } elseif ($action === 'create_folder') {
            $folder_name = fm_clean_name($_POST['folder_name'] ?? '');
            if ($folder_name === '') throw new Exception('文件夹名称不能为空');
            $new_dir = $fm_path . '/' . $folder_name;
            if (file_exists($new_dir)) throw new Exception('该名称已存在');
            if (!mkdir($new_dir, 0755)) throw new Exception('创建失败，请检查目录权限');
            $resp = ['success'=>true, 'msg'=>'文件夹创建成功'];
        } elseif ($action === 'delete') {
            $items = json_decode($_POST['items'] ?? '[]', true);
            if (!is_array($items)) throw new Exception('参数格式错误');
            $count = 0;
            foreach ($items as $item) {
                $p = $fm_path . '/' . basename($item);
                if (is_dir($p)) { fm_rmdir($p); $count++; }
                elseif (is_file($p)) { unlink($p); $count++; }
            }
            $resp = ['success'=>true, 'msg'=>"已删除 {$count} 项"];
        }
    } catch (Exception $e) { $resp['msg'] = $e->getMessage(); }
    echo json_encode($resp);
    exit;
}

// ========== 批量下载处理 ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_download_items'])) {
    $fm_dir = $_POST['batch_download_dir'] ?? '';
    $fm_path = fm_sanitize_path($fm_dir);
    $items = json_decode($_POST['batch_download_items'], true);
    if (!$fm_path || !is_array($items)) exit('请求参数错误');

    $zip = new ZipArchive();
    $tmp_file = tempnam(sys_get_temp_dir(), 'batch_');
    if ($zip->open($tmp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        foreach ($items as $item) {
            $item = basename($item);
            $target = $fm_path . '/' . $item;
            if (is_file($target)) {
                $zip->addFile($target, $item);
            } elseif (is_dir($target)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($target, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $rel = substr($file->getPathname(), strlen($target) + 1);
                        $zip->addFile($file->getPathname(), $item . '/' . str_replace('\\', '/', $rel));
                    }
                }
            }
        }
        $zip->close();
        if (filesize($tmp_file) > 22) { // ZIP最小头为22字节
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="files_' . date('Ymd_His') . '.zip"');
            header('Content-Length: ' . filesize($tmp_file));
            readfile($tmp_file);
        } else {
            echo "<script>alert('未勾选有效文件或文件夹');history.back();</script>";
        }
        unlink($tmp_file);
        exit;
    } else { exit('ZIP 创建失败，请检查临时目录权限'); }
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
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>登录</title>
    <link href="https://unpkg.com/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);min-height:100vh;display:flex;align-items:center;justify-content:center}.card{max-width:400px;width:100%;padding:2rem;border-radius:12px;background:#fff}
    </style>
</head>
<body>
    <div class="card shadow">
        <div class="card-body">
            <h4 class="text-center mb-4">音乐服务器后台</h4> <?php if (!empty($login_error)): ?><div class="alert alert-danger"><?php echo htmlspecialchars($login_error) ?></div><?php endif; ?> <form method="post"><input type="hidden" name="do_login" value="1">
                <div class="mb-3"><label class="form-label">用户名</label><input type="text" name="username" class="form-control" required autofocus></div>
                <div class="mb-3"><label class="form-label">密码</label><input type="password" name="password" class="form-control" required></div>
                <button type="submit" class="btn btn-primary w-100">登录</button>
            </form>
        </div>
    </div>
</body>
</html> <?php exit;
}

// ========== 歌单管理 ==========
$playlist_files = glob(PLAYLIST_DIR . '/*.json');
$playlists = array_map(fn($f) => pathinfo($f, PATHINFO_FILENAME), $playlist_files);
$selected = $_GET['playlist'] ?? ($playlists[0] ?? 'default');
$selected = preg_replace('/[^a-zA-Z0-9_-]/', '', $selected);
if (empty($playlists)) {
    file_put_contents(PLAYLIST_DIR . '/default.json', json_encode([['name'=>'示例歌曲','artist'=>'示例歌手','url'=>'./assets/demo.mp3','pic'=>'./assets/default.jpg','lrc'=>'./assets/demo.lrc']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $playlists = ['default']; $selected = 'default';
}
$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['ajax'])) {
    if (isset($_POST['save_playlist'])) {
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['playlist_name']);
        if (json_decode($_POST['playlist_data'] ?? '[]') !== null) { file_put_contents(PLAYLIST_DIR . "/{$name}.json", $_POST['playlist_data']); $msg = '歌单已保存'; $selected = $name; }
        else $err = 'JSON 格式错误';
    }
    if (isset($_POST['new_playlist'])) {
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['new_name']);
        if ($name && !in_array($name, $playlists)) { file_put_contents(PLAYLIST_DIR . "/{$name}.json", '[]'); $playlists[] = $name; $selected = $name; $msg = '歌单已创建'; }
        else $err = '名称无效或已存在';
    }
    if (isset($_POST['del_playlist'])) {
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['del_name']);
        if ($name && in_array($name, $playlists) && ($name !== 'default' || count($playlists) > 1)) { unlink(PLAYLIST_DIR . "/{$name}.json"); $playlists = array_diff($playlists, [$name]); $selected = $playlists[0] ?? ''; $msg = '歌单已删除'; }
        else $err = '无法删除默认歌单或歌单不存在';
    }
}
$playlist_data = file_exists(PLAYLIST_DIR . "/{$selected}.json") ? file_get_contents(PLAYLIST_DIR . "/{$selected}.json") : '[]';

// ========== 文件管理数据 ==========
$fm_cur = $_GET['fm_dir'] ?? '';
$fm_path = fm_sanitize_path($fm_cur);
if (!$fm_path) { $fm_path = realpath(FM_ROOT); $fm_cur = ''; }
$fm_items = array_diff(scandir($fm_path), ['.', '..']);
natcasesort($fm_items);
$fm_all = array_merge(array_filter($fm_items, fn($i) => is_dir($fm_path . '/' . $i)), array_filter($fm_items, fn($i) => is_file($fm_path . '/' . $i)));
$fm_parent = $fm_cur === '' ? '' : implode('/', array_slice(explode('/', trim($fm_cur, '/')), 0, -1));

// ========== API 预览数据 ==========
$api_list = [];
foreach ($playlist_files as $f) {
    $rel = substr($f, strlen(__DIR__) + 1);
    $rel = str_replace('\\', '/', $rel);
    $api_list[] = ['name' => pathinfo($f, PATHINFO_FILENAME), 'url' => (isset($_SERVER['HTTPS'])?'https':'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/api.php?url=./' . urlencode($rel)];
}
$active_tab = $_GET['tab'] ?? 'playlist';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>音乐服务器管理</title>
    <link href="https://unpkg.com/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://unpkg.com/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body{background:#f8f9fa} #drop-zone{border:2px dashed #cbd5e1;border-radius:8px;padding:2rem;text-align:center;transition:all .2s;background:#f8fafc;cursor:pointer} #drop-zone.dragover{border-color:#3b82f8;background:#eff6ff} .progress-list{max-height:150px;overflow-y:auto} .progress-item{font-size:0.85rem;margin-bottom:4px} .api-box{background:#e7f3ff;padding:.75rem;border-radius:6px;font-family:monospace;font-size:13px;word-break:break-all}
        /* 移动端侧滑菜单项强制撑满容器宽度 */
        @media (max-width: 991.98px) {
            #sidebarMenu .nav-item { width: 100% !important; }
            #sidebarMenu .nav-link { 
                width: 100% !important; 
                text-align: left; 
                padding: 0.6rem 1rem;
            }
            #sidebarMenu .btn-outline-light { 
                width: 100% !important; 
                margin-top: 0.5rem; 
            }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-3">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">音乐服务器后台</a>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <!-- offcanvas-lg：桌面端(≥lg)为普通导航栏，移动端(<lg)自动转为侧滑面板 -->
            <div class="offcanvas offcanvas-lg offcanvas-start text-bg-dark" tabindex="-1" id="sidebarMenu" aria-labelledby="sidebarMenuLabel">
                <div class="offcanvas-header border-bottom border-secondary">
                    <h5 class="offcanvas-title" id="sidebarMenuLabel">导航菜单</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#sidebarMenu" aria-label="Close"></button>
                </div>
                <div class="offcanvas-body d-flex flex-column flex-lg-row align-items-lg-center">
                    <ul class="navbar-nav flex-grow-1 flex-lg-row mb-2 mb-lg-0 gap-2">
                        <li class="nav-item"><a class="nav-link <?php echo $active_tab==='playlist'?'active bg-primary text-white rounded':''?>" href="?tab=playlist">歌单管理</a></li>
                        <li class="nav-item"><a class="nav-link <?php echo $active_tab==='files'?'active bg-primary text-white rounded':''?>" href="?tab=files">文件管理</a></li>
                        <li class="nav-item"><a class="nav-link <?php echo $active_tab==='api'?'active bg-primary text-white rounded':''?>" href="?tab=api">🌐 API 预览</a></li>
                        <li class="nav-item"><a class="nav-link <?php echo $active_tab==='preview'?'active bg-primary text-white rounded':''?>" href="?tab=preview">主页预览</a></li>
                    </ul>
                    <!-- ms-lg-auto：桌面端强制靠右对齐，移动端自然位于底部 -->
                    <a href="?logout" class="btn btn-outline-light btn-sm ms-lg-auto mt-2 mt-lg-0">退出登录</a>
                </div>
            </div>
        </div>
    </nav>
    <div class="container pb-5"> <?php if ($msg): ?><div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?> <?php if ($err): ?><div
            class="alert alert-danger alert-dismissible fade show"><?php echo htmlspecialchars($err) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?> <?php if ($active_tab === 'playlist'): ?> <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">歌单管理</h5>
                <form method="get" class="d-flex gap-2"><input type="hidden" name="tab" value="playlist"><select name="playlist" class="form-select form-select-sm" onchange="this.form.submit()"> <?php foreach ($playlists as $pl): ?><option value="<?php echo htmlspecialchars($pl) ?>"
                            <?php echo $pl===$selected?'selected':'' ?>><?php echo htmlspecialchars($pl) ?></option><?php endforeach; ?> </select></form>
            </div>
            <div class="card-body">
                <form method="post" class="mb-3">
                    <div class="input-group input-group-sm"><input type="text" name="new_name" class="form-control" placeholder="新歌单名称（仅字母数字_-）" required><button type="submit" name="new_playlist" class="btn btn-outline-primary">新建</button></div>
                </form>
                <div class="row">
                    <div class="col-lg-6"><label class="form-label">JSON 编辑器</label><textarea id="playlistEditor" class="form-control font-monospace" rows="18"><?php echo htmlspecialchars($playlist_data) ?></textarea></div>
                    <div class="col-lg-6"><label class="form-label">实时预览</label>
                        <div id="playlistPreview" class="border rounded p-2 bg-light" style="max-height:400px;overflow-y:auto"></div>
                    </div>
                </div>
                <form method="post" class="mt-3 d-flex gap-2"><input type="hidden" name="playlist_name" value="<?php echo htmlspecialchars($selected) ?>"><input type="hidden" name="playlist_data" id="playlistDataHidden">
                    <button type="submit" name="save_playlist" class="btn btn-primary">保存</button><button type="button" class="btn btn-outline-secondary" onclick="formatJSON()">格式化</button> <?php if ($selected !== 'default' || count($playlists) > 1): ?><button type="submit" name="del_playlist"
                        value="<?php echo htmlspecialchars($selected) ?>" class="btn btn-outline-danger ms-auto" onclick="return confirm('确定删除？')">删除</button><?php endif; ?>
                </form>
            </div>
        </div> <?php endif; ?> <?php if ($active_tab === 'files'): ?> <div class="card">
            <div class="card-header d-flex justify-content-between">
                <h5 class="mb-0">文件管理</h5><small class="text-muted">当前: /<?php echo htmlspecialchars($fm_cur ?: 'assets') ?></small>
            </div>
            <div class="card-body">
                <nav class="breadcrumb mb-2"><a class="breadcrumb-item" href="?tab=files&fm_dir=">根目录</a><?php if ($fm_cur): $parts = explode('/', trim($fm_cur, '/')); $path=''; foreach ($parts as $i => $p): $path .= ($i?'/':'') . $p; ?><a class="breadcrumb-item"
                        href="?tab=files&fm_dir=<?php echo urlencode($path) ?>"><?php echo htmlspecialchars($p) ?></a><?php endforeach; endif; ?></nav>
                <div class="gap-2 mb-3 align-items-stretch">
                    <div id="drop-zone" class="flex-grow-1">
                        <i class="bi bi-cloud-arrow-up fs-2 text-primary"></i>
                        <p class="mb-0 mt-2">拖拽文件到此处，或 <a href="#" onclick="document.getElementById('fileInput').click()">点击选择</a></p>
                        <input type="file" id="fileInput" multiple hidden>
                        <div id="progress-container" class="progress-list mt-2"></div>
                    </div>
                </div>
                <div class="d-flex gap-2 mb-3 align-items-center">
                    <input type="text" id="newFolderName" class="form-control form-control-sm" placeholder="新文件夹名" maxlength="50">
                    <button type="button" class="btn btn-outline-success btn-sm text-nowrap" onclick="createFolder()">新建</button>
                </div>
                <form id="fmForm"><input type="hidden" name="fm_dir" value="<?php echo htmlspecialchars($fm_cur) ?>">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm align-middle">
                            <thead>
                                <tr>
                                    <th width="30"><input type="checkbox" id="fmSelectAll"></th>
                                    <th>名称</th>
                                    <th>大小</th>
                                    <th width="180">操作</th>
                                </tr>
                            </thead>
                            <tbody> <?php if ($fm_cur !== ''): ?><tr>
                                    <td></td>
                                    <td colspan="3"><a href="?tab=files&fm_dir=<?php echo urlencode($fm_parent) ?>" class="text-decoration-none fw-bold">上级目录</a></td>
                                </tr><?php endif; ?> <?php foreach ($fm_all as $item): $is_dir = is_dir($fm_path . '/' . $item); $next = $fm_cur === '' ? $item : $fm_cur . '/' . $item;
$raw_size = $is_dir ? 0 : filesize($fm_path . '/' . $item);
$size = $is_dir ? '-' : ($raw_size < 1048576 ? round($raw_size/1024,1).' KB' : round($raw_size/1048576,2).' MB'); ?> <tr data-name="<?php echo htmlspecialchars($item) ?>">
                                    <td><input type="checkbox" name="sel[]" value="<?php echo htmlspecialchars($item) ?>"></td>
                                    <td><?php if ($is_dir): ?><a href="?tab=files&fm_dir=<?php echo urlencode($next) ?>" class="text-decoration-none fw-bold"><?php echo htmlspecialchars($item) ?></a><?php else: ?>📄 <?php echo htmlspecialchars($item) ?><?php endif; ?></td>
                                    <td class="text-muted small"><?php echo $size ?></td>
                                    <td class="text-nowrap">
                                        <button type="button" class="btn btn-sm btn-outline-secondary rename-btn me-1" data-name="<?php echo htmlspecialchars($item) ?>" title="重命名"><i class="bi bi-pencil"></i></button>
                                        <button type="button" class="btn btn-sm btn-outline-danger delete-btn me-1" data-name="<?php echo htmlspecialchars($item) ?>" title="删除"><i class="bi bi-trash"></i></button>
                                        <a href="?fm_download=<?php echo urlencode($item) ?>&fm_dir=<?php echo urlencode($fm_cur) ?>" class="btn btn-sm btn-outline-success" title="下载"><i class="bi bi-download"></i></a>
                                    </td>
                                </tr> <?php endforeach; ?><?php if (empty($fm_all)): ?><tr>
                                    <td colspan="4" class="text-center text-muted py-4">目录为空</td>
                                </tr><?php endif; ?> </tbody>
                        </table>
                    </div>
                    <div class="d-flex gap-2 mt-2">
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="fmBatch('delete')">批量删除</button>
                        <button type="button" class="btn btn-outline-success btn-sm" onclick="fmBatch('download')">批量下载</button>
                    </div>
                </form>
            </div>
        </div> <?php endif; ?> <?php if ($active_tab === 'api'): ?> <div class="card">
            <div class="card-header">
                <h5 class="mb-0">API 接口预览</h5>
            </div>
            <div class="card-body">
                <div class="row g-3"> <?php foreach ($api_list as $api): ?><div class="col-12">
                        <div class="d-flex align-items-center gap-2 api-box"><span class="text-truncate flex-grow-1"><?php echo htmlspecialchars($api['name']) ?>.json<br><small class="text-muted"><?php echo htmlspecialchars($api['url']) ?></small></span><button
                                class="btn btn-sm btn-outline-primary copy-btn" data-url="<?php echo htmlspecialchars($api['url']) ?>">复制</button></div>
                    </div><?php endforeach; ?> <?php if (empty($api_list)): ?><p class="text-muted">暂无歌单文件</p><?php endif; ?> </div>
                <hr>
                <p class="small text-muted">调用示例：<code>fetch('API_URL').then(r=>r.json()).then(console.log)</code></p>
            </div>
        </div> <?php endif; ?> <?php if ($active_tab === 'preview'): ?> <div class="card">
            <div class="card-header">
                <h5 class="mb-0">主页预览</h5>
            </div>
            <div class="card-body p-0"><iframe src="<?php echo htmlspecialchars(INDEX_PAGE) ?>" style="width:100%;height:650px;border:0"></iframe></div>
        </div> <?php endif; ?> </div>
    <script src="https://unpkg.com/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ================= 歌单预览 =================
function updatePreview() {
    const e = document.getElementById('playlistEditor'), p = document.getElementById('playlistPreview');
    if (!e || !p) return;
    try {
        const d = JSON.parse(e.value);
        if (!Array.isArray(d)) throw new Error('根元素必须是数组');
        p.innerHTML = d.length ? d.map(s => `<div class="border-bottom pb-2 mb-2"><div class="fw-bold">${s.name || '未知'}</div><div class="text-muted small">${s.artist || '未知'}</div></div>`).join('') : '<p class="text-muted text-center mt-4">暂无歌曲</p>';
    } catch (e) {
        p.innerHTML = `<p class="text-danger p-2">JSON 错误: ${e.message}</p>`;
    }
}
document.getElementById('playlistEditor')?.addEventListener('input', updatePreview);
window.addEventListener('DOMContentLoaded', updatePreview);

function formatJSON() {
    try { document.getElementById('playlistEditor').value = JSON.stringify(JSON.parse(document.getElementById('playlistEditor').value), null, 2); updatePreview(); } catch (e) { alert('格式错误'); }
}

document.querySelectorAll('form').forEach(f => f.addEventListener('submit', () => {
    const h = document.getElementById('playlistDataHidden');
    if (h && document.getElementById('playlistEditor')) h.value = document.getElementById('playlistEditor').value;
}));

// ================= 文件管理交互 =================
document.getElementById('fmSelectAll')?.addEventListener('change', function () {
    document.querySelectorAll('input[name="sel[]"]').forEach(c => c.checked = this.checked);
});

function fmBatch(action) {
    const sel = [...document.querySelectorAll('input[name="sel[]"]:checked')].map(c => c.value);
    if (sel.length === 0) return alert('请先勾选要操作的项目');
    if (action === 'delete') {
        if (!confirm('确定删除选中项及其内容？此操作不可恢复。')) return;
        const fd = new FormData(); fd.append('items', JSON.stringify(sel));
        fetch(`?ajax=1&fm_dir=<?php echo urlencode($fm_cur) ?>&action=delete`, { method: 'POST', body: fd })
            .then(r => r.json()).then(res => { alert(res.msg); if (res.success) location.reload(); });
    } else if (action === 'download') {
        const form = document.createElement('form');
        form.method = 'POST'; form.action = '';
        const dirInput = document.createElement('input'); dirInput.type = 'hidden'; dirInput.name = 'batch_download_dir'; dirInput.value = '<?php echo htmlspecialchars($fm_cur) ?>';
        const itemsInput = document.createElement('input'); itemsInput.type = 'hidden'; itemsInput.name = 'batch_download_items'; itemsInput.value = JSON.stringify(sel);
        form.appendChild(dirInput); form.appendChild(itemsInput);
        document.body.appendChild(form); form.submit(); document.body.removeChild(form);
    }
}

document.querySelectorAll('.rename-btn').forEach(btn => btn.addEventListener('click', function () {
    const old = this.dataset.name;
    const newName = prompt('输入新名称:', old);
    if (newName && newName !== old) {
        const fd = new FormData(); fd.append('old', old); fd.append('new', newName);
        fetch(`?ajax=1&fm_dir=<?php echo urlencode($fm_cur) ?>&action=rename`, { method: 'POST', body: fd })
            .then(r => r.json()).then(res => { alert(res.msg); if (res.success) location.reload(); });
    }
}));

function createFolder() {
    const input = document.getElementById('newFolderName');
    const name = input.value.trim();
    if (!name) return alert('请输入文件夹名称');
    const fd = new FormData(); fd.append('folder_name', name);
    fetch(`?ajax=1&fm_dir=<?php echo urlencode($fm_cur) ?>&action=create_folder`, { method: 'POST', body: fd })
        .then(r => r.json()).then(res => { alert(res.msg); if (res.success) { input.value = ''; location.reload(); } })
        .catch(() => alert('请求失败'));
}

// 移除无效的 .delete-folder-btn 绑定，删除由 fmBatch 统一处理

// 仅在元素存在时绑定拖拽上传事件，解决 null 问题
const dropZone = document.getElementById('drop-zone');
const fileInput = document.getElementById('fileInput');
const progressCont = document.getElementById('progress-container');

if (dropZone) {
    ['dragenter', 'dragover'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.add('dragover'); }));
    ['dragleave', 'drop'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.remove('dragover'); }));
    dropZone.addEventListener('drop', e => handleFiles(e.dataTransfer.files));
}
if (fileInput) {
    fileInput.addEventListener('change', e => handleFiles(e.target.files));
}

function handleFiles(files) { if (!files.length) return; Array.from(files).forEach(uploadFile); }
let reloadTriggered = false;
function uploadFile(file) {
    if (!dropZone || !progressCont) return;
    const id = 'prog_' + Math.random().toString(36).substr(2);
    progressCont.insertAdjacentHTML('beforeend', `<div id="${id}" class="progress-item d-flex align-items-center gap-2"><span class="text-truncate" style="max-width:60%">${file.name}</span><div class="progress flex-grow-1" style="height:6px"><div class="progress-bar" role="progressbar" style="width:0%"></div></div><span class="small">0%</span></div>`);
    const fd = new FormData(); fd.append('fm_files', file);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', `?ajax=1&fm_dir=<?php echo urlencode($fm_cur) ?>&action=upload`);
    xhr.upload.onprogress = e => {
        if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 100);
            const el = document.getElementById(id);
            el.querySelector('.progress-bar').style.width = pct + '%';
            el.querySelector('span:last-child').textContent = pct + '%';
            if (pct === 100) el.querySelector('.progress-bar').classList.add('bg-success');
        }
    };
    xhr.onload = () => {
        const el = document.getElementById(id);
        try {
            if (xhr.status === 200) {
                const res = JSON.parse(xhr.responseText);
                el.querySelector('span:last-child').textContent = res.success ? '✅ 完成' : '❌ ' + res.msg;
                if (res.success && !reloadTriggered) { reloadTriggered = true; setTimeout(() => location.reload(), 800); }
            } else { el.querySelector('span:last-child').textContent = `❌ HTTP ${xhr.status}`; }
        } catch (e) { console.error('解析失败:', xhr.responseText); el.querySelector('span:last-child').textContent = '❌ 响应格式错误'; }
    };
    xhr.onerror = () => { document.getElementById(id).querySelector('span:last-child').textContent = '❌ 网络错误'; };
    xhr.send(fd);
}

document.querySelectorAll('.copy-btn').forEach(b => b.addEventListener('click', function () {
    navigator.clipboard.writeText(this.dataset.url).then(() => {
        const o = this.innerHTML; this.innerHTML = '✅'; setTimeout(() => this.innerHTML = o, 1000);
    });
}));
</script>
</body>
</html>