<?php
session_start();

$host = '127.0.0.1'; // 替换为 Python 服务器地址
$port = 12345;       // 替换为 Python 服务器端口

$username = $_SESSION['username'];
$password = $_SESSION['password'] ;
$client_public_key = $_SESSION['client_public_key'];
$client_private_key = $_SESSION['client_private_key'];
$server_public_key = $_SESSION['server_public_key'];


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

    $headerJson = json_encode($header);
    $headerPacked = str_pad($headerJson, 132, "\0");

    echo "\n".$headerPacked;

    fwrite($socket, $headerPacked);

    $file = fopen($filePath, 'rb');
    while (!feof($file)) {
        $data = fread($file, 1024);
        $tosendLength = strlen($data);  // 获取 $tosend 的长度（字节数）
    // 将长度转换为字符串，通常发送时会固定长度，比如 4 个字节用于表示长度
        $lengthPacked = pack('N', $tosendLength);  // 这里使用大端格式 4 字节无符号整数

        echo $data." which length is : ".$lengthPacked."\n";

        fwrite($socket, $lengthPacked);
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