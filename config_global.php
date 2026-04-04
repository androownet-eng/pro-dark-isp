<?php
// --- 1. GESTIÓN DE SESIONES (EL MOTOR) ---
// Esto debe ir ANTES de cualquier otra cosa. 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- 2. RUTAS DEL SISTEMA ---
$python_path = "C:/Users/Androow/AppData/Local/Programs/Python/Python37/python.exe";
$base_dir    = "C:/xampp/htdocs/gestion/";

// --- 3. CONEXIÓN A BASE DE DATOS ---
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "isp_gestion";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Verificación de conexión
if ($conn->connect_error) {
    die("Error crítico de conexión: " . $conn->connect_error);
}

// Opcional: Configurar charset para evitar problemas con tildes o eñes
$conn->set_charset("utf8mb4");

// --- 4. CONFIGURACIÓN DEL ROUTER MIKROTIK (CENTRALIZADO) ---
$mikrotik_ip = "192.168.60.1"; 
?>