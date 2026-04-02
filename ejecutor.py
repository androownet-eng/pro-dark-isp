import routeros_api
import sys
import os
import config_mk as cfg  # <--- IMPORTAMOS TU CEREBRO CONFIG_MK

# Configuración de ruta (Mantenemos tu lógica de ruta relativa)
os.chdir(os.path.dirname(os.path.abspath(__file__)))

# Argumentos: 1:usuario, 2:accion (vencido/pagado)
if len(sys.argv) < 3:
    print("Error: Se requiere usuario y accion.")
    sys.exit()

user_mikrotik = sys.argv[1]
accion = sys.argv[2]

try:
    # USAMOS LAS VARIABLES DEL CEREBRO CENTRAL
    connection = routeros_api.RouterOsApiPool(
        cfg.HOST, 
        username=cfg.USER, 
        password=cfg.PASS, 
        plaintext_login=True
    )
    api = connection.get_api()
    
    res_active = api.get_resource('/ppp/active')
    res_addr = api.get_resource('/ip/firewall/address-list')

    # --- LÓGICA 1: CORTE AUTOMÁTICO ---
    if accion.lower() == 'vencido':
        sesion = res_active.get(name=user_mikrotik)
        
        if sesion:
            ip_actual = sesion[0].get('address')
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
            print(f"SISTEMA: {user_mikrotik} vencido pero desconectado.")

    # --- LÓGICA 2: ACTIVACIÓN Y PROTECCIÓN ---
    elif accion.lower() == 'pagado':
        # Buscamos en la lista 'CORTADOS' por comentario para ser precisos
        entradas = res_addr.get(list='CORTADOS')
        fue_liberado = False
        
        for e in entradas:
            if e.get('comment') == f"ID:{user_mikrotik}":
                res_addr.remove(id=e['id'])
                fue_liberado = True
        
        if fue_liberado:
            print(f"SISTEMA: {user_mikrotik} ha pagado. Activado de inmediato.")
        else:
            print(f"SISTEMA: {user_mikrotik} pago registrado. Protegido.")

    connection.disconnect()

except Exception as e:
    print(f"ERROR EN PRO-DARK CORTE: {e}")