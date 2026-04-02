<?php
// obtener_tiempo.php
header('Content-Type: application/json');
date_default_timezone_set('America/Caracas'); // Ajusta a tu zona horaria

echo json_encode([
    'timestamp' => time() * 1000,
    'fecha' => date("d / m / Y")
]);
?>