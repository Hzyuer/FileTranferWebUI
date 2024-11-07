import hashlib

def sha256_hash(data: str):
    '''
    return a sha256encoded string
    '''
    print(data)
    sha256 = hashlib.sha256()
    sha256.update(data)
    res = sha256.hexdigest()
    return res

if __name__ == '__main__':
    e = sha256_hash('testing'.encode('utf-8'))
    print(e)
    