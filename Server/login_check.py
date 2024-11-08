from flask import Flask, request, jsonify, session
import pymysql
from Crypto.PublicKey import RSA
from Crypto.Cipher import PKCS1_OAEP
import base64

app = Flask(__name__)
app.secret_key = 'supersecretkey'

# MySQL database connection configuration
DB_HOST = '127.0.0.1'
DB_USER = 'root'
DB_PASSWORD = 'your_password'
DB_NAME = 'user_database'

# Helper function to connect to the database
def get_db_connection():
    return pymysql.connect(host='127.0.0.1', port=3306, user='root', passwd='cattac', db='file_transfer', charset='utf8mb4')

# Load server private key
with open('keys/server_private.pem', 'rb') as f:
    server_private_key = RSA.import_key(f.read())

private_cipher = PKCS1_OAEP.new(server_private_key)

@app.route('/login', methods=['POST'])
def login():
    data = request.get_json()
    encrypted_username = base64.b64decode(data.get('username'))
    encrypted_password = base64.b64decode(data.get('password'))

    # Decrypt the username and password using the server's private key
    try:
        username = private_cipher.decrypt(encrypted_username).decode('utf-8')
        password = private_cipher.decrypt(encrypted_password).decode('utf-8')
    except ValueError:
        return jsonify({'message': 'Invalid encryption'}), 400

    # Verify username and password with the database
    connection = get_db_connection()
    try:
        with connection.cursor() as cursor:
            sql = "SELECT * FROM users WHERE username=%s AND password=%s"
            cursor.execute(sql, (username, password))
            user = cursor.fetchone()
            if user:
                session['username'] = username
                return jsonify({'message': 'Login successful!'}), 200
            else:
                return jsonify({'message': 'Invalid username or password'}), 401
    finally:
        connection.close()

@app.route('/logout', methods=['POST'])
def logout():
    session.pop('username', None)
    return jsonify({'message': 'You have been logged out'}), 200

if __name__ == '__main__':
    app.run(debug=True)
