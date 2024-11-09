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
//echo "私钥:\n" . $client_private_key . "\n\n";

// 提取公钥
$public_key_details = openssl_pkey_get_details($res);
if ($public_key_details === false) {
    $error = openssl_error_string();
    die("公钥提取失败: $error\n");
}

$client_public_key = $public_key_details["key"];
//echo "公钥:\n" . $client_public_key . "\n";


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

echo base64_decode($server_public_key);

if ($server_key_hash === hash('sha256', base64_decode($server_public_key))) {
    echo "成功接收服务端公钥: \n" . base64_decode($server_public_key) ;
} else {
    die("未能正确接收服务端公钥。");
}

// 处理用户请求的函数
function handleUserRequest($socket) {
    $action = $_POST['action'];

    echo $action."\n";

    if ($action === 'register') {
        $username = $_POST['username'];
        $password = $_POST['password'];
        registerUser($socket, $username, $password);
    } elseif ($action === 'login') {
        $username = $_POST['username'];
        $password = $_POST['password'];
        loginUser($socket, $username, $password);
    } elseif (isset($_POST['action']) && $_POST['action'] === 'upload' && isset($_FILES['file'])){
        $filePath = $_FILES['file']['tmp_name'];
        $fileName = $_FILES['file']['name'];
        uploadFile($socket, $filePath,$fileName);
    } elseif (isset($_POST['action']) && $_POST['action'] === 'download' && isset($_POST['fileName'])) {


        $fileName = $_POST['fileName'];

        echo "\n".$fileName."\n";

        download_file($socket, $fileName);
    }
    else {
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
            header('Location: file_upload_download.html');
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

function uploadFile($socket,$filePath,$fileName) {
    try {
        // 检查文件是否存在
        if (!file_exists($filePath)) {
            echo "文件 {$filePath} 不存在\n";
            return;
        }

        // 准备头信息
        $header = [
            'command' => 'UPLOAD',
            'fileName' => basename($fileName),
            'fileSize' => filesize($filePath),
            'time' => date('Y-m-d H:i:s')
        ];

        // 将头信息转换为 JSON 并填充为固定长度（128 字节）
        $headerJson = json_encode($header);
        if ($headerJson === false) {
            throw new Exception("无法将头信息数组转换为 JSON");
        }
        $headerPacked = str_pad($headerJson, 128, "\0");

        // 发送头信息
        fwrite($socket, $headerPacked);

        // 打开文件以二进制方式读取
        $file = fopen($filePath, 'rb');
        if ($file === false) {
            echo "无法打开文件: $filePath\n";
            return;
        }

        // 逐块读取文件并发送
        while (!feof($file)) {
            // 每次读取 1024 字节
            $data = fread($file, 1024);
            if ($data === false) {
                throw new Exception("读取文件时发生错误");
            }

            if (strlen($data) === 0) {
                echo basename($filePath) . " 文件发送完毕...\n";
                break;
            }

            echo "发送的内容: " . bin2hex($data) . "\n";  // 用 bin2hex 打印二进制数据以便调试

            echo "原始数据的长度为： ".strlen($data);

            // 对文件数据进行加密
            $tosend = encrypt_file($data);  // 假设 encrypt_file() 是一个已实现的加密函数
            echo "加密后的消息: " . $tosend . "\n";  // 打印加密后的消息
            $tosend = base64_encode($tosend);

            //$tosend = $data;
//            echo "加密后的消息: " . $tosend . "\n";  // 打印加密后的消息

            // 发送加密消息的长度（4 字节大端格式）
            $tosendLength = strlen($tosend);
            $lengthPacked = (string)$tosendLength;// base64encode
            fwrite($socket, base64_encode($lengthPacked));

            // 发送加密后的数据
            fwrite($socket, $tosend);
        }

        fclose($file);

        // 提示信息
        echo "上传成功\n";

    } catch (Exception $e) {
        echo "上传文件时发生错误: " . $e->getMessage() . "\n";
    }
}

function download_file($socket, $fileName) {

    echo "\n".$fileName."\n";

    try {
        // 准备下载请求头信息
        $header = [
            'command' => 'DOWNLOAD',
            'fileName' => $fileName,
            'fileSize' => '',
            'time' => date('Y-m-d H:i:s')
        ];

        // 将头信息转换为 JSON 并填充为固定长度（128 字节）
        $headerJson = json_encode($header);
        if ($headerJson === false) {
            throw new Exception("无法将头信息数组转换为 JSON");
        }
        $headerPacked = str_pad($headerJson, 128, "\0");

        echo "\n".$headerPacked."\n";

        // 发送下载请求头信息
        fwrite($socket, $headerPacked);

        // 接收服务器的响应（文件信息）
        $fileinfoSize = 128; // 假设文件信息中包含文件名和文件大小信息
        $buf = fread($socket, $fileinfoSize);
        if ($buf === false || strlen($buf) !== $fileinfoSize) {
            throw new Exception("接收文件信息时发生错误");
        }

        // 解包文件信息
        $headerJson = trim(substr($buf, 0, 128), "\0");
        $header = json_decode($headerJson, true);


//        echo $header."\n";

        if ($header === null) {
            throw new Exception("无法解析文件头信息");
        }

        // 检查下载状态
        if ($header['status'] !== 'OK') {
            throw new Exception("下载失败: " . $header['message']);
        }

        $fileSize = $header['fileSize'];
        $downloadDir = 'download_files';
        if (!file_exists($downloadDir)) {
            mkdir($downloadDir, 0777, true);
        }
        $filePath = $downloadDir . '/' . $fileName;

        // 准备接收文件数据
        $file = fopen($filePath, 'wb');
        if ($file === false) {
            throw new Exception("无法创建文件: $filePath");
        }

        echo "开始接收文件: $fileName, 文件大小: $fileSize 字节\n";
        $receivedSize = 0;

        while ($receivedSize < $fileSize) {
            // 先接收加密数据的长度（4 字节大端格式）
            $lengthBuf = fread($socket, 1024);

            echo "\n".$lengthBuf."\n";

            $recvLen = (Int)$lengthBuf; //unpack('N', $lengthBuf)[1];

            // 接收加密数据
            $encryptedData = fread($socket, $recvLen);


            if ($encryptedData === false || strlen($encryptedData) !== $recvLen) {
                throw new Exception("接收加密数据时发生错误");
            }

            // 解密数据
//            $decryptedData = decrypt_file($encryptedData);
            $decryptedData = $encryptedData;

            echo $decryptedData."\n";

            if ($decryptedData === null) {
                throw new Exception("解密数据时发生错误");
            }

            // 写入文件
            fwrite($file, $decryptedData);
            $receivedSize += strlen($decryptedData);

            echo $receivedSize."\n";
        }

        fclose($file);
        echo "文件下载成功: $fileName\n";

    } catch (Exception $e) {
        echo "下载文件时发生错误: " . $e->getMessage() . "\n";
    }
}
//加密函数
function encrypt_file($data) {

    $blockSize = 16; // AES 块大小为 16 字节
    $paddingLength = $blockSize - (strlen($data) % $blockSize);
    $padding = str_repeat(chr($paddingLength), $paddingLength);
    $paddedData = $data . $padding;

    global $server_public_key;
    // 生成 AES 密钥和初始向量
    $aesKey = generate_aes_key();  // 生成 256 位 AES 密钥
    $aesIv = generate_aes_iv();    // 生成 128 位初始向量

    echo "AES秘钥： ".base64_encode($aesKey)."\n";
    echo "AES向量： ".base64_encode($aesIv)."\n";

    // 使用 AES-256-CBC 加密数据
    $cipher = "aes-256-cbc";
//    $encryptedData = openssl_encrypt($paddedData, $cipher, $aesKey, OPENSSL_RAW_DATA, $aesIv);

    // 使用服务器公钥对 AES 密钥和 IV 进行加密
    $keyIv = json_encode(['key' => base64_encode($aesKey), 'iv' => base64_encode($aesIv)]);
    $encryptedKeyIv = null;
    $publicKeyResource = openssl_get_publickey(base64_decode($server_public_key));


    if ($publicKeyResource === false) {
        throw new Exception("加载公钥失败");
    }

    $encryptionResult = openssl_public_encrypt($keyIv, $encryptedKeyIv, $publicKeyResource);

    if (!$encryptionResult) {
        throw new Exception("RSA 加密失败");
    }

    $encryptionResult = openssl_public_encrypt($data, $encryptedData, $publicKeyResource);

    // 返回加密后的数据和加密的 AES 密钥与 IV
    $res = [
        'encrypted_data' => base64_encode($encryptedData),  // 将加密数据转换为 base64 便于传输
        'encrypted_key_iv' => base64_encode($encryptedKeyIv) // 将加密后的 AES 密钥和 IV 转换为 base64
    ];

    // 将加密后的数据与密钥和 IV 打包在一起
    $sendMessage = json_encode($res);

    return $sendMessage;
}

function generate_aes_key() {
    return openssl_random_pseudo_bytes(16); // 生成 128 位 AES 密钥
}

function generate_aes_iv() {
    return openssl_random_pseudo_bytes(16); // 生成 128 位初始向量
}



// 调用处理用户请求的函数
handleUserRequest($socket);

//download_file($socket,"hyper-v.txt");

// 关闭套接字连接
fclose($socket);

?>