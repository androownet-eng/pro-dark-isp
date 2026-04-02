import routeros_api
import sys
import os

# Configuración de ruta
os.chdir(os.path.dirname(os.path.abspath(__file__)))

# Argumentos: 1:usuario, 2:accion (vencido/pagado)
if len(sys.argv) < 3:
    print("Error: Se requiere usuario y accion.")
    sys.exit()

user_mikrotik = sys.argv[1]
accion = sys.argv[2]

HOST = '192.168.60.1'
USER = 'admin'
PASS = '23763941'

try:
    connection = routeros_api.RouterOsApiPool(HOST, username=USER, password=PASS, plaintext_login=True)
    api = connection.get_api()
    
    res_active = api.get_resource('/ppp/active')
    res_addr = api.get_resource('/ip/firewall/address-list')

    # --- LÓGICA 1: CORTE AUTOMÁTICO (Solo si no ha pagado) ---
    if accion.lower() == 'vencido':
        # Antes de cortar, el sistema verifica si está conectado
        sesion = res_active.get(name=user_mikrotik)
        
        if sesion:
            ip_actual = sesion[0].get('address')
            
            # Verificamos si ya está en la lista
            check = res_addr.get(address=ip_actual, list='CORTADOS')
            
            if not check:
                res_addr.add(
                    list='CORTADOS', 
                    address=ip_actual, 
                    comment=f"ID:{user_mikrotik}"
                )
                print(f"SISTEMA: {user_mikrotik} cortado por vencimiento.")
            else:
                print(f"SISTEMA: {user_mikrotik} ya estaba en la lista de corte.")
        else:
            print(f"SISTEMA: {user_mikrotik} vencido pero desconectado. Se cortará al conectar.")

    # --- LÓGICA 2: ACTIVACIÓN Y PROTECCIÓN (Si pagó antes de la fecha) ---
    elif accion.lower() == 'pagado':
        # 1. Si ya estaba cortado, lo saca de la lista (Activación)
        entradas = res_addr.get()
        fue_liberado = False
        
        for e in entradas:
            if e.get('comment') == f"ID:{user_mikrotik}":
                res_addr.remove(id=e['id'])
                fue_liberado = True
        
        if fue_liberado:
            print(f"SISTEMA: {user_mikrotik} ha pagado. Activado de inmediato.")
        else:
            # 2. Si no estaba cortado, el sistema lo registra como "Protegido"
            # Esta parte es vital: PHP no enviará la orden de 'vencido' si el estatus es 'pagado'
            print(f"SISTEMA: {user_mikrotik} pago registrado. Protegido contra el corte.")

    connection.disconnect()

except Exception as e:
    print(f"ERROR: {e}")