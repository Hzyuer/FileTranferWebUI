<?php
session_start();

$host = '127.0.0.1'; // 替换为 Python 服务器地址
$port = 12345;       // 替换为 Python 服务器端口
$username = 'cattac';//$_POST['username']; // 从POST请求获取用户名
$password = '123456';//$_POST['password']; // 从POST请求获取密码

// 创建 SSL 上下文选项以禁用证书验证（仅用于开发环境）
$contextOptions = [
    'ssl' => [
        'verify_peer' => false, // 禁用验证对等方证书
        'verify_peer_name' => false, // 禁用验证对等方名称
        'allow_self_signed' => true // 允许自签名证书
    ]
];
$context = stream_context_create($contextOptions);

// 创建一个套接字连接，使用自定义的 SSL 上下文
$socket = stream_socket_client("ssl://$host:$port", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
if (!$socket) {
    die("无法连接到服务器: $errstr ($errno)");
}


// 配置密钥生成参数
$config = [
    "private_key_bits" => 2048,
    "private_key_type" => OPENSSL_KEYTYPE_RSA,
];

// 尝试生成密钥对，并检查是否成功
$res = openssl_pkey_new($config);

if ($res === false) {
    // 输出错误信息
    $error = openssl_error_string();
    die("密钥生成失败: $error\n");
}

// 提取私钥
$exported = openssl_pkey_export($res, $client_private_key);
if ($exported === false) {
    $error = openssl_error_string();
    die("私钥导出失败: $error\n");
}
echo "私钥:\n" . $client_private_key . "\n\n";

// 提取公钥
$public_key_details = openssl_pkey_get_details($res);
if ($public_key_details === false) {
    $error = openssl_error_string();
    die("公钥提取失败: $error\n");
}

$client_public_key = $public_key_details["key"];
echo "公钥:\n" . $client_public_key . "\n";


// Step 0: 读取本地公钥并发送给服务器
function exchange_pubkey($socket) {
    //$public_key = file_get_contents($key_dir);
    global $client_public_key;
    if ($client_public_key === false) {
        echo "无法读取公钥文件\n";
        return false;
    }
    echo "发送客户端公钥\n" . $client_public_key;

    // 计算公钥的哈希值
    $key_hash = hash('sha256', $client_public_key);
    $data_to_send = json_encode([
        'public_key' => base64_encode($client_public_key), // 转换为 Base64 编码，以便传输二进制数据
        'key_hash' => base64_encode($key_hash)      // 将哈希值也编码为 Base64
    ]);


    for ($i = 0; $i < 3; $i++) {
        try {
            fwrite($socket, $data_to_send);
            echo "客户端公钥发送成功\n";
            return true;
        } catch (Exception $e) {
            echo "公钥发送失败，尝试重新发送\n";
        }
    }

    echo "公钥发送失败\n";
    return false;
}

if (!exchange_pubkey($socket)) {
    fclose($socket);
    die("无法发送客户端公钥");
}

// Step 1: 接收服务端的公钥
$public_key_size = 2048; // 假设公钥的最大长度为 1024 字节
$json_str = fread($socket, $public_key_size);

$json_data=json_decode( $json_str , true);

$server_public_key = $json_data['public_key'];
$server_key_hash = $json_data['key_hash'];

if ($server_key_hash === hash('sha256', base64_decode($server_public_key))) {
    echo "成功接收服务端公钥: \n" . base64_decode($server_public_key) ;
} else {
    die("未能正确接收服务端公钥。");
}

// 处理用户请求的函数
function handleUserRequest($socket) {
    $action = $_POST['action'];
    $username = $_POST['username'];
    $password = $_POST['password'];

    if ($action === 'register') {
        registerUser($socket, $username, $password);
    } elseif ($action === 'login') {
        loginUser($socket, $username, $password);
    } else {
        echo "无效的请求";
    }
}

// 发送注册请求的函数
function registerUser($socket, $username, $password) {
    $header = [
        'command' => 'REGISTER',
        'username' => $username,
        'password' => $password,
        'time' => date('Y-m-d H:i:s'),
    ];
    $headerBytes = json_encode($header);
    $paddedHeader = str_pad($headerBytes, 128, "\0"); // 按照 Python 端使用 struct.pack('128s')，填充到 128 字节

    fwrite($socket, $paddedHeader);

    echo "注册中...\n";
    $buf = fread($socket, 128);
    if ($buf) {
        $headerJson = trim($buf);
        $header = json_decode($headerJson, true);

        if ($header && isset($header['status']) && $header['status'] === 'OK') {
            echo "注册成功";
        } else {
            echo "注册失败";
        }
    }
}

// 发送登录请求的函数
function loginUser($socket, $username, $password) {
    global $client_public_key;
    global $client_private_key;
    global $server_public_key;
    $header = [
        'command' => 'LOGIN',
        'username' => $username,
        'password' => $password,
        'time' => date('Y-m-d H:i:s'),
    ];
    $headerBytes = json_encode($header);
    $paddedHeader = str_pad($headerBytes, 128, "\0");

    fwrite($socket, $paddedHeader);

    echo "登录中...\n";
    $buf = fread($socket, 128);
    if ($buf) {
        $headerJson = trim($buf);
        $header = json_decode($headerJson, true);

        if ($header && isset($header['status']) && $header['status'] === 'OK') {
            echo "登录成功";
            $_SESSION['username'] = $username;
            $_SESSION['password'] = $password;
            $_SESSION['client_public_key'] = $client_public_key;
            $_SESSION['client_private_key'] = $client_private_key;
            $_SESSION['server_public_key'] = $server_public_key;
            header('Location: upload.html');
            exit();
        } else {
            echo "登录失败";
        }

//        if ($header && isset($header['status']) && $header['status'] === 'OK') {
//            echo "登录成功";
//        } else {
//            echo "登录失败";
//        }
    }
}

// 调用处理用户请求的函数
handleUserRequest($socket);

// 关闭套接字连接
fclose($socket);

// 关闭套接字连接
fclose($socket);

?>
