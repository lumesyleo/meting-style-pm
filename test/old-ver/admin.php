<?php
// ========== 文件管理器配置 ==========
define('FILE_MANAGER_ROOT', './assets/'); // 只允许管理此目录及子目录
$fm_action = $_GET['fm_action'] ?? '';
$current_path = isset($_GET['path']) ? $_GET['path'] : '';
$current_path = ltrim($current_path, '/\\'); // 去除开头斜杠

// 安全函数：确保路径在 FILE_MANAGER_ROOT 内
function sanitizePath($path) {
    $real_root = realpath(FILE_MANAGER_ROOT);
    $target = realpath(FILE_MANAGER_ROOT . $path);
    if ($target === false || strpos($target, $real_root) !== 0) {
        return false;
    }
    return $target;
}

// 获取当前目录（安全）
$full_path = sanitizePath($current_path);
if ($full_path === false) {
    $full_path = realpath(FILE_MANAGER_ROOT);
    $current_path = '';
}

// ========== 文件管理操作处理 ==========
$message_fm = '';
$error_fm = '';

// 上传文件
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload_files'])) {
    $upload_dir = $full_path . '/';
    foreach ($_FILES['upload_files']['name'] as $key => $name) {
        if ($_FILES['upload_files']['error'][$key] === UPLOAD_ERR_OK) {
            $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
            move_uploaded_file($_FILES['upload_files']['tmp_name'][$key], $upload_dir . $safe_name);
        }
    }
    $message_fm = "文件上传成功！";
}

// 新建文件夹
if (isset($_POST['create_folder'])) {
    $folder_name = trim($_POST['folder_name']);
    if ($folder_name && preg_match('/^[a-zA-Z0-9._-]+$/', $folder_name)) {
        mkdir($full_path . '/' . $folder_name, 0755, true);
        $message_fm = "文件夹 '{$folder_name}' 创建成功！";
    } else {
        $error_fm = "文件夹名称无效！仅允许字母、数字、下划线、连字符和点。";
    }
}

// 重命名
if (isset($_POST['rename_old']) && isset($_POST['rename_new'])) {
    $old = $full_path . '/' . basename($_POST['rename_old']);
    $new = $full_path . '/' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_POST['rename_new']);
    if (file_exists($old) && !file_exists($new)) {
        rename($old, $new);
        $message_fm = "重命名成功！";
    } else {
        $error_fm = "重命名失败：文件不存在或目标已存在。";
    }
}

// 删除（单个或批量）
if (isset($_POST['delete_items']) && is_array($_POST['delete_items'])) {
    foreach ($_POST['delete_items'] as $item) {
        $path = $full_path . '/' . basename($item);
        if (is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            // 递归删除目录
            function rrmdir($dir) {
                if (is_dir($dir)) {
                    $objects = scandir($dir);
                    foreach ($objects as $object) {
                        if ($object != "." && $object != "..") {
                            if (is_dir($dir . "/" . $object)) rrmdir($dir . "/" . $object);
                            else unlink($dir . "/" . $object);
                        }
                    }
                    rmdir($dir);
                }
            }
            rrmdir($path);
        }
    }
    $message_fm = "所选项目已删除！";
}

// 下载（单个文件）
if (isset($_GET['download'])) {
    $file = $full_path . '/' . basename($_GET['download']);
    if (is_file($file)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
}

// 打包下载（多个）
if (isset($_POST['download_items']) && is_array($_POST['download_items'])) {
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        $zip_name = tempnam(sys_get_temp_dir(), 'fm_') . '.zip';
        if ($zip->open($zip_name, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            foreach ($_POST['download_items'] as $item) {
                $path = $full_path . '/' . basename($item);
                if (is_file($path)) {
                    $zip->addFile($path, basename($path));
                } elseif (is_dir($path)) {
                    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
                    foreach ($iterator as $file) {
                        if ($file->isFile()) {
                            $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($full_path) + 1));
                        }
                    }
                }
            }
            $zip->close();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="files_' . date('YmdHis') . '.zip"');
            header('Content-Length: ' . filesize($zip_name));
            readfile($zip_name);
            unlink($zip_name);
            exit;
        }
    } else {
        $error_fm = "服务器未安装 ZipArchive，无法打包下载。";
    }
}

// ===== 文件管理结束 =====

session_start();

// ===== 配置登录凭据 =====
$ADMIN_USER = 'admin';      // 请修改为你自己的用户名
$ADMIN_PASS = 'A@142857';     // 请修改为你自己的密码

// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// 处理登录
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';
    if ($user === $ADMIN_USER && $pass === $ADMIN_PASS) {
        $_SESSION['logged_in'] = true;
        header('Location: ' . $_SERVER['PHP_SELF'] . (isset($_GET['playlist']) ? '?playlist=' . urlencode($_GET['playlist']) : ''));
        exit;
    } else {
        $login_error = '用户名或密码错误！';
    }
}

// 未登录：显示登录页面
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>登录 - 多歌单管理器</title>
        <style>
            body {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }
            .login-box {
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.2);
                width: 100%;
                max-width: 400px;
            }
            .login-box h2 {
                text-align: center;
                margin-bottom: 20px;
                color: #333;
            }
            .form-group {
                margin-bottom: 15px;
            }
            .form-group label {
                display: block;
                margin-bottom: 5px;
                font-weight: bold;
                color: #555;
            }
            .form-group input {
                width: 100%;
                padding: 10px;
                border: 1px solid #ddd;
                border-radius: 5px;
                font-size: 16px;
            }
            .btn {
                width: 100%;
                padding: 12px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                border-radius: 5px;
                font-size: 16px;
                cursor: pointer;
                margin-top: 10px;
            }
            .btn:hover {
                opacity: 0.9;
            }
            .error {
                color: #d32f2f;
                background: #ffebee;
                padding: 10px;
                border-radius: 5px;
                margin-bottom: 15px;
                text-align: center;
            }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h2>🔒 管理员登录</h2>
            <?php if ($login_error): ?>
                <div class="error"><?php echo htmlspecialchars($login_error); ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="login" value="1">
                <div class="form-group">
                    <label for="username">用户名</label>
                    <input type="text" id="username" name="username" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">密码</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn">登录</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ========== 以下为原控制面板代码（已登录） ==========

$playlists_dir = 'playlists/';
if (!is_dir($playlists_dir)) {
    mkdir($playlists_dir, 0755, true);
}

// 获取所有歌单文件
$playlist_files = glob($playlists_dir . '*.json');
$playlists = [];
foreach ($playlist_files as $file) {
    $name = basename($file, '.json');
    $playlists[] = $name;
}

// 默认歌单
$selected_playlist = isset($_GET['playlist']) ? $_GET['playlist'] : 'default';
$selected_playlist = preg_replace('/[^a-zA-Z0-9_-]/', '', $selected_playlist);

// 如果没有歌单，创建默认歌单
if (empty($playlists)) {
    $playlists = ['default'];
    $selected_playlist = 'default';
    $default_config = [
        [
            "name" => "Небо",
            "artist" => "SadSvit",
            "url" => "./assets/Небо - SadSvit.mp3",
            "pic" => "./assets/default.jpg",
            "lrc" => "./assets/Небо - SadSvit.lrc"
        ]
    ];
    file_put_contents($playlists_dir . 'default.json', json_encode($default_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// 处理POST请求（保存歌单）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['playlist_name']) && isset($_POST['playlist_data'])) {
        $playlist_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['playlist_name']);
        $playlist_data = $_POST['playlist_data'];
        // 验证JSON格式
        $decoded = json_decode($playlist_data, true);
        if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
            file_put_contents($playlists_dir . $playlist_name . '.json', $playlist_data);
            $message = "歌单 '{$playlist_name}' 保存成功！";
            $selected_playlist = $playlist_name;
        } else {
            $error = "JSON格式错误，请检查格式！";
        }
    }
    // 处理删除歌单
    if (isset($_POST['delete_playlist'])) {
        $delete_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['delete_playlist']);
        if ($delete_name !== 'default' || count($playlists) > 1) { // 至少保留一个歌单
            unlink($playlists_dir . $delete_name . '.json');
            $message = "歌单 '{$delete_name}' 已删除！";
            $selected_playlist = $playlists[0]; // 切换到第一个歌单
        } else {
            $error = "不能删除默认歌单，至少需要保留一个歌单！";
        }
    }
    // 处理新建歌单
    if (isset($_POST['new_playlist_name'])) {
        $new_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['new_playlist_name']);
        if (!empty($new_name) && !in_array($new_name, $playlists)) {
            file_put_contents($playlists_dir . $new_name . '.json', json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $message = "歌单 '{$new_name}' 创建成功！";
            $selected_playlist = $new_name;
        } else {
            $error = "歌单名称无效或已存在！";
        }
    }
}

// 读取当前选中歌单的数据
$config_file = $playlists_dir . $selected_playlist . '.json';
$playlist_data = file_exists($config_file) ? 
    file_get_contents($config_file) : 
    json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// 构建 API 链接（使用 api.php?url=... 格式）
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$api_path = $script_dir . '/api.php';
$json_url = './playlists/' . urlencode($selected_playlist) . '.json';
$api_full_url = $base_url . $api_path . '?url=' . urlencode($json_url);
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>多歌单管理器</title>
    <style>
        /* 原有 CSS 不变 */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            text-align: center;
            position: relative;
        }
        .logout-btn {
            position: absolute;
            top: 15px;
            right: 20px;
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 14px;
        }
        .header h1 {
            margin-bottom: 10px;
            font-size: 2em;
        }
        .content {
            padding: 30px;
        }
        .sidebar {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .playlist-selector {
            margin-bottom: 20px;
        }
        .playlist-selector label {
            display: block;
            margin-bottom: 10px;
            font-weight: bold;
            color: #333;
        }
        select {
            width: 100%;
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        .new-playlist {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .new-playlist input {
            flex: 1;
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 5px;
        }
        .editor-container {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        .editor-section {
            flex: 1;
        }
        .section-title {
            font-size: 1.2em;
            margin-bottom: 10px;
            color: #333;
            border-bottom: 2px solid #667eea;
            padding-bottom: 5px;
        }
        textarea {
            width: 100%;
            height: 500px;
            padding: 15px;
            border: 2px solid #e1e5e9;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            resize: vertical;
            background: #f8f9fa;
        }
        textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .controls {
            text-align: center;
            margin-top: 20px;
        }
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 25px;
            cursor: pointer;
            font-size: 14px;
            margin: 0 5px;
            transition: all 0.3s ease;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-danger {
            background: #dc3545;
        }
        .message {
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            text-align: center;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .preview {
            margin-top: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        .song-item {
            background: white;
            margin: 10px 0;
            padding: 15px;
            border-radius: 5px;
            border-left: 4px solid #667eea;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .song-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .song-title {
            font-weight: bold;
            color: #333;
        }
        .song-artist {
            color: #666;
        }
        .api-info {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
            font-size: 14px;
        }
        .api-info h4 {
            margin-bottom: 10px;
            color: #333;
        }
        .api-url {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 3px;
            font-family: monospace;
            word-break: break-all;
        }
        @media (max-width: 768px) {
            .editor-container {
                flex-direction: column;
            }
            .new-playlist {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎵 多歌单管理器</h1>
            <p>管理您的多个音乐播放列表</p>
            <button class="logout-btn" onclick="location.href='?logout'">退出登录</button>
        </div>
        <div class="content">
            <?php if (isset($message)): ?>
                <div class="message success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
                <div class="message error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <div class="sidebar">
                <div class="playlist-selector">
                    <label for="playlistSelect">选择歌单：</label>
                    <select id="playlistSelect" onchange="switchPlaylist()">
                        <?php foreach ($playlists as $playlist): ?>
                            <option value="<?php echo htmlspecialchars($playlist); ?>" 
                                    <?php echo $playlist === $selected_playlist ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($playlist); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="new-playlist">
                    <input type="text" id="newPlaylistName" placeholder="输入新歌单名称">
                    <button class="btn btn-secondary" onclick="createNewPlaylist()">➕ 新建歌单</button>
                </div>
                <button class="btn btn-danger" onclick="deleteCurrentPlaylist()">🗑️ 删除当前歌单</button>
            </div>
            <form method="post" id="playlistForm">
                <input type="hidden" name="playlist_name" id="playlistNameInput" value="<?php echo htmlspecialchars($selected_playlist); ?>">
                <div class="editor-container">
                    <div class="editor-section">
                        <h3 class="section-title">编辑歌单</h3>
                        <textarea id="playlistEditor" name="playlist_data" 
                                  placeholder="在此编辑歌单JSON数据..."><?php echo htmlspecialchars($playlist_data); ?></textarea>
                    </div>
                    <div class="editor-section">
                        <h3 class="section-title">歌单预览</h3>
                        <div id="preview" class="preview">
                            <p>保存后将在此显示歌单预览...</p>
                        </div>
                    </div>
                </div>
                <div class="controls">
                    <button type="submit" class="btn">💾 保存歌单</button>
                    <button type="button" class="btn btn-secondary" onclick="loadSample()">📋 加载示例</button>
                    <button type="button" class="btn btn-secondary" onclick="formatJSON()">✨ 格式化JSON</button>
                    <button type="button" class="btn btn-secondary" onclick="copyAPIUrl()">📋 复制API链接</button>
                </div>
            </form>
            <div class="api-info">
                <h4>API 使用方法：</h4>
                <p>使用以下链接通过你的 <code>api.php</code> 获取歌单数据：</p>
                <div class="api-url" id="apiUrl">
                    <?php echo htmlspecialchars($api_full_url); ?>
                </div>
                <!--<p style="margin-top: 10px; font-size: 12px; color: #666;">
                    示例：fetch('<?php echo htmlspecialchars($api_full_url); ?>')
                </p>-->
            </div>
        </div>
    </div>
    <script>
        // 实时预览功能
        const editor = document.getElementById('playlistEditor');
        const preview = document.getElementById('preview');
        function updatePreview() {
            try {
                const data = JSON.parse(editor.value);
                let html = '';
                if (Array.isArray(data) && data.length > 0) {
                    data.forEach((song, index) => {
                        html += `
                            <div class="song-item">
                                <div class="song-info">
                                    <div>
                                        <span class="song-title">${song.name || '未知歌曲'}</span>
                                        <span class="song-artist"> - ${song.artist || '未知艺术家'}</span>
                                    </div>
                                    <div style="font-size: 0.9em; color: #999;">
                                        ${index + 1}/${data.length}
                                    </div>
                                </div>
                                ${song.url ? `<div style="margin-top: 5px; font-size: 0.9em; color: #666;">URL: ${song.url}</div>` : ''}
                                ${song.pic ? `<div style="margin-top: 5px; font-size: 0.9em; color: #666;">封面: ${song.pic}</div>` : ''}
                                ${song.lrc ? `<div style="margin-top: 5px; font-size: 0.9em; color: #666;">歌词: ${song.lrc}</div>` : ''}
                            </div>
                        `;
                    });
                } else {
                    html = '<p style="text-align: center; color: #666;">暂无歌曲数据</p>';
                }
                preview.innerHTML = html;
            } catch (e) {
                preview.innerHTML = '<p style="text-align: center; color: #d63384;">JSON格式错误，请检查语法</p>';
            }
        }
        editor.addEventListener('input', updatePreview);
        updatePreview();

        function switchPlaylist() {
            const selected = document.getElementById('playlistSelect').value;
            window.location.href = '?playlist=' + encodeURIComponent(selected);
        }

        function createNewPlaylist() {
            const newPlaylistName = document.getElementById('newPlaylistName').value.trim();
            if (!newPlaylistName) {
                alert('请输入歌单名称！');
                return;
            }
            if (!/^[a-zA-Z0-9_-]+$/.test(newPlaylistName)) {
                alert('歌单名称只能包含字母、数字、下划线和连字符！');
                return;
            }
            const exists = Array.from(document.getElementById('playlistSelect').options).some(opt => opt.value === newPlaylistName);
            if (exists) {
                alert('歌单名称已存在！');
                return;
            }
            const form = document.createElement('form');
            form.method = 'post';
            form.style.display = 'none';
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'new_playlist_name';
            input.value = newPlaylistName;
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }

        function deleteCurrentPlaylist() {
            const currentPlaylist = document.getElementById('playlistSelect').value;
            if (currentPlaylist === 'default') {
                alert('不能删除默认歌单！');
                return;
            }
            if (confirm(`确定要删除歌单 "${currentPlaylist}" 吗？此操作不可撤销！`)) {
                const form = document.createElement('form');
                form.method = 'post';
                form.style.display = 'none';
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'delete_playlist';
                input.value = currentPlaylist;
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function loadSample() {
            const sampleData = [
                {
                    "name": "夜曲",
                    "artist": "周杰伦",
                    "url": "./assets/夜曲 - 周杰伦.mp3",
                    "pic": "./assets/default.jpg",
                    "lrc": "./assets/夜曲 - 周杰伦.lrc"
                }
            ];
            editor.value = JSON.stringify(sampleData, null, 2);
            updatePreview();
        }

        function formatJSON() {
            try {
                const data = JSON.parse(editor.value);
                editor.value = JSON.stringify(data, null, 2);
                updatePreview();
            } catch (e) {
                alert('JSON格式错误：' + e.message);
            }
        }

        function copyAPIUrl() {
            const apiUrl = document.getElementById('apiUrl').textContent;
            navigator.clipboard.writeText(apiUrl).then(() => {
                alert('API链接已复制到剪贴板！');
            }).catch(err => {
                console.error('复制失败:', err);
                const textArea = document.createElement('textarea');
                textArea.value = apiUrl;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                alert('API链接已复制到剪贴板！');
            });
        }

        document.getElementById('playlistForm').addEventListener('submit', function(e) {
            try {
                JSON.parse(editor.value);
            } catch (e) {
                e.preventDefault();
                alert('JSON格式错误：' + e.message);
            }
        });

        // 更新API链接（用于前端切换歌单时同步）
        function updateApiUrl() {
            const selectedPlaylist = document.getElementById('playlistSelect').value;
            const baseUrl = window.location.origin;
            const scriptDir = new URL(window.location.pathname, baseUrl).pathname.replace(/\/[^\/]*$/, '');
            const apiUrl = baseUrl + scriptDir + '/api.php?url=./playlists/' + encodeURIComponent(selectedPlaylist) + '.json';
            document.getElementById('apiUrl').textContent = apiUrl;
        }
        document.getElementById('playlistSelect').addEventListener('change', updateApiUrl);
    </script>
</body>
</html>