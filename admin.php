<?php
// ================= 配置区域 =================
$_s_path = __DIR__ . '/settings.json';
$_s_data = file_exists($_s_path) ? json_decode(file_get_contents($_s_path), true) : [];
$def_pl = $_s_data['playlist_dir'] ?? __DIR__ . '/playlists';
$def_as = $_s_data['localmedia_dir'] ?? __DIR__ . '/localmedia';
// 统一转换为绝对路径
if (preg_match('/^\.\//', $def_pl)) $def_pl = __DIR__ . '/' . substr($def_pl, 2);
if (preg_match('/^\.\//', $def_as)) $def_as = __DIR__ . '/' . substr($def_as, 2);
// 确保目录存在
if (!is_dir($def_pl)) mkdir($def_pl, 0755, true);
if (!is_dir($def_as)) mkdir($def_as, 0755, true);
// 定义常量（供全局使用）
define('PLAYLIST_DIR', realpath($def_pl) ?: $def_pl);
define('FM_ROOT', realpath($def_as) ?: $def_as);
define('SETTINGS_FILE', __DIR__ . '/settings.json');
define('BACKUP_DIR', PLAYLIST_DIR . '/backups');
define('ADMIN_USER', 'admin');
define('ADMIN_PASS_HASH', password_hash('123456', PASSWORD_DEFAULT));
define('INDEX_PAGE', 'index.html');
// 全局默认值配置（设置页未保存时自动 fallback 至此）
define('DEFAULT_COVER', './localmedia/default.jpg');
define('DEFAULT_LRC', './localmedia/default.lrc');
define('DEFAULT_TITLE', '未知曲目');
define('DEFAULT_ARTIST', '未知歌手');
define('DEFAULT_URL', './localmedia/default.mp3');
define('PARSE_SEPARATOR', '-');
define('PARSE_FORMAT', 'title_artist');
// 静态资源管理
define('ASSET_FAVICON', './assets/favicon.png');
define('ASSET_BOOTSTRAP_CSS', 'https://unpkg.com/bootstrap@5.3.3/dist/css/bootstrap.min.css');
define('ASSET_BOOTSTRAP_JS',  'https://unpkg.com/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js');
define('ASSET_BOOTSTRAP_ICONS', 'https://unpkg.com/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css');
// ============================================
if (!class_exists('ZipArchive')) die('错误：服务器未启用 ZipArchive 扩展。');
session_start();

// ========== 独立下载处理器 (修复单文件下载跳转) ==========
if (isset($_GET['fm_download']) && isset($_GET['fm_dir'])) {
    $fm_cur = $_GET['fm_dir'];
    $fm_path = fm_sanitize_path($fm_cur);
    $download_file = $_GET['fm_download'];
    $target = $fm_path ? $fm_path . '/' . basename($download_file) : false;
    if ($target && is_file($target)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . addslashes(basename($target)) . '"');
        header('Content-Length: ' . filesize($target));
        header('Cache-Control: no-store');
        readfile($target);
        exit;
    }
    echo '文件不存在或路径无效'; exit;
}

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
            $conflict_mode = $_POST['conflict_mode'] ?? 'overwrite';
            $files = $_FILES['fm_files'] ?? [];
            if (empty($files['tmp_name'])) throw new Exception('未接收到文件数据');
            if (!is_array($files['tmp_name'])) {
                $files = ['tmp_name'=>[$files['tmp_name']], 'name'=>[$files['name']], 'error'=>[$files['error']], 'size'=>[isset($files['size'])?$files['size']:0]];
            }
            $count = 0;
            foreach ($files['tmp_name'] as $i => $tmp) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $clean_name = fm_clean_name($files['name'][$i]);
                    $dest = $fm_path . '/' . $clean_name;

                    if ($conflict_mode === 'skip' && file_exists($dest)) {
                        $count++; continue;
                    } elseif ($conflict_mode === 'rename' && file_exists($dest)) {
                        $info = pathinfo($clean_name);
                        $base = $info['filename'];
                        $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
                        $counter = 1;
                        while (file_exists($dest)) {
                            $dest = $fm_path . '/' . $base . '_' . $counter . $ext;
                            $counter++;
                        }
                    }
                    if (!move_uploaded_file($tmp, $dest)) throw new Exception("移动失败: " . basename($files['name'][$i]));
                    $count++;
                }
            }
            $resp = ['success'=>true, 'msg'=>"成功处理 {$count} 个文件"];
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
        } elseif ($action === 'get_settings') {
            $defaults = [
                'playlist_dir' => str_replace(__DIR__, '.', PLAYLIST_DIR),
                'localmedia_dir' => str_replace(__DIR__, '.', FM_ROOT),
                'default_cover' => DEFAULT_COVER, 'default_lrc' => DEFAULT_LRC,
                'default_title' => DEFAULT_TITLE, 'default_artist' => DEFAULT_ARTIST,
                'default_url' => DEFAULT_URL,
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
        } elseif ($action === 'delete_backup') {
            $file = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $_POST['file'] ?? '');
            $path = BACKUP_DIR . '/' . $file;
            if (!file_exists($path)) throw new Exception('备份文件不存在');
            if (!unlink($path)) throw new Exception('删除失败');
            $resp = ['success' => true, 'msg' => '删除成功'];
        } elseif ($action === 'create_playlist') {
            $name = preg_replace('/[^a-zA-Z0-9_-]/u', '', trim($_POST['name'] ?? ''));
            if ($name === '') throw new Exception('名称不能为空');
            if (file_exists(PLAYLIST_DIR . "/{$name}.json")) throw new Exception('歌单已存在');
            file_put_contents(PLAYLIST_DIR . "/{$name}.json", '[]');
            $resp = ['success' => true, 'msg' => '创建成功', 'name' => $name];
        } elseif ($action === 'rename_playlist') {
            $old = preg_replace('/[^a-zA-Z0-9_-]/u', '', trim($_POST['old'] ?? ''));
            $new = preg_replace('/[^a-zA-Z0-9_-]/u', '', trim($_POST['new'] ?? ''));
            if ($old === '' || $new === '') throw new Exception('名称无效');
            if (!file_exists(PLAYLIST_DIR . "/{$old}.json")) throw new Exception('原歌单不存在');
            if (file_exists(PLAYLIST_DIR . "/{$new}.json")) throw new Exception('目标名称已存在');
            rename(PLAYLIST_DIR . "/{$old}.json", PLAYLIST_DIR . "/{$new}.json");
            $resp = ['success' => true, 'msg' => '重命名成功', 'new_name' => $new];
        } elseif ($action === 'delete_playlist') {
            $name = preg_replace('/[^a-zA-Z0-9_-]/u', '', trim($_POST['name'] ?? ''));
            if ($name === '') throw new Exception('名称无效');
            if (!file_exists(PLAYLIST_DIR . "/{$name}.json")) throw new Exception('歌单不存在');
            unlink(PLAYLIST_DIR . "/{$name}.json");
            $resp = ['success' => true, 'msg' => '删除成功'];
        } elseif ($action === 'save_playlist') {
            $name = preg_replace('/[^a-zA-Z0-9_-]/u', '', trim($_GET['playlist'] ?? 'default'));
            if ($name === '') throw new Exception('无效的歌单名称');
            $input = json_decode(file_get_contents('php://input'), true);
            $data = $input['data'] ?? [];
            if (!is_array($data)) throw new Exception('无效的数据格式');
            $file = PLAYLIST_DIR . "/{$name}.json";
            if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
                throw new Exception('保存失败');
            }
            $resp = ['success' => true, 'msg' => '保存成功'];
        } elseif ($action === 'restore_backup') {
            $file = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $_POST['file'] ?? '');
            $path = BACKUP_DIR . '/' . $file;
            if (!file_exists($path)) throw new Exception('备份文件不存在');
            $resp = ['success' => true, 'data' => file_get_contents($path)];
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
<!DOCTYPE html>
<html lang="zh-CN">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="icon" type="image/x-icon" href="<?php echo ASSET_FAVICON; ?>">
	<title>登录</title>
	<link href="<?php echo ASSET_BOOTSTRAP_CSS; ?>" rel="stylesheet">
	<link href="./assets/style_login.css" rel="stylesheet">
</head>
<body>
	<div class="card shadow">
		<div class="card-body">
			<h4 class="text-center mb-4">音乐服务器后台</h4> <?php if (!empty($login_error)): ?><div class="alert alert-danger"><?php echo esc_html($login_error) ?></div><?php endif; ?> <form method="post"><input type="hidden" name="do_login" value="1">
				<div class="mb-3"><label class="form-label">用户名</label><input type="text" name="username" class="form-control" required autofocus></div>
				<div class="mb-3"><label class="form-label">密码</label><input type="password" name="password" class="form-control" required></div>
				<button type="submit" class="btn btn-primary w-100">登录</button>
			</form>
		</div>
	</div>
</body>
</html> <?php exit; }

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
<!DOCTYPE html>
<html lang="zh-CN">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="icon" type="image/x-icon" href="<?php echo ASSET_FAVICON; ?>">
	<title>音乐服务器管理</title>
	<link href="<?php echo ASSET_BOOTSTRAP_CSS; ?>" rel="stylesheet">
	<link href="<?php echo ASSET_BOOTSTRAP_ICONS; ?>" rel="stylesheet">
	<link href="./assets/style_admin.css" rel="stylesheet">
</head>
<body class="bg-light">
	<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-3 sticky-top">
		<div class="container-fluid">
			<a class="navbar-brand" href="#">音乐服务器后台</a>
			<button class="navbar-toggler border-0" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu"><span class="navbar-toggler-icon"></span></button>
			<div class="offcanvas offcanvas-lg offcanvas-start text-bg-dark" tabindex="-1" id="sidebarMenu">
				<div class="offcanvas-header border-bottom border-secondary">
					<h5 class="offcanvas-title">导航菜单</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#sidebarMenu"></button>
				</div>
				<div class="offcanvas-body d-flex flex-column flex-lg-row align-items-lg-center">
					<ul class="navbar-nav flex-grow-1 flex-lg-row mb-2 mb-lg-0 gap-2">
						<li class="nav-item"><a class="nav-link px-2 <?php echo $active_tab==='playlist'?'active bg-primary text-white rounded':''?>" href="?tab=playlist">歌单管理</a></li>
						<li class="nav-item"><a class="nav-link px-2 <?php echo $active_tab==='files'?'active bg-primary text-white rounded':''?>" href="?tab=files">文件管理</a></li>
						<li class="nav-item"><a class="nav-link px-2 <?php echo $active_tab==='api'?'active bg-primary text-white rounded':''?>" href="?tab=api">API 预览</a></li>
						<li class="nav-item"><a class="nav-link px-2 <?php echo $active_tab==='settings'?'active bg-primary text-white rounded':''?>" href="?tab=settings">系统设置</a></li>
					</ul><a href="?logout" class="btn btn-outline-light btn-sm ms-lg-auto mt-2 mt-lg-0">退出登录</a>
				</div>
			</div>
		</div>
	</nav>
	<div class="container pb-5">
		<div id="toast-container" class="position-fixed bottom-0 end-0 p-3" style="z-index:1055"></div> <?php if ($active_tab === 'playlist'): ?> <div class="card shadow-sm mb-4">
			<div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
				<h5 class="mb-0"><i class="bi bi-music-note-beam me-2"></i>歌单管理</h5>
				<form method="get" class="d-flex gap-2 m-0"><input type="hidden" name="tab" value="playlist">
					<select name="playlist" id="playlistSelect" class="form-select form-select-sm" onchange="this.form.submit()"><?php foreach ($playlists as $pl): ?><option value="<?php echo esc_html($pl) ?>" <?php echo $pl===$selected?'selected':'' ?>><?php echo esc_html($pl) ?></option>
						<?php endforeach; ?></select>
				</form>
				<div class="d-flex flex-wrap gap-2 align-items-center">
					<button class="btn btn-sm btn-outline-success" onclick="createPlaylist()"><i class="bi bi-plus-lg"></i> 新建歌单</button>
					<button class="btn btn-sm btn-outline-warning" onclick="renamePlaylist('<?php echo esc_html($selected) ?>')"><i class="bi bi-pencil"></i> 重命名</button>
					<button class="btn btn-sm btn-outline-danger" onclick="deletePlaylist('<?php echo esc_html($selected) ?>')" <?php echo count($playlists)<=1?'disabled title="至少保留一个歌单"':''?>><i class="bi bi-trash"></i> 删除歌单</button>
					<div class="vr d-none d-md-block"></div>
					<button class="btn btn-sm btn-outline-success" onclick="openAddModal()"><i class="bi bi-plus-lg"></i> 添加曲目</button>
					<button class="btn btn-sm btn-outline-primary" onclick="autoAddTracks()"><i class="bi bi-lightning-charge"></i> 智能添加</button>
					<button class="btn btn-sm btn-outline-danger" onclick="batchDelete()"><i class="bi bi-trash"></i> 批量删曲</button>
					<div class="vr d-none d-md-block"></div>
					<button class="btn btn-sm btn-outline-secondary" id="sortToggleBtn" onclick="toggleSortMode()"><i class="bi bi-arrow-down-up"></i> 顺序编辑</button>
					<button class="btn btn-sm btn-success" onclick="savePlaylist()"><i class="bi bi-save"></i> 保存</button>
					<div class="vr d-none d-md-block"></div>
					<div class="btn-group btn-group-sm"><button class="btn btn-outline-secondary" onclick="importJson()"><i class="bi bi-upload"></i> 导入</button>
						<button class="btn btn-outline-secondary" onclick="exportJson()"><i class="bi bi-download"></i> 导出</button>
						<button class="btn btn-outline-secondary" onclick="showBackups()"><i class="bi bi-archive"></i> 备份</button>
					</div>
				</div>
			</div>
			<div class="card-body p-0">
				<div class="table-responsive">
					<table class="table table-hover align-middle mb-0 w-100 user-select-none" id="playlistTable">
						<thead class="table-light">
							<tr>
								<th width="40"><input type="checkbox" id="plSelectAll" class="form-check-input"></th>
								<th>曲名</th>
								<th>艺术家</th>
								<th class="d-none d-md-table-cell">音频路径</th>
								<th class="d-none d-md-table-cell">歌词</th>
								<th class="d-none d-md-table-cell">封面</th>
								<th class="sticky-col"><i class="bi bi-list-check"></i></th>
							</tr>
						</thead>
						<tbody id="playlistBody"></tbody>
					</table>
				</div>
				<div id="emptyState" class="text-center py-5 text-muted d-none">暂无曲目，请点击“添加”或“智能添加”</div>
			</div>
			<div class="card-footer bg-transparent">
				<details class="small">
					<summary class="text-muted cursor-pointer"><i class="bi bi-code-slash"></i> JSON 编辑</summary>
					<textarea id="jsonEditor" class="form-control font-monospace mt-2 bg-light" rows="6" spellcheck="false" placeholder="JSON 格式数据..."><?php echo esc_html($playlist_data) ?></textarea>
				</details>
			</div>
		</div>
		<div class="modal fade" id="trackModal" tabindex="-1">
			<div class="modal-dialog modal-lg">
				<div class="modal-content">
					<div class="modal-header">
						<h5 class="modal-title" id="trackModalTitle">添加曲目</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
					</div>
					<div class="modal-body"><input type="hidden" id="editIndex" value="-1">
						<div class="row g-3">
							<div class="col-md-6"><label class="form-label">曲名</label><input type="text" id="inpName" class="form-control"></div>
							<div class="col-md-6"><label class="form-label">艺术家</label><input type="text" id="inpArtist" class="form-control"></div>
							<div class="col-md-6"><label class="form-label">音频路径</label><input type="text" id="inpUrl" class="form-control"></div>
							<div class="col-md-6"><label class="form-label">歌词路径</label><input type="text" id="inpLrc" class="form-control"></div>
							<div class="col-md-12"><label class="form-label">封面路径</label><input type="text" id="inpPic" class="form-control"></div>
						</div>
					</div>
					<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button><button type="button" class="btn btn-primary" onclick="saveTrack()">保存</button></div>
				</div>
			</div>
		</div>
		<div class="modal fade" id="backupModal" tabindex="-1">
			<div class="modal-dialog">
				<div class="modal-content">
					<div class="modal-header">
						<h5 class="modal-title">备份管理</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
					</div>
					<div class="modal-body"><button class="btn btn-outline-primary btn-sm mb-3" onclick="createBackup()"><i class="bi bi-plus-circle"></i> 立即备份</button>
						<div class="list-group" id="backupList">
							<div class="text-muted">加载中...</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<div class="modal fade" id="duplicateModal" tabindex="-1">
			<div class="modal-dialog modal-dialog-centered">
				<div class="modal-content">
					<div class="modal-header">
						<h5 class="modal-title">发现重复曲目</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
					</div>
					<div class="modal-body">
                        <p class="mb-2">当前歌单已有曲目，请选择处理方式：</p>
                        <ul class="small text-muted ps-3 mb-0">
                            <li><b>覆盖</b>：清空现有歌单，完全替换为本次添加的曲目</li>
                            <li><b>合并</b>：保留现有曲目，追加本次新曲目（自动跳过重复项）</li>
                            <li><b>取消</b>：放弃本次添加操作</li>
                        </ul>
                    </div>
					<div class="modal-footer justify-content-start gap-2">
						<button type="button" class="btn btn-warning" id="btnDupOverwrite">覆盖</button><button type="button" class="btn btn-info" id="btnDupMerge">去重合并</button>
						<button type="button" class="btn btn-secondary" id="btnDupCancel">取消</button>
					</div>
				</div>
			</div>
		</div> <?php elseif ($active_tab === 'files'): ?> <div class="card shadow-sm mb-4">
			<div class="card-header d-flex justify-content-between">
				<h5 class="mb-0">文件管理</h5><small class="text-muted">当前: /<?php echo esc_html($fm_cur ?: 'localmedia') ?></small>
			</div>
			<div class="card-body">
				<nav class="breadcrumb mb-2"><a class="breadcrumb-item" href="?tab=files&fm_dir=">根目录</a><?php if ($fm_cur): $parts = explode('/', trim($fm_cur, '/')); $path=''; foreach ($parts as $i => $p): $path .= ($i?'/':'') . $p; ?><a class="breadcrumb-item"
						href="?tab=files&fm_dir=<?php echo urlencode($path) ?>"><?php echo esc_html($p) ?></a><?php endforeach; endif; ?></nav>
				<!-- 上传冲突处理单选框 -->
				<div class="d-flex flex-wrap gap-3 mb-3 align-items-center bg-light p-2 rounded">
					<span class="small fw-bold me-1">上传冲突处理：</span>
					<div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="conflict_mode" id="mode_overwrite" value="overwrite" checked><label class="form-check-label small" for="mode_overwrite">覆盖</label></div>
					<div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="conflict_mode" id="mode_skip" value="skip"><label class="form-check-label small" for="mode_skip">跳过</label></div>
					<div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="conflict_mode" id="mode_rename" value="rename"><label class="form-check-label small" for="mode_rename">保留(自动重命名)</label></div>
				</div>
				<div id="drop-zone" class="border border-2 border-dashed rounded p-4 text-center bg-light mb-3" style="cursor:pointer"><i class="bi bi-cloud-arrow-up fs-2 text-primary"></i>
					<p class="mb-0 mt-2">拖拽文件到此处，或 <a href="#" onclick="document.getElementById('fileInput').click()">点击选择</a></p><input type="file" id="fileInput" multiple hidden>
					<div id="progress-container" class="mt-2"></div>
				</div>
				<div class="d-flex gap-2 mb-3 align-items-center"><input type="text" id="newFolderName" class="form-control form-control-sm" placeholder="新文件夹名" maxlength="50"><button type="button" class="btn btn-outline-success btn-sm text-nowrap" onclick="createFolder()">新建</button></div>
				<form id="fmForm"><input type="hidden" name="fm_dir" value="<?php echo esc_html($fm_cur) ?>">
					<div class="table-responsive">
						<table class="table table-hover table-sm align-middle">
							<thead>
								<tr>
									<th width="30"><input type="checkbox" id="fmSelectAll" class="form-check-input"></th>
									<th>名称</th>
									<th>大小</th>
									<th width="180">操作</th>
								</tr>
							</thead>
							<tbody> <?php if ($fm_cur !== ''): ?><tr>
									<td></td>
									<td colspan="3"><a href="?tab=files&fm_dir=<?php echo urlencode($fm_parent) ?>" class="text-decoration-none fw-bold"><i class="bi bi-folder2-open"></i> 上级目录</a></td>
								</tr><?php endif; ?>
								<?php foreach ($fm_all as $item): $is_dir = is_dir($fm_path . '/' . $item); $next = $fm_cur === '' ? $item : $fm_cur . '/' . $item; $raw_size = $is_dir ? 0 : filesize($fm_path . '/' . $item); $size = $is_dir ? '-' : ($raw_size < 1048576 ? round($raw_size/1024,1).' KB' : round($raw_size/1048576,2).' MB'); ?>
								<tr data-name="<?php echo esc_html($item) ?>">
									<td><input type="checkbox" name="sel[]" value="<?php echo esc_html($item) ?>" class="form-check-input"></td>
									<td><?php if ($is_dir): ?><a href="?tab=files&fm_dir=<?php echo urlencode($next) ?>" class="text-decoration-none fw-bold"><i class="bi bi-folder"></i> <?php echo esc_html($item) ?></a><?php else: ?><i
											class="bi bi-file-earmark-music"></i><?php echo esc_html($item) ?><?php endif; ?></td>
									<td class="text-muted small"><?php echo $size ?></td>
									<td class="text-nowrap"><button type="button" class="btn btn-sm btn-outline-secondary rename-btn me-1" data-name="<?php echo esc_html($item) ?>"><i class="bi bi-pencil"></i></button><button type="button" class="btn btn-sm btn-outline-danger delete-btn me-1"
											data-name="<?php echo esc_html($item) ?>"><i class="bi bi-trash"></i></button><a href="?fm_download=<?php echo urlencode($item) ?>&fm_dir=<?php echo urlencode($fm_cur) ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-download"></i></a></td>
								</tr> <?php endforeach; ?><?php if (empty($fm_all)): ?><tr>
									<td colspan="4" class="text-center text-muted py-4">目录为空</td>
								</tr><?php endif; ?> </tbody>
						</table>
					</div>
					<div class="d-flex gap-2 mt-2"><button type="button" class="btn btn-outline-danger btn-sm" onclick="fmBatch('delete')"><i class="bi bi-trash"></i> 批量删除</button><button type="button" class="btn btn-outline-success btn-sm" onclick="fmBatch('download')"><i
								class="bi bi-download"></i> 批量下载</button></div>
				</form>
			</div>
		</div> <?php elseif ($active_tab === 'api'): ?> <div class="card shadow-sm mb-4">
			<div class="card-header">
				<h5 class="mb-0">API 接口预览</h5>
			</div>
			<div class="card-body">
				<div class="row g-3"> <?php foreach ($api_list as $api): ?><div class="col-12">
						<div class="d-flex align-items-center gap-2 p-2 bg-light rounded"><span class="text-truncate flex-grow-1"><strong><?php echo esc_html($api['name']) ?>.json</strong><br><small class="text-muted"><?php echo esc_html($api['url']) ?></small></span><button
								class="btn btn-sm btn-outline-primary copy-btn" data-url="<?php echo esc_html($api['url']) ?>">复制</button></div>
					</div><?php endforeach; ?> <?php if (empty($api_list)): ?><p class="text-muted">暂无歌单文件</p><?php endif; ?> </div>
				<hr>
				<p class="small text-muted">调用示例：<code>fetch('API_URL').then(r=>r.json()).then(console.log)</code></p>
			</div>
		</div> <?php elseif ($active_tab === 'settings'): ?> <div class="card shadow-sm mb-4">
			<div class="card-header">
				<h5 class="mb-0"><i class="bi bi-gear"></i> 系统设置</h5>
			</div>
			<div class="card-body">
				<form id="settingsForm">
					<h6 class="border-bottom pb-2">目录配置</h6>
					<div class="row g-3 mb-3">
						<div class="col-md-6"><label class="form-label">歌单目录</label><input type="text" name="playlist_dir" class="form-control" placeholder="./playlists"></div>
						<div class="col-md-6"><label class="form-label">资源目录</label><input type="text" name="localmedia_dir" class="form-control" placeholder="./localmedia"></div>
					</div>
					<h6 class="border-bottom pb-2">默认值配置</h6>
					<div class="row g-3 mb-3">
						<div class="col-md-4"><label class="form-label">默认音频路径</label><input type="text" name="default_url" class="form-control"></div>
						<div class="col-md-4"><label class="form-label">默认封面路径</label><input type="text" name="default_cover" class="form-control"></div>
						<div class="col-md-4"><label class="form-label">默认歌词路径</label><input type="text" name="default_lrc" class="form-control"></div>
						<div class="col-md-4"><label class="form-label">默认曲名</label><input type="text" name="default_title" class="form-control"></div>
						<div class="col-md-4"><label class="form-label">默认艺术家</label><input type="text" name="default_artist" class="form-control"></div>
					</div>
					<h6 class="border-bottom pb-2 mt-4">智能解析规则</h6>
					<div class="row g-3 mb-3">
						<div class="col-md-4"><label class="form-label">分隔符</label><input type="text" name="parse_separator" class="form-control"></div>
						<div class="col-md-4"><label class="form-label">文件名格式</label><select name="parse_format" class="form-select">
								<option value="title_artist">曲名 - 艺术家</option>
								<option value="artist_title">艺术家 - 曲名</option>
							</select></div>
					</div>
					<button type="button" class="btn btn-primary" onclick="saveSettings()"><i class="bi bi-save"></i> 保存设置</button>
				</form>
			</div> <?php endif; ?>
		</div>
		<script src="<?php echo ASSET_BOOTSTRAP_JS; ?>"></script>
		<script src="./assets/script_admin.js"></script>
</body>
</html>