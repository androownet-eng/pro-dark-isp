<?php
// --- 1. CENTRALIZACIÓN ---
// Importamos la conexión y la configuración de sesión desde un solo lugar
require_once "config_global.php"; 

// Si el administrador ya está dentro, lo mandamos al index automáticamente
if (isset($_SESSION['admin_auth'])) {
    header("Location: index.php");
    exit();
}

$error = "";

// --- 2. LÓGICA DE ACCESO ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Limpiamos los espacios en blanco de los bordes
    $user = trim($_POST['usuario']);
    $pass = trim($_POST['password']);

    if (!empty($user) && !empty($pass)) {
        // PREPARED STATEMENT: Evita que hackeen el login con Inyección SQL
        // IMPORTANTE: Usamos 'usuarios_admin' porque así se llama en tu phpMyAdmin
        $stmt = $conn->prepare("SELECT usuario, password FROM usuarios_admin WHERE usuario = ? LIMIT 1");
        
        if ($stmt) {
            $stmt->bind_param("s", $user);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                
                // Comparamos la contraseña (asumiendo que es texto plano como tu reset)
                if ($pass === $row['password']) {
                    // Login exitoso
                    session_regenerate_id(true); // Seguridad extra
                    $_SESSION['admin_auth'] = $row['usuario'];

                    // Si marcó "Recordarme", guardamos una cookie por 30 días
                    if (isset($_POST['recordar'])) {
                        setcookie("rec_user", $user, time() + (86400 * 30), "/", "", false, true);
                    }
                    
                    header("Location: index.php");
                    exit();
                } else {
                    $error = "Contraseña incorrecta.";
                }
            } else {
                $error = "El usuario no existe.";
            }
            $stmt->close();
        } else {
            // Esto solo se activará si hay un error en el nombre de la tabla o columnas
            $error = "Error en el sistema: " . $conn->error;
        }
    } else {
        $error = "Por favor, rellene todos los campos.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pro-Dark ISP | Acceso</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --accent: #3b82f6;
            --bg: #020617;
            --card: #0f172a;
            --text: #f8fafc;
        }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: radial-gradient(circle at top right, #1e1b4b, var(--bg));
            height: 100vh; margin: 0; display: flex; align-items: center; justify-content: center;
            color: var(--text);
        }
        .login-card {
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(12px);
            padding: 40px;
            border-radius: 24px;
            width: 90%; max-width: 380px;
            border: 1px solid rgba(255, 255, 255, 0.05);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            text-align: center;
        }
        .brand-icon {
            width: 60px; height: 60px; background: var(--accent);
            margin: 0 auto 20px; border-radius: 16px; display: flex;
            align-items: center; justify-content: center; font-size: 1.5rem;
            box-shadow: 0 10px 20px rgba(59, 130, 246, 0.3);
        }
        input {
            width: 100%; padding: 14px; margin-bottom: 15px;
            background: #020617; border: 1px solid #1e293b;
            border-radius: 12px; color: white; font-size: 1rem; box-sizing: border-box;
        }
        input:focus { outline: none; border-color: var(--accent); }
        .btn-login {
            width: 100%; padding: 14px; background: var(--accent);
            border: none; border-radius: 12px; color: white;
            font-weight: 700; cursor: pointer; transition: 0.3s;
        }
        .btn-login:hover { background: #2563eb; transform: translateY(-2px); }
        .error-msg {
            background: rgba(239, 68, 68, 0.1); color: #fca5a5;
            padding: 10px; border-radius: 10px; font-size: 0.85rem;
            margin-bottom: 20px; border-left: 4px solid #ef4444; text-align: left;
        }
        .remember-me {
            display: flex; align-items: center; gap: 8px;
            font-size: 0.85rem; color: #64748b; margin-bottom: 20px; cursor: pointer;
        }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="brand-icon"><i class="fas fa-shield-alt"></i></div>
        <h2 style="margin: 0 0 10px 0;">Pro-Dark ISP</h2>
        <p style="color: #64748b; margin-bottom: 30px; font-size: 0.9rem;">Gestión Administrativa</p>

        <?php if($error): ?>
            <div class="error-msg"><i class="fas fa-info-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="text" name="usuario" placeholder="Usuario" value="<?php echo htmlspecialchars($_COOKIE['rec_user'] ?? ''); ?>" required autofocus>
            <input type="password" name="password" placeholder="Contraseña" required>
            
            <label class="remember-me">
                <input type="checkbox" name="recordar" <?php echo isset($_COOKIE['rec_user']) ? 'checked' : ''; ?>>
                Recordar en este equipo
            </label>

            <button type="submit" class="btn-login">DESBLOQUEAR PANEL</button>
        </form>
    </div>

</body>
</html>