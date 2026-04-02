<?php
$conn = new mysqli("localhost", "root", "", "isp_gestion");

// Borramos lo que haya y creamos el usuario limpio
$conn->query("DELETE FROM usuarios");
$sql = "INSERT INTO usuarios (usuario, password) VALUES ('admin', '23763941')";

if ($conn->query($sql)) {
    echo "✅ Usuario reseteado: <br> User: admin <br> Pass: 23763941";
} else {
    echo "❌ Error: " . $conn->error;
}
?>