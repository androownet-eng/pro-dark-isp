<?php
// --- 1. CONEXIÓN CENTRALIZADA ---
require_once "config_global.php"; 

/**
 * ⚠️ ADVERTENCIA DE SEGURIDAD ⚠️
 * Este script resetea la cuenta de administrador. 
 * Una vez que lo uses, DEBES BORRARLO del servidor o renombrarlo.
 */

// --- 2. LÓGICA DE RESETEO ---
// Usamos TRUNCATE para limpiar la tabla y resetear los IDs a 1
if ($conn->query("TRUNCATE TABLE usuarios")) {
    
    // Insertamos el usuario con la contraseña que definiste
    // Sugerencia: En el futuro, usa password_hash() para mayor seguridad
    $user = 'admin';
    $pass = '23763941';
    
    $sql = "INSERT INTO usuarios (usuario, password) VALUES ('$user', '$pass')";

    if ($conn->query($sql)) {
        echo "==============================================<br>";
        echo "   SISTEMA PRO-DARK ISP - RESET DE ACCESO     <br>";
        echo "==============================================<br>";
        echo "✅ USUARIO RESTAURADO CON ÉXITO:<br>";
        echo "👤 Usuario: <b>$user</b><br>";
        echo "🔑 Password: <b>$pass</b><br>";
        echo "----------------------------------------------<br>";
        echo "🚀 <b>IMPORTANTE:</b> Borra este archivo por seguridad.<br>";
    } else {
        echo "❌ Error al insertar: " . $conn->error;
    }
} else {
    echo "❌ Error al limpiar tabla: " . $conn->error;
}

$conn->close();
?>