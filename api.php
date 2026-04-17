<?php
// 先不在这里设置默认的 Content-Type
// header('Content-Type: application/json; charset=utf-8');
// 处理预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 检查是否提供了 url 参数
if (!isset($_GET['url'])) {
    // 没有提供 url 参数，列出当前目录及子目录下的所有 JSON 文件
    // 在这里设置 HTML 的内容类型
    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>歌单列表</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background-color: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; text-align: center; }
        .file-list { list-style-type: none; padding: 0; }
        .file-list li { padding: 10px; border-bottom: 1px solid #eee; }
        .file-link { color: #007bff; text-decoration: none; font-weight: bold; }
        .file-link:hover { text-decoration: underline; }
        .api-info { margin-top: 20px; padding: 15px; background-color: #e7f3ff; border-radius: 5px; }
        .copy-btn {
            background-color: #e7e7e7;
            border: none;
            padding: 2px 6px;
            border-radius: 4px;
            text-align: center;
            text-decoration: none;
            display: inline-block;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>可用歌单配置文件</h1>
        <p>点击下方链接可直接访问对应歌单的 API 数据。</p>
        <ul class="file-list">
        <?php
        $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];

        // 递归查找所有 json 文件
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'json') {
                // 计算相对于 api.php 所在目录的相对路径
                $relative_path = substr($file->getPathname(), strlen(__DIR__) + 1);
                // 转换斜杠与反斜杠，确保 Windows 平台兼容
                $relative_path = str_replace('\\', '/', $relative_path);
                $api_url = $base_url . '?url=./' . urlencode($relative_path);
                echo '<li><a href="' . htmlspecialchars($api_url) . '" class="file-link" target="_blank">./' . htmlspecialchars($relative_path) . '</a>&nbsp;<button class="copy-btn">复制</button></li>';
            }
        }
        ?>
        </ul>
        <div class="api-info">
            <h4>API 使用方法：</h4>
            <p>使用格式：<code><?php echo htmlspecialchars($base_url); ?>?url=相对于此文件的路径</code></p>
            <p>例如：<code><?php echo htmlspecialchars($base_url); ?>?url=./playlist_config.json</code></p>
            <p>或者：<code><?php echo htmlspecialchars($base_url); ?>?url=./list/playlist.json</code></p>
        </div>
    </div>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            // 获取所有复制按钮
            const copyButtons = document.querySelectorAll('.copy-btn');
            
            // 为每个按钮添加点击事件
            copyButtons.forEach(button => {
                button.addEventListener('click', function() {
                    // 获取对应的链接元素
                    const link = this.parentElement.querySelector('.file-link');
                    const url = link.href;
                    
                    // 使用Clipboard API复制URL
                    navigator.clipboard.writeText(url)
                        .then(() => {
                            // 复制成功
                            const originalText = this.innerHTML;
                            this.innerHTML = '<i class="fas fa-check"></i> 复制成功';
                            this.classList.add('copied');
                            
                            // 1秒后恢复按钮状态
                            setTimeout(() => {
                                this.innerHTML = originalText;
                                this.classList.remove('copied');
                            }, 1000);
                        })
                        .catch(err => {
                            // 复制失败处理
                            console.error('复制失败:', err);
                            const originalText = this.innerHTML;
                            this.innerHTML = '<i class="fas fa-times"></i> 复制失败';
                            this.classList.add('copied');
                            
                            setTimeout(() => {
                                this.innerHTML = originalText;
                                this.classList.remove('copied');
                            }, 1000);
                        });
                });
            });
        });
    </script>
</body>
</html>
<?php
    exit;
}

// 获取 url 参数。从这里开始是处理 JSON 响应的部分
// 现在才设置 JSON 的内容类型
header('Content-Type: application/json; charset=utf-8');

$requested_file = $_GET['url'];
// 安全过滤，防止路径遍历 (../ 等)
// 使用 # 作为分隔符以避免与路径中的 / 冲突
$requested_file = preg_replace('#\.\.\/|\.\.\\\|^\./#', '', $requested_file);

if (empty($requested_file) || pathinfo($requested_file, PATHINFO_EXTENSION) !== 'json') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Invalid or missing JSON file path'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 构建完整文件路径
$config_file = __DIR__ . '/' . $requested_file;

// 检查文件是否存在且为 .json 文件
if (!file_exists($config_file) || !is_file($config_file) || pathinfo($config_file, PATHINFO_EXTENSION) !== 'json') {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'JSON file not found: ' . $requested_file], JSON_UNESCAPED_UNICODE);
    exit;
}

// 读取歌单配置
$playlist = json_decode(file_get_contents($config_file), true);

// 检查解码是否成功
if ($playlist === null && json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Invalid JSON in file: ' . $requested_file . '. Error: ' . json_last_error_msg()], JSON_UNESCAPED_UNICODE);
    exit;
}

// 如果解码结果不是数组，返回空数组
if (!is_array($playlist)) {
    $playlist = [];
}

// 获取当前URL基础路径
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$base_url = $protocol . '://' . $host . dirname($_SERVER['SCRIPT_NAME']);

// 处理相对地址转绝对地址
foreach ($playlist as &$item) {
    if (isset($item['url']) && !preg_match('/^https?:\/\//', $item['url'])) {
        $item['url'] = $base_url . '/' . ltrim($item['url'], './');
    }
    if (isset($item['pic']) && !preg_match('/^https?:\/\//', $item['pic'])) {
        $item['pic'] = $base_url . '/' . ltrim($item['pic'], './');
    }
    if (isset($item['lrc']) && !preg_match('/^https?:\/\//', $item['lrc'])) {
        $item['lrc'] = $base_url . '/' . ltrim($item['lrc'], './');
    }
}

// 输出处理后的歌单
echo json_encode($playlist, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>