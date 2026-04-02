<?php
// --- CONFIGURACIÓN DE CONEXIÓN ---
$conn = new mysqli("localhost", "root", "", "isp_gestion");

// IMPORTANTE: Ajusta tu zona horaria para que coincida con MikroTik
date_default_timezone_set('America/Caracas'); 

$dia_actual = (int)date('j');
$hoy = date('Y-m-d');
$hora_actual = date('H:i:s');

// Rutas absolutas para evitar errores en el lanzador
$python = "C:/Users/Androow/AppData/Local/Programs/Python/Python37/python.exe";
$script = "C:/xampp/htdocs/gestion/estado_cliente.py";

echo "==============================================\n";
echo "   REVISIÓN PRO-DARK ISP - MOTOR DE CORTE     \n";
echo "   Fecha: $hoy | Hora: $hora_actual           \n";
echo "==============================================\n";

// 1. GESTIÓN DE PROTECCIÓN (Escudos)
// A. Poner escudo (proteccion=1) si: hay pago futuro O el manual está activo
$conn->query("UPDATE abonados SET proteccion = 1 WHERE (pagado_hasta >= '$hoy' OR proteccion_manual = 1)");

// B. QUITAR escudo (proteccion=0) si: el pago ya venció Y el manual está apagado
$conn->query("UPDATE abonados SET proteccion = 0 WHERE pagado_hasta < '$hoy' AND proteccion_manual = 0");


// 2. BUSCAR TODOS LOS ABONADOS PARA PROCESAR ESTADOS
$todos = $conn->query("SELECT user_mikrotik, plan, estado, dia_pago, proteccion, hora_corte FROM abonados");

$cambios = 0;

while ($r = $todos->fetch_assoc()) {
    $u = $r['user_mikrotik'];
    $estado_db = $r['estado'];
    $proteccion = (int)$r['proteccion'];
    $dia_pago = (int)$r['dia_pago'];
    $hora_corte = $r['hora_corte'];

    // LÓGICA DE DECISIÓN PRO-DARK
    $debe_estar_cortado = false;
    
    if ($proteccion == 0) {
        // Si el día de pago ya pasó
        if ($dia_pago < $dia_actual) {
            $debe_estar_cortado = true;
        } 
        // Si el día de pago es hoy pero ya pasó la hora de corte
        elseif ($dia_pago == $dia_actual && $hora_actual >= $hora_corte) {
            $debe_estar_cortado = true;
        }
    }

    // --- APLICACIÓN DE CAMBIOS (ORDEN CORREGIDO: DB PRIMERO) ---

    // CASO A: El sistema decide que debe estar CORTADO pero en DB figura ACTIVO
    if ($debe_estar_cortado && $estado_db != 'cortado') {
        echo "[!] EVENTO: Vencimiento detectado para -> $u\n";
        
        // 1. PRIMERO: Actualizamos la Base de Datos
        $conn->query("UPDATE abonados SET estado = 'cortado' WHERE user_mikrotik = '$u'");
        
        // 2. SEGUNDO: Llamamos a Python con modo SYNC (Ahora leerá 'cortado' de la DB)
        shell_exec("\"$python\" \"$script\" \"$u\" \"sync\"");
        
        echo "    RESULTADO: Cliente enviado a perfil CORTE y pateado.\n";
        $cambios++;
    } 
    
    // CASO B: El sistema decide que debe estar ACTIVO pero en DB figura CORTADO
    elseif (!$debe_estar_cortado && $estado_db != 'activo') {
        echo "[*] EVENTO: Reactivación/Sincronía para -> $u\n";
        
        // 1. PRIMERO: Actualizamos la Base de Datos
        $conn->query("UPDATE abonados SET estado = 'activo' WHERE user_mikrotik = '$u'");
        
        // 2. SEGUNDO: Llamamos a Python (Ahora leerá 'activo' de la DB)
        shell_exec("\"$python\" \"$script\" \"$u\" \"sync\"");
        
        echo "    RESULTADO: Perfil actualizado a plan real y cliente reactivado.\n";
        $cambios++;
    }
}

// Resumen del ciclo
if ($cambios == 0) {
    echo "[-] STATUS: Todo en orden. No hubo cambios.\n";
} else {
    echo "[OK] STATUS: Se aplicaron $cambios correcciones con éxito.\n";
}

echo "----------------------------------------------\n\n";
$conn->close();
?>