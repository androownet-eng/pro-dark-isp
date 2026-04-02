<?php
// 1. IMPORTAR CEREBRO GLOBAL
// Esto elimina la necesidad de configurar la conexión y rutas aquí mismo.
require_once "config_global.php"; 

// IMPORTANTE: Mantenemos tu zona horaria para precisión con el MikroTik
date_default_timezone_set('America/Caracas'); 

$dia_actual = (int)date('j');
$hoy = date('Y-m-d');
$hora_actual = date('H:i:s');

// Usamos las variables que vienen de config_global.php
$py_exe = $python_path; 
$py_script = $base_dir . "estado_cliente.py";

echo "==============================================\n";
echo "   REVISION PRO-DARK ISP - MOTOR DE CORTE     \n";
echo "   Fecha: $hoy | Hora: $hora_actual           \n";
echo "==============================================\n";

// 2. GESTIÓN DE PROTECCIÓN (Escudos)
// Simplificamos la lógica: Si pagó a futuro o tiene protección manual, tiene escudo.
$conn->query("UPDATE abonados SET proteccion = 1 WHERE (pagado_hasta >= '$hoy' OR proteccion_manual = 1)");
$conn->query("UPDATE abonados SET proteccion = 0 WHERE pagado_hasta < '$hoy' AND proteccion_manual = 0");


// 3. PROCESAMIENTO DE ESTADOS
$todos = $conn->query("SELECT user_mikrotik, plan, estado, dia_pago, proteccion, hora_corte FROM abonados");

$cambios = 0;

while ($r = $todos->fetch_assoc()) {
    $u = $r['user_mikrotik'];
    $estado_db = $r['estado'];
    $proteccion = (int)$r['proteccion'];
    $dia_pago = (int)$r['dia_pago'];
    $hora_corte = $r['hora_corte'];

    $debe_estar_cortado = false;
    
    if ($proteccion == 0) {
        // Lógica Pro-Dark: Día vencido O Día actual después de la hora
        if ($dia_pago < $dia_actual) {
            $debe_estar_cortado = true;
        } 
        elseif ($dia_pago == $dia_actual && $hora_actual >= $hora_corte) {
            $debe_estar_cortado = true;
        }
    }

    // --- SINCRONIZACIÓN (DB -> MikroTik) ---

    // CASO A: Vencimiento detectado
    if ($debe_estar_cortado && $estado_db != 'cortado') {
        echo "[!] EVENTO: Vencimiento -> $u\n";
        
        $conn->query("UPDATE abonados SET estado = 'cortado' WHERE user_mikrotik = '$u'");
        
        // Llamada centralizada a Python
        shell_exec("\"$py_exe\" \"$py_script\" \"$u\" \"sync\"");
        
        echo "    SISTEMA: Perfil CORTE aplicado.\n";
        $cambios++;
    } 
    
    // CASO B: Reactivación (Por pago o cambio de fecha)
    elseif (!$debe_estar_cortado && $estado_db != 'activo') {
        echo "[*] EVENTO: Reactivación -> $u\n";
        
        $conn->query("UPDATE abonados SET estado = 'activo' WHERE user_mikrotik = '$u'");
        
        // Llamada centralizada a Python
        shell_exec("\"$py_exe\" \"$py_script\" \"$u\" \"sync\"");
        
        echo "    SISTEMA: Cliente reactivado con su plan real.\n";
        $cambios++;
    }
}

if ($cambios == 0) {
    echo "[-] STATUS: Sin cambios pendientes.\n";
} else {
    echo "[OK] STATUS: $cambios correcciones aplicadas.\n";
}

echo "----------------------------------------------\n\n";
// No cerramos $conn aquí si planeas incluir este archivo en otros, 
// pero para el Guardián está bien que se cierre al terminar el script.
$conn->close();
?>