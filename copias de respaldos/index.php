<?php
// --- 1. SEGURIDAD: EL CANDADO ---
session_start();
if (!isset($_SESSION['admin_auth'])) {
    header("Location: login.php");
    exit();
}

// --- 2. CONFIGURACIÓN ---
$conn = new mysqli("localhost", "root", "", "isp_gestion");
$python = "C:/Users/Androow/AppData/Local/Programs/Python/Python37/python.exe";
$dir = "C:/xampp/htdocs/gestion/";

// --- 3. VERIFICACIÓN DE CONEXIÓN MIKROTIK (LED REAL) ---
$mikrotik_ip = "192.168.60.1"; 
$timeout = 1; 
$fp = @fsockopen($mikrotik_ip, 8728, $errno, $errstr, $timeout);
if ($fp) {
    $status_color = "var(--success)";
    $status_text = "Online";
    fclose($fp);
} else {
    $status_color = "var(--danger)";
    $status_text = "Offline";
}

// --- 4. LÓGICA DE PROCESAMIENTO DE PAGO (CEREBRO ÚNICO) ---
if (isset($_POST['btn_modal_pago'])) {
    $user   = $conn->real_escape_string($_POST['user_pago']);
    $monto  = (float)$_POST['monto'];
    $metodo = $conn->real_escape_string($_POST['metodo']);
    
    // 1. Buscamos el día de pago del cliente
    $res_u = $conn->query("SELECT dia_pago FROM abonados WHERE user_mikrotik = '$user'");
    $data_u = $res_u->fetch_assoc();
    $dia_p_cliente = (int)($data_u['dia_pago'] ?? 1);

    // 2. Cálculo de vencimiento: Próximo mes, mismo día.
    $dia_f = str_pad($dia_p_cliente, 2, "0", STR_PAD_LEFT);
    $fecha_vencimiento = date('Y-m-', strtotime('+1 month')) . $dia_f;
    
    // 3. Registrar pago en historial
    $sql_pago = "INSERT INTO pagos (user_mikrotik, monto, metodo, fecha_pago) VALUES ('$user', $monto, '$metodo', NOW())";
    
    if ($conn->query($sql_pago)) {
        // ACTUALIZACIÓN DE BASE DE DATOS PRIMERO
        // Activamos estado y escudo automático (proteccion=1)
        $conn->query("UPDATE abonados SET 
                        estado = 'activo', 
                        pagado_hasta = '$fecha_vencimiento', 
                        proteccion = 1 
                      WHERE user_mikrotik = '$user'");
        
        // LA ORDEN ÚNICA AL MIKROTIK
        // El script de Python leerá que ahora está 'activo' en la DB y pondrá el plan correcto.
        shell_exec("\"$python\" \"{$dir}estado_cliente.py\" \"$user\" \"sync\" 2>&1");
        
        header("Location: index.php?status=ok");
        exit();
    }
}

// --- 5. CONSULTAS PARA ESTADÍSTICAS ---
$total_clientes = $conn->query("SELECT count(*) as total FROM abonados")->fetch_assoc()['total'] ?? 0;
$total_activos = $conn->query("SELECT count(*) as total FROM abonados WHERE estado = 'activo'")->fetch_assoc()['total'] ?? 0;
$total_corte = $conn->query("SELECT count(*) as total FROM abonados WHERE estado = 'cortado'")->fetch_assoc()['total'] ?? 0;

// CONSULTA DE LISTA (Incluyendo lógica de escudos para la vista)
$clientes_list = $conn->query("SELECT * FROM abonados ORDER BY user_mikrotik ASC LIMIT 10");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISP Panel - Gestión</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-body: #0f172a; --bg-card: #1e293b; --primary: #3b82f6;
            --success: #10b981; --danger: #ef4444; --text-main: #f8fafc; --text-dim: #94a3b8;
            --shield-auto: #00bcd4; --shield-manual: #FFD700;
        }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: var(--bg-body); color: var(--text-main); margin: 0; padding: 20px; }
        
        /* HEADER */
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .header-right { text-align: right; }
        .status-badge { font-size: 0.75rem; font-weight: bold; margin-bottom: 5px; display: block; }
        
        /* RELOJ NTP */
        .ntp-clock { background: var(--bg-card); padding: 15px; border-radius: 12px; text-align: center; margin-bottom: 25px; border: 1px solid var(--primary); }
        .ntp-time { font-size: 2.5rem; font-weight: bold; color: var(--primary); font-family: monospace; display: block; }
        .ntp-date { font-size: 0.8rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 1px; }
        .blink { animation: blinker 1s linear infinite; }
        @keyframes blinker { 50% { opacity: 0; } }

        /* ESTADISTICAS */
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 25px; }
        .stat-card { background: var(--bg-card); padding: 15px; border-radius: 12px; text-align: center; border: 1px solid #334155; }
        .stat-card i { font-size: 1.2rem; margin-bottom: 8px; display: block; }
        .stat-card .value { font-size: 1.4rem; font-weight: bold; display: block; }
        
        /* BOTONES */
        .menu-btns { display: flex; flex-direction: column; gap: 12px; margin-bottom: 30px; }
        .btn-action { display: flex; align-items: center; justify-content: center; gap: 10px; padding: 15px; border-radius: 10px; text-decoration: none; color: white; font-weight: 600; border: none; cursor: pointer; font-size: 1rem; width: 100%; box-sizing: border-box; }
        .bg-blue { background-color: var(--primary); }
        .bg-gray { background-color: #334155; }

        /* LISTA CLIENTES */
        .client-section { background: var(--bg-card); border-radius: 15px; padding: 20px; border: 1px solid #334155; }
        .client-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid #334155; }
        .badge { background: rgba(16, 185, 129, 0.1); color: var(--success); padding: 4px 10px; border-radius: 6px; font-size: 0.7rem; font-weight: bold; }
        .badge.corte { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        
        /* ESCUDOS DINÁMICOS */
        .escudo { font-size: 0.8rem; margin-left: 6px; }
        .escudo-auto { color: var(--shield-auto); }
        .escudo-manual { color: var(--shield-manual); filter: drop-shadow(0 0 3px var(--shield-manual)); }

        /* MODAL */
        #modalPago { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:1000; align-items:center; justify-content:center; }
        .modal-content { background: var(--bg-card); padding: 25px; border-radius: 15px; width: 90%; max-width: 400px; border: 1px solid var(--primary); }
        select, input { width: 100%; padding: 12px; margin: 10px 0; background: #0f172a; color: white; border: 1px solid #334155; border-radius: 8px; box-sizing: border-box; font-size: 1rem; }
    </style>
</head>
<body>

    <div class="header">
        <h1 style="font-size:1.4rem; margin:0;"><i class="fas fa-broadcast-tower" style="color:var(--primary)"></i> ISP Panel</h1>
        <div class="header-right">
            <span class="status-badge" style="color: <?php echo $status_color; ?>;">
                <i class="fas fa-circle"></i> MikroTik: <?php echo $status_text; ?>
            </span>
            <span style="font-size: 0.8rem; color: var(--text-dim);">
                Admin: <b><?php echo $_SESSION['admin_auth']; ?></b>
                <a href="logout.php" style="color: var(--danger); margin-left: 10px; text-decoration: none;"><i class="fas fa-power-off"></i></a>
            </span>
        </div>
    </div>

    <div class="ntp-clock">
        <div id="ntp-fecha" class="ntp-date">Sincronizando...</div>
        <div class="ntp-time">
            <span id="ntp-h">00</span><span class="blink">:</span><span id="ntp-m">00</span><span class="blink">:</span><span id="ntp-s">00</span>
        </div>
        <div style="font-size: 0.6rem; color: var(--success);"><i class="fas fa-sync-alt"></i> NTP SYNCHRONIZED</div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <i class="fas fa-users" style="color: var(--primary)"></i>
            <span class="value"><?php echo $total_clientes; ?></span>
            <div style="font-size:0.6rem; color:var(--text-dim); text-transform:uppercase;">Total</div>
        </div>
        <div class="stat-card">
            <i class="fas fa-check-circle" style="color: var(--success)"></i>
            <span class="value"><?php echo $total_activos; ?></span>
            <div style="font-size:0.6rem; color:var(--text-dim); text-transform:uppercase;">Activos</div>
        </div>
        <div class="stat-card">
            <i class="fas fa-times-circle" style="color: var(--danger)"></i>
            <span class="value"><?php echo $total_corte; ?></span>
            <div style="font-size:0.6rem; color:var(--text-dim); text-transform:uppercase;">Corte</div>
        </div>
    </div>

    <div class="menu-btns">
        <a href="config.php" class="btn-action bg-blue">
            <i class="fas fa-user-cog"></i> Gestionar Abonados
        </a>
        <button onclick="abrirModal()" class="btn-action bg-gray">
            <i class="fas fa-wallet"></i> Registrar Pago Rápido
        </button>
        <a href="reportes.php" class="btn-action bg-gray">
            <i class="fas fa-chart-line"></i> Reportes y Cobros
        </a>
    </div>

    <div class="client-section">
        <div style="color:var(--text-dim); font-size:0.9rem; margin-bottom:15px;"><i class="fas fa-user-clock"></i> Últimos Movimientos</div>
        <?php while($c = $clientes_list->fetch_assoc()): 
            // Lógica de Escudos para la vista
            $es_manual = ($c['proteccion_manual'] == 1);
            $es_auto = ($c['proteccion'] == 1 && !$es_manual);
        ?>
        <div class="client-item">
            <div>
                <b style="display:flex; align-items:center; font-size:0.9rem;">
                    <?php echo $c['user_mikrotik']; ?>
                    <?php if($es_manual): ?>
                        <i class="fas fa-shield-alt escudo escudo-manual" title="Protección Manual"></i>
                    <?php elseif($es_auto): ?>
                        <i class="fas fa-shield-alt escudo escudo-auto" title="Protección Automática (Pago)"></i>
                    <?php endif; ?>
                </b>
                <small style="color:var(--text-dim);">Día de Pago: <?php echo $c['dia_pago']; ?></small>
            </div>
            <div class="badge <?php echo ($c['estado'] != 'activo') ? 'corte' : ''; ?>">
                <?php echo strtoupper($c['estado']); ?>
            </div>
        </div>
        <?php endwhile; ?>
    </div>

    <div id="modalPago" onclick="cerrarModalExterno(event)">
        <div class="modal-content" onclick="event.stopPropagation()">
            <h3 style="margin-top:0; color:var(--primary);"><i class="fas fa-money-bill-wave"></i> Registrar Pago</h3>
            <form method="POST">
                <label style="font-size:0.8rem; color:var(--text-dim);">Cliente:</label>
                <select name="user_pago" required>
                    <option value="">-- Seleccionar --</option>
                    <?php 
                    $opciones = $conn->query("SELECT user_mikrotik FROM abonados ORDER BY user_mikrotik ASC");
                    while($o = $opciones->fetch_assoc()) echo "<option value='{$o['user_mikrotik']}'>{$o['user_mikrotik']}</option>";
                    ?>
                </select>
                
                <input type="number" name="monto" step="0.01" placeholder="Monto ($)" required>

                <select name="metodo" required>
                    <option value="Efectivo">💵 Efectivo</option>
                    <option value="Transferencia">🏦 Transferencia</option>
                    <option value="Pago Movil">📱 Pago Móvil</option>
                    <option value="Zelle">💳 Zelle</option>
                </select>

                <button type="submit" name="btn_modal_pago" class="btn-action bg-blue" style="width:100%; margin-top:10px;">
                    <i class="fas fa-check-circle"></i> GUARDAR Y PROTEJER
                </button>
                <button type="button" onclick="cerrarModal()" style="width:100%; background:none; border:none; color:var(--text-dim); margin-top:15px; cursor:pointer;">Cancelar</button>
            </form>
        </div>
    </div>

    <script>
        function abrirModal() { document.getElementById('modalPago').style.display = 'flex'; }
        function cerrarModal() { document.getElementById('modalPago').style.display = 'none'; }
        function cerrarModalExterno(e) { if(e.target.id === 'modalPago') cerrarModal(); }

        let srvTime;
        function getSrvTime() {
            fetch('obtener_tiempo.php')
                .then(r => r.json())
                .then(d => {
                    srvTime = new Date(d.timestamp);
                    document.getElementById('ntp-fecha').innerText = d.fecha;
                });
        }
        setInterval(() => {
            if(!srvTime) return;
            srvTime.setSeconds(srvTime.getSeconds() + 1);
            document.getElementById('ntp-h').innerText = String(srvTime.getHours()).padStart(2, '0');
            document.getElementById('ntp-m').innerText = String(srvTime.getMinutes()).padStart(2, '0');
            document.getElementById('ntp-s').innerText = String(srvTime.getSeconds()).padStart(2, '0');
        }, 1000);
        getSrvTime();
    </script>
</body>
</html>