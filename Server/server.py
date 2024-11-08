from flask import Flask, request
from Crypto.PublicKey import RSA
from Crypto.Cipher import PKCS1_v1_5
import base64
import ssl

app = Flask(__name__)

# path of RSA keys
public_key_path = 'keys/server/serverpublic.pem'
private_key_path = 'keys/server/serverprivate.pem'

public_key = ""
private_key = ""

def Initialize():
    # loading keys
    global public_key
    global private_key
    global chiper
    with open(public_key_path, 'rb') as f:
        public_key = f.read() #RSA.import_key(f.read())
    with open(private_key_path, 'rb') as f:
        private_key = RSA.import_key(f.read())




@app.route('/get_public_key', methods=['GET'])
def get_public_key():
    return public_key, 200

@app.route('/receive_data', methods=['POST'])
def receive_data():

    cipher=PKCS1_v1_5.new(private_key)

    encrypted_data_base64 = request.form.get('data')
    if not encrypted_data_base64:
        return "No data received", 400

    # 解密数据
    encrypted_data = base64.b64decode(encrypted_data_base64)
    decrypted_data = cipher.decrypt(encrypted_data, None).decode('utf-8')

    return f"Received and decrypted data: {decrypted_data}", 200

if __name__ == '__main__':
    context = ssl.SSLContext(ssl.PROTOCOL_TLS)
    context.load_cert_chain(certfile="certs/server.crt", keyfile="certs/server.key")
    Initialize()
    print(public_key)
    app.run(host='localhost', port=5000, ssl_context=context)