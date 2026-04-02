import routeros_api
import json
import sys
import config_mk as cfg  # <--- CONECTAMOS AL CEREBRO PYTHON

def obtener_usuarios_online():
    try:
        # Usamos las variables globales de config_mk.py
        connection = routeros_api.RouterOsApiPool(
            cfg.HOST, 
            username=cfg.USER, 
            password=cfg.PASS, 
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
        # Devolvemos el error en formato JSON para no romper el decode de PHP
        return json.dumps({"error": str(e)})

if __name__ == "__main__":
    # Importante: Solo imprimimos el JSON final
    print(obtener_usuarios_online())