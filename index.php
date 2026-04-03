<?php
// --- 1. NÚCLEO Y SEGURIDAD ---
require_once "config_global.php"; 

// --- 2. VERIFICACIÓN DE CONEXIÓN MIKROTIK ---
// Usamos la IP de tu RB4011. Timeout corto para no trabar la carga.
$mikrotik_ip = "192.168.60.1"; 
$fp = @fsockopen($mikrotik_ip, 8728, $errno, $errstr, 1);
if ($fp) {
    $status_color = "var(--success)";
    $status_text = "Online";
    fclose($fp);
} else {
    $status_color = "var(--danger)";
    $status_text = "Offline";
}

// --- 3. LÓGICA DE PROCESAMIENTO DE PAGO (CEREBRO ÚNICO) ---
if (isset($_POST['btn_modal_pago'])) {
    $user   = $conn->real_escape_string($_POST['user_pago']);
    $monto  = (float)$_POST['monto'];
    $metodo = $conn->real_escape_string($_POST['metodo']);
    
    $res_u = $conn->query("SELECT dia_pago FROM abonados WHERE user_mikrotik = '$user'");
    $data_u = $res_u->fetch_assoc();
    $dia_p_cliente = (int)($data_u['dia_pago'] ?? 1);

    $dia_f = str_pad($dia_p_cliente, 2, "0", STR_PAD_LEFT);
    $fecha_vencimiento = date('Y-m-', strtotime('+1 month')) . $dia_f;
    
    // Transacción de pago
    $sql_pago = "INSERT INTO pagos (user_mikrotik, monto, metodo, fecha_pago) VALUES ('$user', $monto, '$metodo', NOW())";
    
    if ($conn->query($sql_pago)) {
        // Actualizamos estado y protección en DB
        $conn->query("UPDATE abonados SET estado = 'activo', pagado_hasta = '$fecha_vencimiento', proteccion = 1 WHERE user_mikrotik = '$user'");
        
        // EJECUCIÓN CENTRALIZADA: Usamos las rutas de config_global.php
        $cmd = "start /B \"\" \"$python_path\" \"{$base_dir}estado_cliente.py\" \"$user\" \"sync\"";
        pclose(popen($cmd, "r"));
        
        header("Location: index.php?status=ok");
        exit();
    }
}

// --- 4. CONSULTAS PARA ESTADÍSTICAS (Sincronizadas con Config) ---
$stats = $conn->query("SELECT 
    COUNT(*) as total, 
    SUM(CASE WHEN estado = 'activo' OR (prorroga_hasta IS NOT NULL AND prorroga_hasta > NOW()) THEN 1 ELSE 0 END) as activos,
    SUM(CASE WHEN estado = 'cortado' AND (prorroga_hasta IS NULL OR prorroga_hasta <= NOW()) THEN 1 ELSE 0 END) as cortes
    FROM abonados")->fetch_assoc();

$clientes_list = $conn->query("SELECT * FROM abonados ORDER BY id DESC LIMIT 10");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pro-Dark ISP | Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-body: #020617; --bg-card: #0f172a; --primary: #3b82f6;
            --success: #10b981; --danger: #ef4444; --text-main: #f8fafc; --text-dim: #64748b;
            --shield-auto: #00bcd4; --shield-manual: #fbbf24; --border: rgba(255,255,255,0.05);
        }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; background-color: var(--bg-body); color: var(--text-main); margin: 0; padding: 20px; line-height: 1.5; }
        
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .logo-box { display: flex; align-items: center; gap: 10px; }
        .logo-box i { font-size: 1.5rem; color: var(--primary); }
        .logo-box h1 { font-size: 1.2rem; margin: 0; font-weight: 800; letter-spacing: -0.5px; }
        
        .status-pill { background: rgba(0,0,0,0.2); padding: 5px 12px; border-radius: 20px; border: 1px solid var(--border); font-size: 0.7rem; font-weight: 700; display: flex; align-items: center; gap: 6px; }

        .ntp-clock { background: linear-gradient(145deg, #1e293b, #0f172a); padding: 25px; border-radius: 24px; text-align: center; margin-bottom: 25px; border: 1px solid var(--border); box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .ntp-time { font-size: 3rem; font-weight: 900; color: var(--primary); font-variant-numeric: tabular-nums; }
        .ntp-date { font-size: 0.75rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 2px; }

        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 25px; }
        .stat-card { background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(10px); padding: 15px; border-radius: 20px; text-align: center; border: 1px solid rgba(255, 255, 255, 0.03); transition: transform 0.3s ease, border-color 0.3s ease; }
        .stat-card:hover { transform: translateY(-5px); border-color: var(--primary); }
        .stat-card .value { font-size: 1.4rem; font-weight: 800; display: block; }
        .stat-card label { font-size: 0.6rem; color: var(--text-dim); text-transform: uppercase; font-weight: 700; }
        
        .menu-btns { display: grid; grid-template-columns: 1fr; gap: 10px; margin-bottom: 30px; }
        .btn-action { display: flex; align-items: center; justify-content: center; gap: 10px; padding: 16px; border-radius: 16px; text-decoration: none; color: white; font-weight: 700; border: none; cursor: pointer; transition: 0.2s; }
        .btn-action:active { transform: scale(0.98); }
        .bg-blue { background: linear-gradient(135deg, #3b82f6, #2563eb); box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4); }
        .bg-gray { background: #1e293b; border: 1px solid var(--border); }

        .client-section { background: var(--bg-card); border-radius: 24px; padding: 20px; border: 1px solid var(--border); }
        .list-header { font-size: 0.75rem; font-weight: 800; color: var(--text-dim); margin-bottom: 15px; display: flex; align-items: center; gap: 8px; text-transform: uppercase; }
        .client-item { display: flex; justify-content: space-between; align-items: center; padding: 14px 0; border-bottom: 1px solid var(--border); }
        .client-item:last-child { border-bottom: none; }
        
        .badge { font-size: 0.65rem; font-weight: 800; padding: 4px 10px; border-radius: 8px; text-transform: uppercase; }
        .badge.activo { background: rgba(16, 185, 129, 0.15); color: var(--success); }
        .badge.corte { background: rgba(239, 68, 68, 0.15); color: var(--danger); }
        
        .escudo { font-size: 0.8rem; margin-left: 6px; }
        .escudo-auto { color: var(--shield-auto); }
        .escudo-manual { color: var(--shield-manual); filter: drop-shadow(0 0 5px rgba(251, 191, 36, 0.3)); }
        .escudo-prorroga { color: var(--danger); font-size: 0.7rem; margin-left: 5px; animation: pulse 1.5s infinite; }

        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }

        #modalPago { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.8); backdrop-filter: blur(8px); z-index:1000; align-items:center; justify-content:center; }
        .modal-content { background: #1e293b; padding: 30px; border-radius: 28px; width: 90%; max-width: 380px; border: 1px solid var(--primary); }
        select, input { width: 100%; padding: 14px; margin: 10px 0; background: #020617; color: white; border: 1px solid #334155; border-radius: 12px; font-size: 1rem; }
        .blink { animation: blinker 1s linear infinite; }
        @keyframes blinker { 50% { opacity: 0; } }
    </style>
</head>
<body>

    <div class="header">
        <div class="logo-box">
            <i class="fas fa-satellite-dish"></i>
            <h1>PRO-DARK ISP</h1>
        </div>
        <div class="status-pill" style="color: <?php echo $status_color; ?>;">
            <i class="fas fa-circle <?php echo ($status_text=='Online'?'':'blink'); ?>"></i> <?php echo $status_text; ?>
        </div>
    </div>

    <div class="ntp-clock">
        <div id="ntp-fecha" class="ntp-date">-- / -- / ----</div>
        <div class="ntp-time">
            <span id="ntp-h">00</span><span class="blink">:</span><span id="ntp-m">00</span>
        </div>
        <div style="font-size: 0.6rem; color: var(--success); font-weight: 800; letter-spacing: 1px;">
            <i class="fas fa-sync-alt"></i> SISTEMA SINCRONIZADO
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <label>Abonados</label>
            <span class="value"><?php echo $stats['total'] ?? 0; ?></span>
        </div>
        <div class="stat-card" style="color: var(--success);">
            <label>Activos</label>
            <span class="value"><?php echo $stats['activos'] ?? 0; ?></span>
        </div>
        <div class="stat-card" style="color: var(--danger);">
            <label>Cortes</label>
            <span class="value"><?php echo $stats['cortes'] ?? 0; ?></span>
        </div>
    </div>

    <div class="menu-btns">
        <button onclick="abrirModal()" class="btn-action bg-blue">
            <i class="fas fa-plus-circle"></i> REGISTRAR PAGO RÁPIDO
        </button>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <a href="config.php" class="btn-action bg-gray"><i class="fas fa-users-cog"></i> Panel</a>
            <a href="reportes.php" class="btn-action bg-gray"><i class="fas fa-wallet"></i> Caja</a>
        </div>
    </div>

    <div class="client-section">
        <div class="list-header"><i class="fas fa-history"></i> Últimos Registros</div>
        <?php while($c = $clientes_list->fetch_assoc()): 
            $es_manual = ($c['proteccion_manual'] == 1);
            $es_auto = ($c['proteccion'] == 1 && !$es_manual);
            // Sincronización de lógica de Prórroga
            $tiene_prorroga = (!empty($c['prorroga_hasta']) && strtotime($c['prorroga_hasta']) > time());
            $esta_realmente_activo = ($c['estado'] == 'activo' || $tiene_prorroga);
        ?>
        <div class="client-item">
            <div>
                <b style="display:flex; align-items:center; font-size:0.9rem;">
                    <?php echo $c['user_mikrotik']; ?>
                    <?php if($es_manual): ?>
                        <i class="fas fa-shield-virus escudo escudo-manual" title="Protección Manual"></i>
                    <?php elseif($es_auto): ?>
                        <i class="fas fa-shield-alt escudo escudo-auto" title="Protegido por Pago"></i>
                    <?php endif; ?>
                    <?php if($tiene_prorroga): ?>
                        <i class="fas fa-clock-rotate-left escudo-prorroga" title="Prórroga Activa"></i>
                    <?php endif; ?>
                </b>
                <small style="color:var(--text-dim); font-size:0.7rem;">Corte: Día <?php echo $c['dia_pago']; ?> | <?php echo str_replace('PPPoeClientes-', '', $c['plan']); ?></small>
            </div>
            <div class="badge <?php echo ($esta_realmente_activo) ? 'activo' : 'corte'; ?>">
                <?php echo ($esta_realmente_activo) ? 'Activo' : 'Corte'; ?>
            </div>
        </div>
        <?php endwhile; ?>
    </div>

    <div id="modalPago" onclick="cerrarModalExterno(event)">
        <div class="modal-content" onclick="event.stopPropagation()">
            <h3 style="margin-top:0; color:var(--primary); font-weight:800;"><i class="fas fa-money-bill-wave"></i> Nuevo Pago</h3>
            <form method="POST">
                <select name="user_pago" required>
                    <option value="">Seleccionar Abonado...</option>
                    <?php 
                    $opciones = $conn->query("SELECT user_mikrotik FROM abonados ORDER BY user_mikrotik ASC");
                    while($o = $opciones->fetch_assoc()) echo "<option value='{$o['user_mikrotik']}'>{$o['user_mikrotik']}</option>";
                    ?>
                </select>
                <input type="number" name="monto" step="0.01" placeholder="Monto en $" required>
                <select name="metodo" required>
                    <option value="Pago Movil">📱 Pago Móvil</option>
                    <option value="Efectivo">💵 Efectivo</option>
                    <option value="Zelle">💳 Zelle</option>
                </select>

                <button type="submit" name="btn_modal_pago" class="btn-action bg-blue" style="width:100%; margin-top:10px;">
                    INMUNIZAR Y GUARDAR
                </button>
                <button type="button" onclick="cerrarModal()" style="width:100%; background:none; border:none; color:var(--text-dim); margin-top:15px; cursor:pointer; font-weight:700;">CANCELAR</button>
            </form>
        </div>
    </div>

    <script>
        function abrirModal() { document.getElementById('modalPago').style.display = 'flex'; }
        function cerrarModal() { document.getElementById('modalPago').style.display = 'none'; }
        function cerrarModalExterno(e) { if(e.target.id === 'modalPago') cerrarModal(); }

        function updateClock() {
            const now = new Date();
            document.getElementById('ntp-h').innerText = String(now.getHours()).padStart(2, '0');
            document.getElementById('ntp-m').innerText = String(now.getMinutes()).padStart(2, '0');
            document.getElementById('ntp-fecha').innerText = now.toLocaleDateString('es-ES', { weekday: 'long', day: 'numeric', month: 'long' });
        }
        setInterval(updateClock, 1000);
        updateClock();
    </script>
</body>
</html>