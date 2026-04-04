<?php
// 1. IMPORTAR CEREBRO GLOBAL
require_once "config_global.php"; 

// IMPORTANTE: Mantenemos tu zona horaria para precisión con el MikroTik
date_default_timezone_set('America/Caracas'); 

$dia_actual = (int)date('j');
$hoy = date('Y-m-d');
$hora_actual = date('H:i:s');

$py_exe = $python_path; 
$py_script = $base_dir . "estado_cliente.py";

echo "==============================================\n";
echo "   REVISION PRO-DARK ISP - MOTOR DE CORTE     \n";
echo "   Fecha: $hoy | Hora: $hora_actual           \n";
echo "==============================================\n";

// 2. GESTIÓN DE PROTECCIÓN (Escudos)
$conn->query("UPDATE abonados SET proteccion = 1 WHERE (pagado_hasta >= '$hoy' OR proteccion_manual = 1)");
$conn->query("UPDATE abonados SET proteccion = 0 WHERE pagado_hasta < '$hoy' AND proteccion_manual = 0");

// 3. PROCESAMIENTO DE ESTADOS (Añadimos prorroga_hasta a la consulta)
$todos = $conn->query("SELECT user_mikrotik, plan, estado, dia_pago, proteccion, hora_corte, prorroga_hasta FROM abonados");

$cambios = 0;

while ($r = $todos->fetch_assoc()) {
    $u = $r['user_mikrotik'];
    $estado_db = $r['estado'];
    $proteccion = (int)$r['proteccion'];
    $dia_pago = (int)$r['dia_pago'];
    $hora_corte = $r['hora_corte'];
    
    // Verificamos si tiene una prórroga viva en este preciso momento
    $tiene_prorroga_valida = (!empty($r['prorroga_hasta']) && strtotime($r['prorroga_hasta']) > time());

    $debe_estar_cortado = false;

    // Solo lo sentenciamos a corte si NO tiene protección Y NO tiene prórroga válida
    if ($proteccion == 0 && !$tiene_prorroga_valida) {
        if ($dia_pago < $dia_actual) {
            $debe_estar_cortado = true;
        } 
        elseif ($dia_pago == $dia_actual && $hora_actual >= $hora_corte) {
            $debe_estar_cortado = true;
        }
    }

    // --- BLINDAJE DE SEGURIDAD ---
    $u_esc = escapeshellarg($u); 

    // --- SINCRONIZACIÓN (DB -> MikroTik) ---

    // CASO A: Vencimiento detectado (Ya no afectará a los que tienen prórroga)
    if ($debe_estar_cortado && $estado_db != 'cortado') {
        echo "[!] EVENTO: Vencimiento -> $u\n";
        
        // Limpiamos la prórroga por si acaso acababa de expirar
        $conn->query("UPDATE abonados SET estado = 'cortado', prorroga_hasta = NULL WHERE user_mikrotik = '$u'");

        // Llamada blindada a Python
        shell_exec("\"$py_exe\" \"$py_script\" $u_esc \"sync\"");

        echo "    SISTEMA: Perfil CORTE aplicado.\n";
        $cambios++;
    } 

    // CASO B: Reactivación (Por pago o cambio de fecha)
    elseif (!$debe_estar_cortado && $estado_db != 'activo') {
        echo "[*] EVENTO: Reactivación -> $u\n";

        $conn->query("UPDATE abonados SET estado = 'activo' WHERE user_mikrotik = '$u'");

        // Llamada blindada a Python
        shell_exec("\"$py_exe\" \"$py_script\" $u_esc \"sync\"");

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
$conn->close();
?>