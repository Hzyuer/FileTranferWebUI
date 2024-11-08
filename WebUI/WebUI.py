from flask import Flask, request, render_template, redirect, url_for, session, send_from_directory, flash, jsonify
import os
from werkzeug.utils import secure_filename
import pymysql
from Crypto.PublicKey import RSA
from Crypto.Cipher import PKCS1_OAEP
import base64
import ssl
import socket

app = Flask(__name__, template_folder='../templates')
app.secret_key = 'supersecretkey'
UPLOAD_FOLDER = 'uploads'
ALLOWED_EXTENSIONS = {'txt', 'pdf', 'png', 'jpg', 'jpeg', 'gif'}

# Ensure the upload directory exists
if not os.path.exists(UPLOAD_FOLDER):
    os.makedirs(UPLOAD_FOLDER)

app.config['UPLOAD_FOLDER'] = UPLOAD_FOLDER

# Server info
SERVER_ADDRESS = '127.0.0.1'
SERVER_PORT = 12345

# MySQL database connection configuration
DB_HOST = 'localhost'
DB_USER = 'root'
DB_PASSWORD = 'your_password'
DB_NAME = 'user_database'

# Load server private key
with open('keys/server/serverprivate.pem', 'rb') as f:
    server_private_key = RSA.import_key(f.read())

private_cipher = PKCS1_OAEP.new(server_private_key)

# Helper function to check allowed file extensions
def allowed_file(filename):
    return '.' in filename and filename.rsplit('.', 1)[1].lower() in ALLOWED_EXTENSIONS

# Helper function to connect to the database
def get_db_connection():
    return pymysql.connect(host='127.0.0.1', port=3306, user='root', passwd='cattac', db='file_transfer', charset='utf8mb4')

# Initialize SSL context for secure connection
def initialize_ssl_connection():
    context = ssl.create_default_context()
    context.load_verify_locations("certs/server.crt")
    sock = socket.create_connection((SERVER_ADDRESS, SERVER_PORT))
    ssock = context.wrap_socket(sock, server_hostname=SERVER_ADDRESS)
    return ssock

@app.route('/')
def index():
    if 'username' in session:
        return redirect(url_for('upload'))
    return render_template('login.html')

@app.route('/login', methods=['GET', 'POST'])
def login():
    if request.method == 'POST':
        username = request.form['username']
        password = request.form['password']

        # Verify username and password with the database
        connection = get_db_connection()
        try:
            with connection.cursor() as cursor:
                sql = "SELECT * FROM users WHERE username=%s AND password=%s"
                cursor.execute(sql, (username, password))
                user = cursor.fetchone()
                if user:
                    session['username'] = username
                    flash('Login successful!', 'success')
                    return redirect(url_for('upload'))
                else:
                    flash('Invalid username or password', 'danger')
        finally:
            connection.close()

    return render_template('login.html')

@app.route('/logout')
def logout():
    session.pop('username', None)
    flash('You have been logged out', 'info')
    return redirect(url_for('index'))

@app.route('/upload', methods=['GET', 'POST'])
def upload():
    if 'username' not in session:
        flash('Please log in first', 'warning')
        return redirect(url_for('login'))

    if request.method == 'POST':
        if 'file' not in request.files:
            flash('No file part', 'danger')
            return redirect(request.url)
        file = request.files['file']
        if file.filename == '':
            flash('No selected file', 'danger')
            return redirect(request.url)
        if file and allowed_file(file.filename):
            filename = secure_filename(file.filename)
            file.save(os.path.join(app.config['UPLOAD_FOLDER'], filename))
            flash('File uploaded successfully!', 'success')
            return redirect(url_for('uploaded_files'))
    return render_template('upload.html')

@app.route('/uploads')
def uploaded_files():
    if 'username' not in session:
        flash('Please log in first', 'warning')
        return redirect(url_for('login'))

    files = os.listdir(app.config['UPLOAD_FOLDER'])
    return render_template('uploaded_files.html', files=files)

@app.route('/download/<filename>')
def download_file(filename):
    if 'username' not in session:
        flash('Please log in first', 'warning')
        return redirect(url_for('login'))
    return send_from_directory(app.config['UPLOAD_FOLDER'], filename)

# Endpoint to initialize SSL connection
@app.route('/initialize_ssl', methods=['POST'])
def initialize_ssl():
    try:
        ssock = initialize_ssl_connection()
        return jsonify({"message": "SSL connection established"}), 200
    except Exception as e:
        return jsonify({"message": f"Failed to initialize SSL: {str(e)}"}), 500

# Endpoint to exchange public keys
@app.route('/exchange_pubkey', methods=['POST'])
def exchange_pubkey():
    try:
        # Load the client's public key
        client_public_key_path = "keys/client/clientpublic.pem"
        with open(client_public_key_path, "rb") as f:
            client_public_key = RSA.import_key(f.read())
        
        # Send the public key to the server (simulated here)
        server_public_key = request.json.get("server_public_key")
        
        # Save server public key (for simplicity, just print it here)
        print("Received server public key:", server_public_key)
        
        return jsonify({"client_public_key": client_public_key.export_key().decode('utf-8')}), 200
    except Exception as e:
        return jsonify({"message": f"Failed to exchange public keys: {str(e)}"}), 500

# Endpoint to verify the server's public key
@app.route('/verify_key', methods=['POST'])
def verify_key():
    try:
        # Here you would normally have code to verify the server's public key integrity
        server_key = request.json.get("server_key")
        # Placeholder for actual verification logic
        if server_key:
            return jsonify({"message": "Server public key verified successfully"}), 200
        else:
            return jsonify({"message": "Server public key verification failed"}), 400
    except Exception as e:
        return jsonify({"message": f"Failed to verify key: {str(e)}"}), 500

if __name__ == '__main__':
    app.run(debug=True)
