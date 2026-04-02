import subprocess
import time
import os
import sys

# Intentamos importar el config con validación
try:
    import config_mk as cfg
except ImportError:
    print("ERROR: No se encontró 'config_mk.py'.")
    sys.exit()

# Validación de variables necesarias
try:
    PHP_PATH = cfg.PHP_EXE
    BASE_DIR = cfg.BASE_DIR
except AttributeError:
    print("ERROR: Falta definir PHP_EXE o BASE_DIR en config_mk.py")
    sys.exit()

REVISAR_FECHAS_PHP = os.path.join(BASE_DIR, "revisar_fechas.php")

os.system("title GUARDIAN PRO-DARK ISP - Activo")

def ejecutar_revision():
    ahora = time.strftime('%H:%M:%S')
    print(f"[{ahora}] --- Escaneando abonados ---")
    
    try:
        proceso = subprocess.run(
            [PHP_PATH, REVISAR_FECHAS_PHP], 
            capture_output=True, 
            text=True,
            encoding='latin-1' 
        )
        
        if proceso.stdout:
            print(f"SISTEMA: {proceso.stdout.strip()}")
        
        if proceso.stderr:
            # Esto captura errores de PHP (como fallos de conexión a la DB)
            print(f"ALERTA: {proceso.stderr.strip()}")

    except Exception as e:
        print(f"¡ERROR CRÍTICO!: {e}")

# --- BUCLE PRINCIPAL ---
print("==============================================")
print("     GUARDIAN ACTIVO - PRO-DARK ISP           ")
print(f"     MONITORIANDO: {REVISAR_FECHAS_PHP}      ")
print("==============================================")

try:
    while True:
        ejecutar_revision()
        time.sleep(60) # Revisión cada minuto
except KeyboardInterrupt:
    print("\n[!] Guardián detenido por el usuario.")
    sys.exit()