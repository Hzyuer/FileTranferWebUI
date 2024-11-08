<?php
session_start();

$host = '127.0.0.1'; // 替换为 Python 服务器地址
$port = 12345;       // 替换为 Python 服务器端口

// 创建 SSL 上下文选项以禁用证书验证（仅用于开发环境）
$contextOptions = [
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true
    ]
];
$context = stream_context_create($contextOptions);

// 创建一个套接字连接，使用自定义的 SSL 上下文
$socket = stream_socket_client("ssl://$host:$port", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
if (!$socket) {
    die("无法连接到服务器: $errstr ($errno)");
}

// 文件上传处理函数
function uploadFile($socket, $filePath) {
    if (!file_exists($filePath)) {
        echo "文件不存在。";
        return;
    }

    $header = [
        'command' => 'UPLOAD',
        'fileName' => basename($filePath),
        'fileSize' => filesize($filePath),
        'time' => date('Y-m-d H:i:s'),
    ];

    $headerBytes = json_encode($header);
    $paddedHeader = str_pad($headerBytes, 128, "\0");
    fwrite($socket, $paddedHeader);

    $file = fopen($filePath, 'rb');
    while (!feof($file)) {
        $data = fread($file, 1024);
        fwrite($socket, $data);
    }
    fclose($file);
    echo "文件上传成功。\n";
}

// 文件下载处理函数
function downloadFile($socket, $fileName) {
    $header = [
        'command' => 'DOWNLOAD',
        'fileName' => $fileName,
        'time' => date('Y-m-d H:i:s'),
    ];

    $headerBytes = json_encode($header);
    $paddedHeader = str_pad($headerBytes, 128, "\0");
    fwrite($socket, $paddedHeader);

    $fileInfoSize = fread($socket, 128);
    $headerJson = trim($fileInfoSize);
    $header = json_decode($headerJson, true);

    if ($header && isset($header['status']) && $header['status'] === 'OK') {
        $fileSize = $header['fileSize'];
        $filePath = 'downloads/' . $fileName;
        $file = fopen($filePath, 'wb');
        $receivedSize = 0;

        while ($receivedSize < $fileSize) {
            $data = fread($socket, 1024);
            fwrite($file, $data);
            $receivedSize += strlen($data);
        }

        fclose($file);
        echo "文件下载成功：$filePath\n";
    } else {
        echo "文件下载失败。\n";
    }
}

// 处理上传或下载请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'upload' && isset($_FILES['file'])) {
        $filePath = $_FILES['file']['tmp_name'];
        uploadFile($socket, $filePath);
    } elseif (isset($_POST['action']) && $_POST['action'] === 'download' && isset($_POST['fileName'])) {
        $fileName = $_POST['fileName'];
        downloadFile($socket, $fileName);
    }
}

// 关闭套接字连接
fclose($socket);
?>