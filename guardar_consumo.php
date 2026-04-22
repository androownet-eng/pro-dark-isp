<?php
require_once "config_global.php";

// 1. Recibir el paquete JSON crudo
$json_entrante = file_get_contents('php://input');
$data = json_decode($json_entrante, true);

// 2. Verificar si logró entender el JSON
if (isset($data['user']) && isset($data['down']) && isset($data['up'])) {
    
    $u = $conn->real_escape_string($data['user']);
    $down = (int)$data['down']; 
    $up = (int)$data['up'];     

    $sql = "UPDATE abonados SET 
            descarga_total = descarga_total + $down, 
            subida_total = subida_total + $up,
            ultima_conexion = NOW() 
            WHERE user_mikrotik = '$u'";

    if ($conn->query($sql)) {
        echo "OK";
    } else {
        // Si falla la base de datos, lo anotamos
        file_put_contents("error_db.txt", "Error SQL: " . $conn->error . "\n", FILE_APPEND);
        http_response_code(500);
    }
} else {
    // Si falta algo, anotamos exactamente qué mandó MikroTik para investigar
    file_put_contents("error_mikrotik.txt", "Llegó esto: " . $json_entrante . "\n", FILE_APPEND);
    http_response_code(400);
    echo "Faltan parámetros";
}
?>