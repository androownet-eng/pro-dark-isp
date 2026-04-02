import routeros_api
import sys

def crear():
    host = '192.168.60.1'
    user = 'admin'
    password = '23763941'
    
    try:
        connection = routeros_api.RouterOsApiPool(host, username=user, password=password, plaintext_login=True)
        api = connection.get_api()
        ppp_secrets = api.get_resource('/ppp/secret')
        
        # Argumentos: 1=Usuario, 2=Clave, 3=Perfil
        ppp_secrets.add(name=sys.argv[1], password=sys.argv[2], profile=sys.argv[3], service='pppoe')
        
        print("EXITO")
        connection.disconnect()
    except Exception as e:
        print(f"ERROR: {str(e)}")

if __name__ == "__main__":
    if len(sys.argv) > 3:
        crear()