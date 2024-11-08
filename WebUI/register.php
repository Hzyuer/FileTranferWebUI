<?php
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
$exported = openssl_pkey_export($res, $private_key);
if ($exported === false) {
    $error = openssl_error_string();
    die("私钥导出失败: $error\n");
}
echo "私钥:\n" . $private_key . "\n\n";

// 提取公钥
$public_key_details = openssl_pkey_get_details($res);
if ($public_key_details === false) {
    $error = openssl_error_string();
    die("公钥提取失败: $error\n");
}

$public_key = $public_key_details["key"];
echo "公钥:\n" . $public_key . "\n";


// Step 0: 读取本地公钥并发送给服务器
function exchange_pubkey($socket) {
    //$public_key = file_get_contents($key_dir);
    global $public_key;
    if ($public_key === false) {
        echo "无法读取公钥文件\n";
        return false;
    }
    echo "发送客户端公钥\n" . $public_key;

    // 计算公钥的哈希值
    $key_hash = hash('sha256', $public_key);
    $data_to_send = json_encode([
        'public_key' => base64_encode($public_key), // 转换为 Base64 编码，以便传输二进制数据
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
$jsonstr = fread($socket, $public_key_size);

$json_data=json_decode( $jsonstr , true);

$server_public_key = $json_data['public_key'];
$server_key_hash = $json_data['key_hash'];



if ($server_key_hash === hash('sha256', base64_decode($server_public_key))) {
    echo "成功接收服务端公钥: \n" . base64_decode($server_public_key) ;
} else {
    die("未能正确接收服务端公钥。");
}


/*
// Step 2: 构建登录请求
$header = [
    'command' => 'LOGIN',
    'username' => $username,
    'password' => $password,
    'time' => date("Y-m-d H:i:s"), // PHP 版本中的当前时间
];
$header_json = json_encode($header);
$header_bytes = pack('128s', $header_json);

// 发送登录请求
fwrite($socket, $header_bytes);

echo "登录中...\n";

// 从服务器接收返回的响应
$fileinfo_size = 128; // 数据头大小与 Python 中一致
$buf = fread($socket, $fileinfo_size);

if ($buf) {
    // 解包并去除填充字符
    $unpacked = unpack('128s', $buf);
    $header_json = trim($unpacked[1]);
    $header = json_decode($header_json, true);
    $status = $header['status'];

    if ($status == 'OK') {
        echo "登录成功\n";
        $login_success = true;
    } else {
        echo "登录失败\n";
        $login_success = false;
    }
} else {
    $login_success = false;
}

// 关闭套接字连接
//fclose($socket);

// 登录成功后的操作
if ($login_success) {
    // 在这里实现登录后的逻辑，比如重定向到用户主页
    echo "欢迎回来, $username!";
} else {
    // 实现登录失败的逻辑，比如重定向回登录页面，显示错误信息
    echo "登录失败，请检查用户名和密码。";
}
*/

// 关闭套接字连接
fclose($socket);

?>