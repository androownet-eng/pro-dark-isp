import routeros_api
import mysql.connector
import sys
from datetime import datetime

# --- CONFIGURACIÓN (Extraída de tu config.php) ---
MK_DATA = {'host': '192.168.60.1', 'user': 'admin', 'pass': '23763941'}
DB_DATA = {'host': 'localhost', 'user': 'root', 'pw': '', 'db': 'isp_gestion'}

def conectar_db():
    return mysql.connector.connect(
        host=DB_DATA['host'], user=DB_DATA['user'], 
        password=DB_DATA['pw'], database=DB_DATA['db']
    )

def procesar():
    if len(sys.argv) < 2: return
    
    user_target = sys.argv[1]
    accion = sys.argv[2].lower() if len(sys.argv) > 2 else 'sync'
    plan_manual = sys.argv[3] if len(sys.argv) > 3 else None
    pass_manual = sys.argv[4] if len(sys.argv) > 4 else None

    try:
        db = conectar_db()
        cursor = db.cursor(dictionary=True)
        connection = routeros_api.RouterOsApiPool(MK_DATA['host'], username=MK_DATA['user'], password=MK_DATA['pass'], plaintext_login=True)
        api = connection.get_api()
        res_ppp = api.get_resource('/ppp/secret')
        res_active = api.get_resource('/ppp/active')

        # 1. ACCIÓN ELIMINAR
        if accion == 'eliminar':
            m_user = res_ppp.get(name=user_target)
            if m_user:
                res_ppp.remove(id=m_user[0]['id'])
                sesion = res_active.get(name=user_target)
                for s in sesion: res_active.remove(id=s['id'])
            return

        # 2. LECTURA DE LA BASE DE DATOS (Aquí es donde ocurre la magia de la prórroga)
        cursor.execute("SELECT plan, estado, prorroga_hasta, proteccion, proteccion_manual FROM abonados WHERE user_mikrotik = %s", (user_target,))
        cliente = cursor.fetchone()
        if not cliente: return

        # --- LÓGICA DE DECISIÓN ---
        perfil_objetivo = plan_manual if plan_manual else cliente['plan']
        ahora = datetime.now()

        # ¿Tiene prórroga activa?
        tiene_prorroga = cliente['prorroga_hasta'] and cliente['prorroga_hasta'] > ahora
        # ¿Tiene escudo activo?
        tiene_escudo = (cliente['proteccion'] == 1 or cliente['proteccion_manual'] == 1)

        # Aplicamos el perfil CORTE solo si no hay excepciones
        if accion == 'cortar':
            perfil_objetivo = 'CORTE'
        elif cliente['estado'] == 'cortado':
            if not tiene_prorroga and not tiene_escudo:
                perfil_objetivo = 'CORTE'

        # 3. ACTUALIZAR MIKROTIK
        m_user = res_ppp.get(name=user_target)
        if m_user:
            uid = m_user[0]['id']
            # Si el perfil en el MikroTik es distinto al que decidimos aquí, lo cambiamos
            if m_user[0]['profile'] != perfil_objetivo or (pass_manual and m_user[0]['password'] != pass_manual):
                res_ppp.set(id=uid, profile=perfil_objetivo)
                if pass_manual: res_ppp.set(id=uid, password=pass_manual)
                
                # Desconectar para aplicar cambio
                sesion = res_active.get(name=user_target)
                for s in sesion: res_active.remove(id=s['id'])
        else:
            # Si no existe, lo creamos con los datos del panel
            if perfil_objetivo != 'CORTE':
                clave = pass_manual if pass_manual else user_target
                res_ppp.add(name=user_target, password=clave, profile=perfil_objetivo, service='pppoe')

        db.close()
        connection.disconnect()
    except Exception as e: print(f"Error: {e}")

if __name__ == "__main__":
    procesar()