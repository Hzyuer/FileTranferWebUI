import base64
from Crypto.PublicKey import RSA
from Crypto.Hash import SHA256
from Crypto.Signature import pkcs1_15
from Crypto.Cipher import PKCS1_OAEP


class RSACryptor:
    '''
    Class: a encrypotro with default key size of 2048
    Class variables: key_size, private_key, public_key, rsa_key
    '''
    def __init__(self, key_size=2048) -> None:
        self.key_size = key_size
        self.private_key = None
        self.public_key = None
        self.rsa_key = None

    def gen_rsa_key_pairs(self):
        '''
        Function Usage: generate rsa public and private keys
        '''
        key = RSA.generate(self.key_size)
        self.private_key = key.export_key("PEM")
        self.public_key = key.publickey().export_key()

    def save_keys(self, save_dir: str):
        '''
        Function Usage: save public&private keys
        Variables: save_dir --> keys' folder 
        '''
        if self.private_key and self.public_key:
            with open(save_dir + "private.pem", "wb") as f:
                f.write(self.private_key)
            with open(save_dir + "public.pem", "wb") as f:
                f.write(self.public_key)
        else:
            raise ValueError("Keys have not been generated yet.")
        
    def load_keys(self, load_dir: str):
        '''
        Function Usage: load public&private keys
        Variables: save_dir --> keys' folder
        '''
        with open(load_dir + "private.pem", "rb") as f:
            self.private_key = RSA.import_key(f.read())
        with open(load_dir + "public.pem", "rb") as f:
            self.public_key = RSA.import_key(f.read())

    def encrypt_message(self, message: bytes | str, public_key=None) -> bytes:
        '''
        Function Usage: Encrypt messages
        Variables:
            message: str or bytes
            public_key
        '''
        if public_key == None:
            public_key = self.public_key

        self.rsa_key = RSA.import_key(public_key)
        
        if isinstance(message, str):
            message = message.encode('utf-8')
        
        cipher_rsa = PKCS1_OAEP.new(self.rsa_key)
        encrypted_message = base64.b64encode(cipher_rsa.encrypt(message))
        return encrypted_message
    
    def decrypt_message(self, encrypted_message: bytes | str, private_key=None ) -> bytes:
        '''
        Function Usage: decrypt messages
        Variables:
            encrypted_message
            private_key
        '''
        if private_key == None:
            private_key = self.private_key

        self.rsa_key = RSA.import_key(private_key)
        cipher_rsa = PKCS1_OAEP.new(self.rsa_key)
        decrypted_message = cipher_rsa.decrypt(base64.b64decode(encrypted_message))
        return decrypted_message
    
# testing
if __name__ == "__main__":
    rsa_encryption = RSACryptor()
    rsa_encryption.gen_rsa_key_pairs()

    message = "This is the original message"

    print(message)

    en_message = rsa_encryption.encrypt_message(message)

    print(en_message)

    de_message = rsa_encryption.decrypt_message(en_message)

    print(de_message)