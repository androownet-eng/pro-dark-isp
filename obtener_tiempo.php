<?php
// --- 1. INTEGRACIÓN CON EL SISTEMA ---
// Aunque sea un script de una sola función, es buena práctica tenerlo bajo el ala global
// por si luego quieres registrar quién consulta la hora o por seguridad de sesión.
require_once "config_global.php"; 

header('Content-Type: application/json');

// --- 2. PRECISIÓN PRO-DARK ---
// Aseguramos que la zona horaria sea la de tu operación en Venezuela
date_default_timezone_set('America/Caracas'); 

// Enviamos el tiempo en milisegundos (para JavaScript) y la fecha formateada
echo json_encode([
    'timestamp' => time() * 1000,
    'fecha'     => date("d / m / Y"),
    'hora'      => date("h:i:s A"), // Añadimos la hora por si la necesitas en el Dashboard
    'dia_num'   => (int)date('j')    // Útil para comparar con el 'dia_pago' de los abonados
]);
?>