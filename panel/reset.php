<?php
// --- 1. CONEXIÓN CENTRALIZADA ---
require_once "config_global.php"; 

/**
 * ⚠️ ADVERTENCIA DE SEGURIDAD ⚠️
 * Este script es una LLAVE MAESTRA. 
 * Una vez ejecutado, DEBES ELIMINARLO de /gestion/ inmediatamente.
 */

// --- 2. LÓGICA DE RESETEO (CORREGIDA) ---
// Usamos el nombre de tabla que definimos en el login: 'usuarios_admin'
$tabla = "usuarios_admin";

if ($conn->query("TRUNCATE TABLE $tabla")) {

    $user = 'admin';
    $pass = '23763941'; // Tu clave Pro-Dark definida

    // Blindamos las variables por si acaso decides cambiar el user a algo con comillas
    $u_clean = $conn->real_escape_string($user);
    $p_clean = $conn->real_escape_string($pass);

    $sql = "INSERT INTO $tabla (usuario, password) VALUES ('$u_clean', '$p_clean')";

    if ($conn->query($sql)) {
        echo "<body style='background:#020617; color:#f8fafc; font-family:sans-serif; padding:40px; text-align:center;'>";
        echo "<div style='background:#0f172a; padding:30px; border-radius:20px; border:1px solid #3b82f6; display:inline-block;'>";
        echo "<h2 style='color:#3b82f6;'>🛡️ PRO-DARK SYSTEM RESET</h2>";
        echo "<p>✅ Tabla <b>$tabla</b> restaurada correctamente.</p>";
        echo "<hr style='border:0; border-top:1px solid #1e293b; margin:20px 0;'>";
        echo "👤 Usuario: <b style='color:#10b981;'>$user</b><br>";
        echo "🔑 Password: <b style='color:#10b981;'>$pass</b><br><br>";
        echo "<p style='background:#ef4444; color:white; padding:10px; border-radius:8px; font-size:0.8rem;'>";
        echo "<b>¡PELIGRO!:</b> Elimina el archivo <b>reset.php</b> de tu carpeta ahora mismo.";
        echo "</p>";
        echo "</div></body>";
    } else {
        echo "❌ Error al insertar: " . $conn->error;
    }
} else {
    echo "❌ Error al limpiar tabla: " . $conn->error;
}

$conn->close();
?>