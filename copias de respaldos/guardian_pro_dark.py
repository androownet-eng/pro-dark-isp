import subprocess
import time
import os
import sys

# --- CONFIGURACIÓN DE RUTAS ---
# Usamos r"" para evitar problemas con las barras invertidas de Windows
PHP_PATH = r"C:\xampp\php\php.exe"
REVISAR_FECHAS_PHP = r"C:\xampp\htdocs\gestion\revisar_fechas.php"

# Título de la ventana (Solo para Windows)
os.system("title GUARDIAN PRO-DARK ISP - Activo")

def ejecutar_revision():
    print(f"[{time.strftime('%H:%M:%S')}] --- Iniciando ciclo de monitoreo ---")
    try:
        # El Guardián activa al ejecutor PHP
        # capture_output=True permite que lo que diga el PHP se vea aquí en Python
        proceso = subprocess.run(
            [PHP_PATH, REVISAR_FECHAS_PHP], 
            capture_output=True, 
            text=True,
            encoding='latin-1' # Para evitar errores con tildes o caracteres especiales
        )
        
        # Mostramos en pantalla lo que el sistema encontró
        if proceso.stdout:
            print(f"RESULTADO:\n{proceso.stdout.strip()}")
        
        if proceso.stderr:
            print(f"AVISO SISTEMA: {proceso.stderr.strip()}")

    except Exception as e:
        print(f"ERROR CRÍTICO: No se pudo ejecutar la revisión. {e}")

# --- BUCLE INFINITO ---
print("==============================================")
print("     SISTEMA DE MONITOREO PRO-DARK ISP        ")
print("          NTP SINCRONIZADO - ACTIVO           ")
print("==============================================")
print("Presiona Ctrl+C para detener el monitoreo.\n")

while True:
    ejecutar_revision()
    
    # Espera 60 segundos (1 minuto) antes de la próxima revisión
    # Puedes bajarlo a 30 si quieres más precisión
    time.sleep(60)