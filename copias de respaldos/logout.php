<?php
session_start(); // Carga la sesión actual
session_unset(); // Limpia todas las variables
session_destroy(); // Destruye la sesión por completo

// Redirige al login para que nadie pueda volver a entrar sin clave
header("Location: login.php");
exit();
?>