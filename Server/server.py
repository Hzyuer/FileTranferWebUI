import socket
import ssl
import sys
import threading
import struct
import json
import pickle
import base64
import os
import pymysql
import bcrypt
import logging
import datetime
from common.RSAencryption import RSACryptor
from common.AESencryption import AESCryptor
import hashlib

from Crypto.Cipher import AES
from Crypto.Util.Padding import unpad
from Crypto.PublicKey import RSA
from Crypto.Cipher import PKCS1_OAEP

def sha256_hash(data):
    '''
    Usage: 进行sha256哈希
    
    Args:
        data: 需要哈希的字符串
    Returns:
        进行哈希和消息摘要后的字符串
    '''
    sha256 = hashlib.sha256()
    sha256.update(data)
    res = sha256.hexdigest()
    return res

SERVER_ADDRESS = '127.0.0.1'
SERVER_PORT = 12345
UPLOAD_DIR = 'uploaded_files'

def init_key():
    '''
    Usage: 生成公私钥
    '''
    rsa = RSACryptor()
    rsa.gen_rsa_key_pairs()
    rsa.save_keys("keys/server/server")
    return rsa.public_key, rsa.private_key

def conn_db():
    '''
    Usage: 打开数据库连接
    '''
    #打开数据库连接
    db = pymysql.connect(host='127.0.0.1', port=3306, user='root', passwd='cattac', db='file_transfer', charset='utf8mb4')
    #使用cursor方法创建一个游标
    cursor = db.cursor()
    #查询数据库版本
    cursor.execute("select version()")
    data = cursor.fetchone()
    print(f"数据库版本:{data}")
    return db, cursor


class Server:
    '''
    Description: 服务端类
    '''
    def __init__(self):
        # 设置日志记录配置
        logging.basicConfig(level=logging.INFO,
                            format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
                            handlers=[
                                logging.FileHandler("logs/server.log"),
                                logging.StreamHandler()
                            ])
        self.logger = logging.getLogger(__name__)

        self.logger.info("Initializing server...")
        self.db, self.cursor = conn_db()
        self.server_public_key, self.server_private_key = init_key()
        self.client_public_key = None
        self.rsa_cipher = RSACryptor()
        self.aes_key = AESCryptor.gen_key()
        self.aes_iv = AESCryptor.gen_iv()
        self.aes_cipher = AESCryptor(self.aes_key, iv=self.aes_iv)
        

    def listen(self) -> None:
        '''
        Usage: 开启监听
        '''
        context = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)

        # 关闭SSL校验
        context.verify_mode = ssl.CERT_OPTIONAL

        context.load_cert_chain(certfile="certs/server.crt", keyfile="certs/server.key")

        with socket.socket(socket.AF_INET, socket.SOCK_STREAM, 0) as sock:
            try:
                sock.bind((SERVER_ADDRESS, SERVER_PORT))
                sock.listen(5)
                self.logger.info("Server listening on %s:%d", SERVER_ADDRESS, SERVER_PORT)
                # 打包成ssl socket
                with context.wrap_socket(sock, server_side=True) as ssock:
                    while True:
                        # 接收客户端连接
                        connection, client_address = ssock.accept()
                        self.logger.info('Connected by: %s', client_address)
                        #开启多线程,这里arg后面一定要跟逗号，否则报错
                        thread = threading.Thread(target = self.handle_conn, args=(connection,))
                        thread.start()
            except socket.error as msg:
                self.logger.error("Socket error: %s", msg)
                sys.exit(1)

    def exchange_pubkey(self, key_dir: str, conn):
        ''' 
        Usage: 向客户端发送服务端公钥 
        Args: 
            key_dir: 存放公钥的目录       
        '''
        with open(key_dir, 'rb') as fi:
            public_key = fi.read()
            print("发送服务端公钥\n" + public_key.decode("utf-8"))

        key_hash = sha256_hash(public_key)
        
        # 使用 Base64 编码公钥
        public_key_base64 = base64.b64encode(public_key).decode('utf-8')
        
        # 创建 JSON 数据
        data = {
            "public_key": public_key_base64,
            "key_hash": key_hash
        }
        
        json_message = json.dumps(data)
        
        for _ in range(3):  # 尝试三次
            try:
                conn.send(json_message.encode('utf-8'))  # 发送 JSON 字符串的字节形式
                print("服务端公钥发送成功")
                self.logger.info("Sent server public key to client")
                return
            except ConnectionRefusedError:
                print("公钥发送失败，尝试重新发送")
                self.logger.info("Server public key failed")
    
    def verify_key(self, conn):
        '''
        Usage: 对客户端公钥完整性进行验证

        Args:
            sock: SSLSocket
        '''
        while True:
            message = conn.recv(4096)
            if message == None:
                continue
            data = json.loads(message)
            client_public_key = data['public_key']
            client_key_hash = data['key_hash']
            print(str(base64.b64decode(client_public_key)))
            print(base64.b64decode(client_key_hash).decode('utf-8'))
            print(sha256_hash(base64.b64decode(client_public_key)))
            

            if base64.b64decode(client_key_hash).decode('utf-8') == sha256_hash(base64.b64decode(client_public_key)):
                self.logger.info("Received client public key")
                self.client_public_key = str(base64.b64decode( client_public_key))
                print("收到客户端公钥\n" +  self.client_public_key + "\n")
                break

    def connect(self, conn):
        '''
        Usage: 与客户端进行连接
        '''
        print("开始交换公钥")
        self.exchange_pubkey("keys/server/serverpublic.pem", conn)
        print("开始验证对方公钥完整性")
        self.verify_key(conn)
        print("WTF")

    def handle_conn(self, conn):
        '''
        Usage: 处理连接

        Args:
            conn: SSL Socket连接
        '''
        try:
            # 公钥交换
            #if self.client_public_key == None:
            self.connect(conn)

            while True:

                

                # 申请相同大小的空间存放发送过来的文件名与文件大小信息
                fileinfo_size = struct.calcsize('128s')

                # 接收文件名与文件大小信息
                buf = conn.recv(fileinfo_size)
                # 判断是否接收到文件头信息

                # print(buf)
                if buf:
                    
                    print("time for listening")

                    header_json = str(struct.unpack('128s', buf)[0], encoding='utf-8').strip('\00')
                    print(header_json)
                    header = json.loads(header_json)
                    command = header['command']

                    if command == "UPLOAD":
                        self.handle_upload(conn, header)
                    elif command == "DOWNLOAD":
                        self.handle_download(conn, header)
                    elif command == "REGISTER":
                        self.handle_register(conn, header)
                    elif command == "LOGIN":
                        self.handle_login(conn, header)
                    elif command == "LIST":
                        self.handle_list(conn)
        except Exception as e:
            self.logger.error("Error during connection handling: %s", e)

    def handle_upload(self, conn, header):
        '''
        Usage: 处理文件上传

        Args:
            conn: SSL Socket连接
            header: 文件头信息
        '''
        file_name, file_size = header["fileName"], header["fileSize"]
        print(f'上传文件名: {file_name}, 文件大小: {file_size}')
        # 定义接收了的文件大小
        recvd_size = 0
        file_path = os.path.join(UPLOAD_DIR + "/", str(file_name))
        fp = open(file_path, "wb")
        print("开始接收文件")  
        while not recvd_size == file_size:
            if file_size - recvd_size > 200:
                # 由于经过加密，实际发送的文件长度和原本不一致

                recv_len = int(base64.b64decode(conn.recv(1024)))
                # recv_len = int(conn.recv(1024).decode("utf-8"))
                # print("该段发送长度: ", recv_len)
                rdata = base64.b64decode(conn.recv(recv_len))

                decrypted_data = self.decrypt_file(rdata)
                # decrypted_data = rdata
                # print("解密后: "+str(decrypted_data))

                recvd_size += len(decrypted_data)
            else:
                
                recv_len = int(base64.b64decode(conn.recv(1024)))
                # print("该段发送长度: ", recv_len)
                rdata = base64.b64decode(conn.recv(recv_len))
                
                # print(rdata)
                
                decrypted_data = self.decrypt_file(rdata)
                # decrypted_data = rdata
                # print("解密后: "+str(decrypted_data))

                recvd_size = file_size
            fp.write(decrypted_data)
        fp.close()
        print('文件接收完毕')
        self.logger.info("File %s upload success, file size is %s", file_name, file_size)
        # conn.close()

    def handle_download(self, conn, header):
        '''
        Usage: 处理文件下载

        Args:
            conn: SSL Socket连接
            header: 文件头信息
        '''
        file_name = header['fileName']
        file_path = os.path.join(UPLOAD_DIR+'/', str(file_name))
        try:
            if os.path.isfile(file_path):
                response = {
                    'status': 'OK', 
                    'fileSize': os.stat(file_path).st_size,
                    'message': 'File found'
                }

                res_hex = bytes(json.dumps(response).encode('utf-8'))
                res_pack = struct.pack('128s', res_hex)
                conn.send(res_pack)

                with open(file_path, 'rb') as fp:
                    while True:
                        data = fp.read(1024)
                        if not data:
                            print(f'{os.path.basename(file_path)}文件发送完毕...')
                            break
                        print("发送的内容:", data)
                        # tosend = self.encrypt_file(data)
                        tosend = data

                        print("加密后的消息", tosend)
                        conn.send(str(len(tosend)).encode('utf-8'))
                        conn.send(tosend)
                self.logger.info("File %s transfer success", file_name)
            else:
                response = {
                    'status': 'ERROR', 
                    'message': 'File not found'
                }
                res_hex = bytes(json.dumps(response).encode('utf-8'))
                res_pack = struct.pack('128s', res_hex)
                conn.send(res_pack)
                self.logger.warning("File %s not exist", file_name)
        except Exception as e:
                response = {
                    'status': 'ERROR', 
                    'message': f'Error {e} occuered while downloading'
                }
                res_hex = bytes(json.dumps(response).encode('utf-8'))
                res_pack = struct.pack('128s', res_hex)
                conn.send(res_pack)
                self.logger.error("File transfer error: %s", e) 

    def handle_register(self, conn, header):
        '''
        Usage: 处理注册

        Args:
            conn: SSL Socket连接
            header: 文件头信息
        '''
        username, password = header['username'], header['password']
        try:
            hashed_password = bcrypt.hashpw(password.encode('utf-8'), bcrypt.gensalt())
            sql = "INSERT INTO users (username, password) VALUES (%s, %s)"
            val = (username, hashed_password)
            self.cursor.execute(sql, val)
            self.db.commit()
            response = {'status': 'OK', 'message': 'User registered successfully'}
            self.logger.info("User %s registered successfully.", username)
        except pymysql.IntegrityError:
            response = {'status': 'ERROR', 'message': 'User already exists'}
            self.logger.warning("Registration failed: User %s already exists.", username)
        except Exception as e:
            response = {'status': 'ERROR', 'message': str(e)}
            self.logger.error("Registration failed: %s", e)

        res_hex = bytes(json.dumps(response).encode('utf-8'))
        res_pack = struct.pack('128s', res_hex)
        conn.send(res_pack)

    def handle_login(self, conn, header):
        '''
        Usage: 处理登录

        Args:
            conn: SSL Socket连接
            header: 文件头信息
        '''
        username, password = header['username'], header['password']
        try:
            sql = "SELECT password FROM users WHERE username = %s"
            val = (username,)
            self.cursor.execute(sql, val)
            result = self.cursor.fetchone()
            if result:
                hashed_password = result[0]
                if bcrypt.checkpw(password.encode('utf-8'), hashed_password.encode('utf-8')):
                    response = {'status': 'OK', 'message': 'Login successful'}
                    self.logger.info("User %s logged in successfully.", username)
                else:
                    response = {'status': 'ERROR', 'message': 'Invalid password'}
                    self.logger.warning("Login failed: Invalid password for user %s.", username)
            else:
                response = {'status': 'ERROR', 'message': 'User does not exist'}
                self.logger.warning("Login failed: User %s does not exist.", username)
        except Exception as e:
            response = {'status': 'ERROR', 'message': str(e)}
            self.logger.error("Login failed: %s", e)

        res_hex = bytes(json.dumps(response).encode('utf-8'))
        res_pack = struct.pack('128s', res_hex)
        conn.send(res_pack)

    def encrypt_file(self, data) -> bytes:
        '''
        加密二进制数据
        
        Args: 
            data: 需要加密数据
        Returns:
            加密后的数据
        '''
        print("aes加密密钥:", self.aes_key, "aes初始向量:", self.aes_iv)
        
        digest = self.rsa_cipher.sign_message(data, self.server_private_key)
        print("消息摘要:", digest)
        
        concated_message = {"Message": base64.b64encode(data), "Digest": digest}
        dumpped_message = pickle.dumps(concated_message)
        
        cipher_message = self.aes_cipher.encrypt_message(dumpped_message)
        
        keyiv = {"Key": self.aes_key, "IV": self.aes_iv}
        print("密钥和初始向量", keyiv)
        dumpped_keyiv = pickle.dumps(keyiv)
        print("序列化后的密钥和初始向量", dumpped_keyiv)
        
        cipher_keyiv = self.rsa_cipher.encrypt_message(dumpped_keyiv, self.client_public_key)
        print("加密后的密钥和初始向量", cipher_keyiv)
        
        send_message = pickle.dumps([cipher_message, cipher_keyiv])
        print("序列化前的发送消息", [cipher_message, cipher_keyiv])
        return send_message
    
    def decrypt_file(self, data) -> bytes:
        '''
        Usage: 解密二进制数据
            
        Args: 
            data: 需要解密的数据
        Returns:
            解密后的数据(完整性通过),否则返回None
        '''
        data_dict = json.loads(data)
        cipher_message = data_dict["encrypted_data"]
        cipher_keyiv = data_dict["encrypted_key_iv"]
        # cipher_message, cipher_keyiv = pickle.loads(data)
        # print("密钥:{cipher_keyiv}, 类型{type(cipher_keyiv)}")


        decrypted_keyiv = self.rsa_cipher.decrypt_message(cipher_keyiv, self.server_private_key)



        # print("接收到的密钥和初始向量:", decrypted_keyiv)


        keyiv = json.loads(decrypted_keyiv.decode('utf-8'))
        key, iv = keyiv["key"], keyiv["iv"]

        # print(key)
        # print(iv)

        iv = base64.b64decode(iv)
        key = base64.b64decode(key)

        # print(f"解密后的密钥{key}和初始向量{iv}:")

    

        # try:
        #     # Base64 解码加密的数据
        #     encrypted_data_bytes = base64.b64decode(cipher_message)

        #     # 创建 AES 解密器
        #     cipher = AES.new(key, AES.MODE_CBC, iv)

        #     # 解密并去除 PKCS7 填充
        #     decrypted_data = unpad(cipher.decrypt(encrypted_data_bytes), AES.block_size)
        #     content = decrypted_data.decode('utf-8')
        # except Exception as e:
        #     raise Exception(f"AES 解密失败: {e}")


        aes = AESCryptor(key, iv)
        
        # print("message长度为：",len(cipher_message))
        
        # decrypted_message = aes.decrypt_message(base64.b64decode(cipher_message))


        # plain_message = pickle.loads(decrypted_message)

        # print("plain_message: ",plain_message)

        content = self.rsa_cipher.decrypt_message(cipher_message, self.server_private_key)
        # content = base64.b64decode(plain_message['Message'])
        # print("解密的内容是", content)
        # digest = plain_message['Digest']
        # print("解密的消息摘要", digest, type(digest))

        return content

        # if self.rsa_cipher.verify_signature(content, digest, self.client_public_key):
        #     print("完整性验证通过!")
        #     return content
        # else:
        #     print("文件签名不一致!")
        #     return None


if __name__ == "__main__":
    server = Server()
    server.listen()
    

# Example usage:
# Start the server: python server/server.py