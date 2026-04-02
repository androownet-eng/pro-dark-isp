import routeros_api
import sys

user_target = sys.argv[1]

host = '192.168.60.1'
user = 'admin'
password = '23763941'

connection = routeros_api.RouterOsApiPool(host, username=user, password=password, plaintext_login=True)
api = connection.get_api()

secret_res = api.get_resource('/ppp/secret')
cliente = secret_res.get(name=user_target)

if cliente:
    secret_res.remove(id=cliente[0]['id'])
    print(f"Cliente {user_target} eliminado del MikroTik.")

connection.disconnect()