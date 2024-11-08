<?php
$host = '127.0.0.1';
$port = 12345;
$username = $_POST['username'];
$password = $_POST['password'];

// 创建socket
$socket = stream_socket_client("ssl://$host:$port", $errno, $errstr, 30);
if (!$socket) {
    die("Could not connect to server: $errstr ($errno)");
}

// 构建注册请求
$header = [
    'command' => 'REGISTER',
    'username' => $username,
    'password' => $password,
];
$header_json = json_encode($header);

// 发送数据到服务器
fwrite($socket, pack('128s', $header_json));

// 接收响应
$response = fread($socket, 8192);
$response_data = json_decode(trim(unpack('128s', $response)[1]), true);

if ($response_data['status'] == 'OK') {
    echo "Registration successful: " . $response_data['message'];
} else {
    echo "Registration failed: " . $response_data['message'];
}

fclose($socket);
?>
