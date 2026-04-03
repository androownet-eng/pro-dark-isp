import routeros_api
import json
import sys
# Importamos las variables tal cual están en tu archivo (en MAYÚSCULAS)
from config_mk import HOST, USER, PASS

try:
    # Usamos las variables HOST, USER y PASS corregidas
    connection = routeros_api.RouterOsApiPool(
        HOST, 
        username=USER, 
        password=PASS, 
        plaintext_login=True
    )
    api = connection.get_api()

    # Traemos los secretos de PPP
    list_users = api.get_resource('/ppp/secret').get()
    
    # Preparamos la lista para PHP (Nombre y Perfil)
    result = [{"name": u['name'], "profile": u['profile']} for u in list_users]
    
    print(json.dumps(result))
    connection.disconnect()

except Exception as e:
    # Si falla, mandamos el error real para saber qué pasa
    print(json.dumps({"error": str(e)}))