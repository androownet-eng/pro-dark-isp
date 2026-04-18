import routeros_api
import mysql.connector
import sys
from datetime import datetime
import config_mk as cfg  # <--- IMPORTAMOS TU CEREBRO CONFIG_MK

def conectar_db():
    return mysql.connector.connect(
        host=cfg.DB_HOST, 
        user=cfg.DB_USER, 
        password=cfg.DB_PASS, 
        database=cfg.DB_NAME
    )

def procesar():
    if len(sys.argv) < 2: return

    user_target = sys.argv[1]
    accion = sys.argv[2].lower() if len(sys.argv) > 2 else 'sync'
    plan_manual = sys.argv[3] if len(sys.argv) > 3 else None
    pass_manual = sys.argv[4] if len(sys.argv) > 4 else None

    try:
        # Nos conectamos al MikroTik usando el cerebro cfg
        connection = routeros_api.RouterOsApiPool(
            cfg.HOST, 
            username=cfg.USER, 
            password=cfg.PASS, 
            plaintext_login=True
        )
        api = connection.get_api()

        # --- NUEVA ACCIÓN: CONTAR CONECTADOS ---
        if accion == 'contar':
            res_active = api.get_resource('/ppp/active')
            conexiones = res_active.get()
            print(len(conexiones))
            connection.disconnect()
            return  # Salimos rápido, no hace falta tocar MySQL

        res_ppp = api.get_resource('/ppp/secret')
        res_active = api.get_resource('/ppp/active')

        # 1. ACCIÓN ELIMINAR
        if accion == 'eliminar':
            m_user = res_ppp.get(name=user_target)
            if m_user:
                res_ppp.remove(id=m_user[0]['id'])
                sesion = res_active.get(name=user_target)
                for s in sesion: 
                    try:
                        res_active.remove(id=s['id'])
                    except:
                        pass
            connection.disconnect()
            return

        # 2. LECTURA DE LA BASE DE DATOS
        db = conectar_db()
        cursor = db.cursor(dictionary=True)
        cursor.execute("SELECT plan, estado, prorroga_hasta, proteccion, proteccion_manual FROM abonados WHERE user_mikrotik = %s", (user_target,))
        cliente = cursor.fetchone()
        
        if not cliente: 
            db.close()
            connection.disconnect()
            return

        # --- LÓGICA DE DECISIÓN ---
        perfil_objetivo = plan_manual if plan_manual else cliente['plan']
        ahora = datetime.now()

        tiene_prorroga = cliente['prorroga_hasta'] and cliente['prorroga_hasta'] > ahora
        tiene_escudo = (cliente['proteccion'] == 1 or cliente['proteccion_manual'] == 1)

        if accion == 'cortar':
            perfil_objetivo = 'CORTE'
        elif cliente['estado'] == 'cortado':
            if not tiene_prorroga and not tiene_escudo:
                perfil_objetivo = 'CORTE'

        # 3. ACTUALIZAR MIKROTIK
        m_user = res_ppp.get(name=user_target)
        if m_user:
            uid = m_user[0]['id']
            cambio_necesario = False

            params = {}
            if m_user[0]['profile'] != perfil_objetivo:
                params['profile'] = perfil_objetivo
                cambio_necesario = True

            if pass_manual and m_user[0]['password'] != pass_manual:
                params['password'] = pass_manual
                cambio_necesario = True

            if cambio_necesario:
                res_ppp.set(id=uid, **params)
                sesion = res_active.get(name=user_target)
                for s in sesion: 
                    try:
                        res_active.remove(id=s['id'])
                    except:
                        pass
        else:
            if perfil_objetivo != 'CORTE':
                clave = pass_manual if pass_manual else user_target
                res_ppp.add(name=user_target, password=clave, profile=perfil_objetivo, service='pppoe')

        db.close()
        connection.disconnect()
    except Exception as e: 
        print(f"Error en Pro-Dark System: {e}")

if __name__ == "__main__":
    procesar()