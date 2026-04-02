import routeros_api
import json
import sys

# CONFIGURACIÓN MIKROTIK (DATOS ACTUALIZADOS)
host = '192.168.60.1'
user = 'admin'
password = '23763941'

def obtener_usuarios_online():
    try:
        # Conexión al MikroTik
        connection = routeros_api.RouterOsApiPool(
            host, 
            username=user, 
            password=password, 
            plaintext_login=True
        )
        api = connection.get_api()
        
        # Consultamos las conexiones PPP activas
        active_resource = api.get_resource('/ppp/active')
        active_users = active_resource.get()
        
        # Creamos un diccionario: { "NombreUsuario": "DireccionIP" }
        online_dict = {}
        for u in active_users:
            nombre = u.get('name')
            ip = u.get('address', '0.0.0.0')
            if nombre:
                # El nombre se guarda tal cual para que PHP lo compare
                online_dict[nombre] = ip
        
        connection.disconnect()
        
        # Enviamos el JSON para que el PHP de la web lo reciba
        return json.dumps(online_dict)

    except Exception as e:
        # En caso de error, devolvemos el mensaje para saber qué falló
        return json.dumps({"error": str(e)})

if __name__ == "__main__":
    print(obtener_usuarios_online())