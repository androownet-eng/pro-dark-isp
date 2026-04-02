<?php
error_reporting(0);
session_start();
$conn = new mysqli("localhost", "root", "", "isp_gestion");

$usuario_guardado = isset($_COOKIE['rec_user']) ? $_COOKIE['rec_user'] : "";
$pass_guardada = isset($_COOKIE['rec_pass']) ? $_COOKIE['rec_pass'] : "";

if (isset($_SESSION['admin_auth'])) {
    header("Location: index.php");
    exit();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user = $conn->real_escape_string($_POST['usuario']);
    $pass = $conn->real_escape_string($_POST['password']);
    $sql = "SELECT * FROM usuarios_admin WHERE usuario = '$user' AND password = '$pass'";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $_SESSION['admin_auth'] = $user;
        if (isset($_POST['recordar'])) {
            setcookie("rec_user", $user, time() + (86400 * 30), "/");
            setcookie("rec_pass", $pass, time() + (86400 * 30), "/");
        } else {
            setcookie("rec_user", "", time() - 3600, "/");
            setcookie("rec_pass", "", time() - 3600, "/");
        }
        header("Location: index.php");
        exit();
    } else {
        $error = "Acceso denegado. Verifique sus credenciales.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISP Control | Premium Access</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --accent: #3b82f6;
            --accent-glow: rgba(59, 130, 246, 0.5);
            --bg-dark: #020617;
            --glass: rgba(30, 41, 59, 0.7);
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: radial-gradient(circle at top right, #1e1b4b, #020617);
            height: 100vh; margin: 0; display: flex; align-items: center; justify-content: center;
            overflow: hidden; color: #f8fafc;
        }

        /* Efecto de Luces de Fondo */
        body::before {
            content: ""; position: absolute; width: 300px; height: 300px;
            background: var(--accent); filter: blur(120px); border-radius: 50%;
            top: 10%; left: 10%; opacity: 0.15; z-index: -1;
        }

        .login-card {
            background: var(--glass);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 45px 40px;
            border-radius: 32px;
            width: 90%; max-width: 400px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .brand-logo {
            width: 70px; height: 70px; background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            margin: 0 auto 20px; border-radius: 20px; display: flex;
            align-items: center; justify-content: center; font-size: 2rem;
            box-shadow: 0 10px 20px var(--accent-glow); rotate: -5deg;
        }

        h2 { font-size: 1.8rem; font-weight: 800; margin: 0; letter-spacing: -0.025em; }
        p { color: #94a3b8; margin-top: 8px; margin-bottom: 35px; font-size: 0.95rem; }

        .input-box { position: relative; margin-bottom: 25px; }
        .input-box label { display: block; font-size: 0.8rem; font-weight: 600; color: #64748b; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.05em; }
        
        .input-box input {
            width: 100%; padding: 14px 15px 14px 45px;
            background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px; color: white; font-size: 1rem; box-sizing: border-box;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .input-box i.icon-left { position: absolute; left: 16px; bottom: 15px; color: #475569; font-size: 1.1rem; }
        
        .input-box input:focus {
            outline: none; border-color: var(--accent);
            background: rgba(15, 23, 42, 0.8);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15);
        }

        .btn-submit {
            width: 100%; padding: 16px; background: var(--accent);
            color: white; border: none; border-radius: 16px;
            font-size: 1rem; font-weight: 700; cursor: pointer;
            transition: all 0.3s; margin-top: 10px;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .btn-submit:hover { background: #2563eb; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(59, 130, 246, 0.4); }

        .error-toast {
            background: rgba(239, 68, 68, 0.15); color: #fca5a5;
            padding: 12px; border-radius: 12px; font-size: 0.85rem;
            border-left: 4px solid #ef4444; margin-bottom: 20px; text-align: left;
        }

        .toggle-eye { position: absolute; right: 16px; bottom: 15px; color: #475569; cursor: pointer; }

        .checkbox-container {
            display: flex; align-items: center; gap: 10px; margin-bottom: 25px;
            color: #94a3b8; font-size: 0.9rem; cursor: pointer;
        }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="brand-logo">
            <i class="fas fa-bolt"></i>
        </div>
        <h2>Control Panel</h2>
        <p>Inicie sesión en el ecosistema ISP</p>

        <?php if($error): ?>
            <div class="error-toast"><i class="fas fa-shield-alt"></i> &nbsp; <?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="input-box">
                <label>Usuario</label>
                <i class="fas fa-at icon-left"></i>
                <input type="text" name="usuario" value="<?php echo $usuario_guardado; ?>" placeholder="Su ID de acceso" required autofocus>
            </div>

            <div class="input-box">
                <label>Contraseña</label>
                <i class="fas fa-fingerprint icon-left"></i>
                <input type="password" name="password" id="passInput" value="<?php echo $pass_guardada; ?>" placeholder="••••••••" required>
                <i class="fas fa-eye toggle-eye" id="eyeIcon" onclick="togglePassword()"></i>
            </div>

            <label class="checkbox-container">
                <input type="checkbox" name="recordar" <?php echo $usuario_guardado ? "checked" : ""; ?>>
                Confío en este dispositivo
            </label>

            <button type="submit" class="btn-submit">DESBLOQUEAR ACCESO</button>
        </form>
    </div>

    <script>
        function togglePassword() {
            const input = document.getElementById('passInput');
            const icon = document.getElementById('eyeIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }
    </script>
</body>
</html>