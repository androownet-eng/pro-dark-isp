<?php
// --- 1. NÚCLEO Y SEGURIDAD ---
require_once "config_global.php"; 

if (!isset($_SESSION['admin_auth'])) {
    header("Location: login.php");
    exit();
}

// --- 2. VERIFICACIÓN DE CONEXIÓN MIKROTIK Y CONTEO EN LÍNEA ---
$fp = @fsockopen($mikrotik_ip, 8728, $errno, $errstr, 1);
$conectados_reales = 0; 
$ips_online = [];

if ($fp) {
    $status_color = "var(--success)";
    $status_text = "Online";
    fclose($fp);

    // Obtenemos el JSON con los usuarios conectados reales desde Python
    $ips_json = shell_exec("\"$python_path\" \"{$base_dir}obtener_ips.py\" 2>&1");
    $ips_online = json_decode($ips_json, true) ?: [];

    // Cruzamos la data. Solo sumamos +1 si el usuario conectado existe en nuestra tabla 'abonados'
    $res_db = $conn->query("SELECT user_mikrotik FROM abonados");
    while ($row = $res_db->fetch_assoc()) {
        if (isset($ips_online[$row['user_mikrotik']])) {
            $conectados_reales++;
        }
    }

} else {
    $status_color = "var(--danger)";
    $status_text = "Offline";
}

// --- 3. LÓGICA DE PROCESAMIENTO DE PAGO ---
if (isset($_POST['btn_modal_pago'])) {
    $user   = $conn->real_escape_string($_POST['user_pago']);
    $monto  = (float)$_POST['monto'];
    $metodo = $conn->real_escape_string($_POST['metodo']);

    $res_u = $conn->query("SELECT dia_pago FROM abonados WHERE user_mikrotik = '$user'");
    $data_u = $res_u->fetch_assoc();
    $dia_p_cliente = (int)($data_u['dia_pago'] ?? 1);

    $dia_f = str_pad($dia_p_cliente, 2, "0", STR_PAD_LEFT);
    // Se le suma 1 mes desde hoy para su nueva fecha de vencimiento
    $fecha_vencimiento = date('Y-m-', strtotime('+1 month')) . $dia_f;

    $sql_pago = "INSERT INTO pagos (user_mikrotik, monto, metodo, fecha_pago) VALUES ('$user', $monto, '$metodo', NOW())";

    if ($conn->query($sql_pago)) {
        // Limpiamos la prórroga y activamos el escudo al pagar
        $conn->query("UPDATE abonados SET estado = 'activo', pagado_hasta = '$fecha_vencimiento', proteccion = 1, prorroga_hasta = NULL WHERE user_mikrotik = '$user'");

        $u_esc = escapeshellarg($user);
        shell_exec("\"$python_path\" \"{$base_dir}estado_cliente.py\" $u_esc \"activar\" 2>&1");

        // Redirigimos pasando los datos para que salte la factura automáticamente
        header("Location: index.php?status=ok&factura_user=" . urlencode($user) . "&vence=" . urlencode($fecha_vencimiento));
        exit();
    }
}

// --- 4. CONSULTAS ESTADÍSTICAS ---
$stats = $conn->query("SELECT 
    COUNT(*) as total, 
    SUM(CASE WHEN estado = 'activo' OR (prorroga_hasta IS NOT NULL AND prorroga_hasta > NOW()) THEN 1 ELSE 0 END) as activos,
    SUM(CASE WHEN estado = 'cortado' AND (prorroga_hasta IS NULL OR prorroga_hasta <= NOW()) THEN 1 ELSE 0 END) as cortes
    FROM abonados")->fetch_assoc();

// Lista completa para poder filtrar por día
$clientes_list = $conn->query("SELECT * FROM abonados ORDER BY dia_pago ASC, user_mikrotik ASC");

// --- 5. DATOS PARA LA GRÁFICA ---
$planes_res = $conn->query("SELECT plan, COUNT(*) as cantidad FROM abonados GROUP BY plan");
$planes_labels = [];
$planes_data = [];
while($p = $planes_res->fetch_assoc()){
    $planes_labels[] = htmlspecialchars(str_replace('PPPoeClientes-', '', $p['plan']));
    $planes_data[] = $p['cantidad'];
}

// --- 6. PRÓXIMOS VENCIMIENTOS ---
$vencimientos = $conn->query("SELECT user_mikrotik, pagado_hasta, plan FROM abonados WHERE estado = 'activo' AND pagado_hasta BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY) ORDER BY pagado_hasta ASC LIMIT 5");

// --- 7. DENSIDAD DE COBROS POR DÍA ---
$res_dias = $conn->query("SELECT dia_pago, COUNT(*) as cantidad FROM abonados GROUP BY dia_pago ORDER BY dia_pago ASC");
$distribucion_dias = [];
while($row = $res_dias->fetch_assoc()){
    $distribucion_dias[] = $row;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pro-Dark ISP | Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg-body: #020617; --bg-card: #0f172a; --primary: #3b82f6;
            --success: #10b981; --danger: #ef4444; --warning: #fbbf24; 
            --text-main: #f8fafc; --text-dim: #64748b;
            --shield-auto: #00bcd4; --shield-manual: #fbbf24; --border: rgba(255,255,255,0.05);
        }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; background-color: var(--bg-body); color: var(--text-main); margin: 0; padding: 20px; line-height: 1.5; }

        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .logo-box { display: flex; align-items: center; gap: 10px; }
        .logo-box i { font-size: 1.5rem; color: var(--primary); }
        .logo-box h1 { font-size: 1.2rem; margin: 0; font-weight: 800; letter-spacing: -0.5px; }
        .status-pill { background: rgba(0,0,0,0.2); padding: 5px 12px; border-radius: 20px; border: 1px solid var(--border); font-size: 0.7rem; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 6px; }

        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }
        .stat-card { background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(10px); padding: 15px; border-radius: 20px; text-align: center; border: 1px solid rgba(255, 255, 255, 0.03); transition: 0.3s; }
        .stat-card:hover { transform: translateY(-5px); border-color: var(--primary); }
        .stat-card .value { font-size: 1.6rem; font-weight: 800; display: block; }
        .stat-card label { font-size: 0.65rem; color: var(--text-dim); text-transform: uppercase; font-weight: 700; letter-spacing: 1px; }

        .menu-btns { display: grid; grid-template-columns: 1fr; gap: 10px; margin-bottom: 20px; }
        .btn-action { display: flex; align-items: center; justify-content: center; gap: 10px; padding: 16px; border-radius: 16px; text-decoration: none; color: white; font-weight: 700; border: none; cursor: pointer; transition: 0.2s; }
        .btn-action:active { transform: scale(0.98); }
        .bg-blue { background: linear-gradient(135deg, #3b82f6, #2563eb); box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4); }
        .bg-gray { background: #1e293b; border: 1px solid var(--border); }

        .dashboard-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 25px; }
        @media (max-width: 768px) { .dashboard-grid { grid-template-columns: 1fr; } .stats-grid{ grid-template-columns: repeat(2, 1fr); } }

        .panel-box { background: var(--bg-card); border-radius: 24px; padding: 20px; border: 1px solid var(--border); }
        .panel-title { font-size: 0.75rem; font-weight: 800; color: var(--text-dim); margin-bottom: 15px; display: flex; align-items: center; gap: 8px; text-transform: uppercase; }

        .chart-container { position: relative; height: 220px; width: 100%; }

        /* Estilos del Mapa de Días */
        .mapa-dias { display: flex; gap: 12px; overflow-x: auto; padding-bottom: 10px; scrollbar-width: thin; scrollbar-color: var(--primary) var(--bg-card); }
        .dia-card { background: rgba(59, 130, 246, 0.05); border: 1px solid rgba(59, 130, 246, 0.2); padding: 12px 20px; border-radius: 16px; text-align: center; min-width: 85px; flex-shrink: 0; cursor: pointer; transition: 0.3s; }
        .dia-card:hover, .dia-card.active { border-color: var(--primary); background: rgba(59, 130, 246, 0.15); transform: translateY(-3px); }
        
        /* Lista de Clientes en Dashboard */
        .client-item { display: flex; justify-content: space-between; align-items: center; padding: 14px 0; border-bottom: 1px solid var(--border); }
        .client-item:last-child { border-bottom: none; }
        
        /* Badge de Pagos y Botones */
        .estado-pago { font-size: 0.65rem; font-weight: bold; padding: 3px 8px; border-radius: 6px; letter-spacing: 0.5px; display: inline-block; margin-bottom: 5px;}
        .pago-ok { background: rgba(16, 185, 129, 0.2); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.3); }
        .pago-deuda { background: rgba(239, 68, 68, 0.2); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.3); }
        .btn-mini { font-size: 0.65rem; padding: 5px 10px; border-radius: 6px; border: none; cursor: pointer; font-weight: bold; color: white; display: flex; align-items: center; gap: 4px; }

        /* Escudos */
        .escudo { font-size: 0.8rem; margin-left: 6px; }
        .escudo-auto { color: var(--shield-auto); }
        .escudo-manual { color: var(--shield-manual); filter: drop-shadow(0 0 5px rgba(251, 191, 36, 0.3)); }

        /* Modales */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.8); backdrop-filter: blur(8px); z-index:1000; align-items:center; justify-content:center; }
        .modal-content { background: #1e293b; padding: 30px; border-radius: 28px; width: 90%; max-width: 380px; border: 1px solid var(--primary); }
        select, input { width: 100%; padding: 14px; margin: 10px 0; background: #020617; color: white; border: 1px solid #334155; border-radius: 12px; font-size: 1rem; }
        
        /* Ticket Factura */
        .ticket { background: #fff; color: #000; padding: 25px 20px; font-family: 'Courier New', Courier, monospace; border-radius: 5px; width: 90%; max-width: 300px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .ticket h2 { text-align: center; margin: 0 0 10px 0; font-size: 1.3rem; font-weight: 900; }
        .ticket .divider { border-bottom: 2px dashed #000; margin: 15px 0; }
        .ticket-row { display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 0.85rem; font-weight: bold; }
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

        <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 6px;">
            <div class="status-pill" style="color: <?php echo $status_color; ?>; width: 100%;">
                <i class="fas fa-circle <?php echo ($status_text=='Online'?'':'blink'); ?>"></i> <?php echo $status_text; ?>
            </div>
            <div id="reloj-digital" style="font-size: 0.65rem; color: var(--text-dim); font-weight: 800; letter-spacing: 0.5px;">
                --:-- --
            </div>
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
        <div class="stat-card" style="color: #00bcd4; border-color: rgba(0, 188, 212, 0.3);">
            <label><i class="fas fa-wifi" style="margin-right: 4px;"></i>En Línea</label>
            <span class="value"><?php echo $conectados_reales; ?></span>
        </div>
    </div>

    <div class="menu-btns">
        <button onclick="abrirModalPago('')" class="btn-action bg-blue">
            <i class="fas fa-plus-circle"></i> REGISTRAR PAGO RÁPIDO
        </button>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <a href="config.php" class="btn-action bg-gray"><i class="fas fa-users-cog"></i> Panel</a>
            <a href="reportes.php" class="btn-action bg-gray"><i class="fas fa-wallet"></i> Caja</a>
        </div>
    </div>

    <div class="dashboard-grid">
        <div class="panel-box">
            <div class="panel-title"><i class="fas fa-chart-pie"></i> Distribución de Red</div>
            <div class="chart-container">
                <canvas id="planChart"></canvas>
            </div>
        </div>

        <div class="panel-box">
            <div class="panel-title"><i class="fas fa-exclamation-triangle" style="color: var(--warning)"></i> Próximos Vencimientos</div>
            <?php if ($vencimientos && $vencimientos->num_rows > 0): ?>
                <?php while($v = $vencimientos->fetch_assoc()): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px dashed var(--border); font-size: 0.85rem;">
                        <span style="font-weight: 600;"><?php echo htmlspecialchars($v['user_mikrotik']); ?></span>
                        <span style="color: var(--warning); font-weight: 700; font-size: 0.75rem; background: rgba(251, 191, 36, 0.1); padding: 3px 8px; border-radius: 6px;"><?php echo date("d M", strtotime($v['pagado_hasta'])); ?></span>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="text-align: center; color: var(--text-dim); font-size: 0.8rem; margin-top: 30px;">
                    <i class="fas fa-check-circle" style="font-size: 2rem; opacity: 0.3; margin-bottom: 10px; display: block;"></i>
                    Red estable.<br>No hay cortes próximos.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel-box" style="margin-bottom: 25px;">
        <div class="panel-title"><i class="fas fa-calendar-day" style="color: var(--primary);"></i> Mapa de Cobros (Filtro)</div>

        <div class="mapa-dias">
            <?php foreach($distribucion_dias as $dd): ?>
            <div class="dia-card" id="btn-dia-<?php echo $dd['dia_pago']; ?>" onclick="filtrarPorDia('<?php echo $dd['dia_pago']; ?>')">
                <span style="font-size: 0.7rem; color: var(--text-dim); font-weight: 800;">DÍA <?php echo $dd['dia_pago']; ?></span><br>
                <b style="font-size: 1.4rem; color: var(--text-main);"><?php echo $dd['cantidad']; ?></b><br>
                <small style="font-size: 0.6rem; color: var(--primary); font-weight: 700;">CLI.</small>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="panel-box" style="margin-bottom: 30px;">
        <div class="panel-title">
            <i class="fas fa-users"></i> Clientes 
            <span id="textoFiltro" style="color: var(--primary); margin-left: 8px;"></span>
        </div>
        
        <div id="contenedorClientes">
            <?php while($c = $clientes_list->fetch_assoc()): 
                $es_manual = ($c['proteccion_manual'] == 1);
                $es_auto = ($c['proteccion'] == 1 && !$es_manual);
                $tiene_prorroga = (!empty($c['prorroga_hasta']) && strtotime($c['prorroga_hasta']) > time());
                
                $mes_vencimiento = (int)date('Ym', strtotime($c['pagado_hasta']));
                $mes_actual = (int)date('Ym');
                $esta_pagado = ($mes_vencimiento > $mes_actual);
            ?>
            <div class="client-item fila-cliente" data-dia="<?php echo $c['dia_pago']; ?>" style="display: none;">
                <div>
                    <b style="display:flex; align-items:center; font-size:0.9rem;">
                        <?php echo htmlspecialchars($c['user_mikrotik']); ?>
                        <?php if($es_manual): ?>
                            <i class="fas fa-shield-virus escudo escudo-manual" title="Protección Manual"></i>
                        <?php elseif($es_auto): ?>
                            <i class="fas fa-shield-alt escudo escudo-auto" title="Protegido por Pago"></i>
                        <?php endif; ?>
                    </b>
                    <small style="color:var(--text-dim); font-size:0.7rem;">Corte: Día <?php echo $c['dia_pago']; ?> | <?php echo str_replace('PPPoeClientes-', '', $c['plan']); ?></small>
                </div>
                
                <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 5px;">
                    <?php if($esta_pagado): ?>
                        <span class="estado-pago pago-ok"><i class="fas fa-check"></i> PAGADO</span>
                        <button onclick="mostrarFactura('<?php echo htmlspecialchars($c['user_mikrotik']); ?>', '<?php echo date("d/m/Y", strtotime($c['pagado_hasta'])); ?>')" class="btn-mini" style="background: rgba(59, 130, 246, 0.2); color: var(--primary); border: 1px solid var(--primary);">
                            <i class="fas fa-receipt"></i> Ver Factura
                        </button>
                    <?php else: ?>
                        <span class="estado-pago pago-deuda"><i class="fas fa-times"></i> PENDIENTE</span>
                        <button onclick="abrirModalPago('<?php echo htmlspecialchars($c['user_mikrotik']); ?>')" class="btn-mini" style="background: var(--warning); color: #000;">
                            <i class="fas fa-hand-holding-dollar"></i> Cobrar
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    </div>

    <div id="modalPago" class="modal-overlay" onclick="cerrarModalExterno(event, 'modalPago')">
        <div class="modal-content" onclick="event.stopPropagation()">
            <h3 style="margin-top:0; color:var(--primary); font-weight:800;"><i class="fas fa-money-bill-wave"></i> Nuevo Pago</h3>
            <form method="POST">
                
                <select name="user_pago" id="inputUserPago" required style="font-weight:bold; color:var(--primary);">
                    <option value="">-- Seleccione un Cliente --</option>
                    <?php 
                    $opciones = $conn->query("SELECT user_mikrotik FROM abonados ORDER BY user_mikrotik ASC");
                    while($o = $opciones->fetch_assoc()) echo "<option value='".htmlspecialchars($o['user_mikrotik'])."'>".htmlspecialchars($o['user_mikrotik'])."</option>";
                    ?>
                </select>

                <input type="number" name="monto" step="0.01" placeholder="Monto en $" value="20.00" required>
                <select name="metodo" required>
                    <option value="Pago Movil">📱 Pago Móvil</option>
                    <option value="Efectivo">💵 Efectivo</option>
                    <option value="Zelle">💳 Zelle</option>
                </select>

                <button type="submit" name="btn_modal_pago" class="btn-action bg-blue" style="width:100%; margin-top:10px;">
                    REGISTRAR Y ACTIVAR
                </button>
                <button type="button" onclick="cerrarModal('modalPago')" style="width:100%; background:none; border:none; color:var(--text-dim); margin-top:15px; cursor:pointer; font-weight:700;">CANCELAR</button>
            </form>
        </div>
    </div>

    <div id="modalFactura" class="modal-overlay" onclick="cerrarModalExterno(event, 'modalFactura')">
        <div class="ticket" onclick="event.stopPropagation()">
            <h2>PRO-DARK ISP</h2>
            <div style="text-align: center; font-size: 0.7rem; margin-bottom: 10px;">Recibo de Servicio de Internet</div>
            <div class="divider"></div>
            
            <div class="ticket-row"><span>FECHA:</span> <span><?php echo date('d/m/Y'); ?></span></div>
            <div class="ticket-row"><span>HORA:</span> <span><?php echo date('h:i A'); ?></span></div>
            <div class="ticket-row"><span>CLIENTE:</span> <span id="ticketUser" style="color:var(--primary);">---</span></div>
            
            <div class="divider"></div>
            
            <div class="ticket-row"><span>ESTADO:</span> <span style="color: green;">PAGADO OK</span></div>
            <div class="ticket-row"><span>VENCE:</span> <span id="ticketVence">---</span></div>
            
            <div class="divider"></div>
            <div style="text-align: center; font-size: 0.75rem; font-weight: bold; margin-top: 15px;">
                ¡Gracias por su pago!<br>Sistema automatizado.
            </div>
            
            <button onclick="window.print()" class="btn-action bg-blue" style="width:100%; margin-top: 20px; padding: 12px; font-size: 0.8rem;">
                <i class="fas fa-print"></i> IMPRIMIR / CAPTURE
            </button>
            <button onclick="cerrarModal('modalFactura')" style="width:100%; background:none; border:none; color:#666; margin-top:10px; cursor:pointer; font-weight:700; font-family: 'Courier New', monospace;">CERRAR</button>
        </div>
    </div>

    <script>
        // --- RELOJ EN TIEMPO REAL ---
        function actualizarReloj() {
            const fecha = new Date();
            const opcionesFecha = { weekday: 'short', day: '2-digit', month: 'short' };
            const opcionesHora = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
            document.getElementById('reloj-digital').innerHTML = `${fecha.toLocaleDateString('es-VE', opcionesFecha).toUpperCase()} <span style="color:var(--primary); margin-left:4px;">${fecha.toLocaleTimeString('es-VE', opcionesHora)}</span>`;
        }
        setInterval(actualizarReloj, 1000); actualizarReloj();

        // --- FUNCIONES DE MODALES ---
        function abrirModalPago(usuario) { 
            const select = document.getElementById('inputUserPago');
            if(usuario) {
                select.value = usuario; // Auto-selecciona en el dropdown
            } else {
                select.value = ''; // Lo deja en blanco para que escojas
            }
            document.getElementById('modalPago').style.display = 'flex'; 
        }
        
        function mostrarFactura(usuario, vence) {
            document.getElementById('ticketUser').innerText = usuario;
            document.getElementById('ticketVence').innerText = vence;
            document.getElementById('modalFactura').style.display = 'flex';
        }
        
        function cerrarModal(id) { document.getElementById(id).style.display = 'none'; }
        function cerrarModalExterno(e, id) { if(e.target.id === id) cerrarModal(id); }

        // --- AUTO-ABRIR FACTURA SI VIENE DE UN PAGO ---
        const params = new URLSearchParams(window.location.search);
        if(params.has('factura_user')) {
            const rawDate = params.get('vence');
            const parts = rawDate.split('-');
            const formatVence = parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : rawDate;
            mostrarFactura(params.get('factura_user'), formatVence);
            window.history.replaceState({}, document.title, "index.php");
        }

        // --- FILTRO INTERACTIVO DE DÍAS ---
        function filtrarPorDia(dia) {
            document.querySelectorAll('.dia-card').forEach(c => c.classList.remove('active'));
            let btnActivo = document.getElementById('btn-dia-' + dia);
            if (btnActivo) btnActivo.classList.add('active');

            document.getElementById('textoFiltro').innerText = `(Día ${dia})`;

            const filas = document.querySelectorAll('.fila-cliente');
            filas.forEach(fila => {
                if(fila.getAttribute('data-dia') === String(dia)) {
                    fila.style.display = 'flex';
                } else {
                    fila.style.display = 'none';
                }
            });
        }

        // --- AUTO-FILTRO AL CARGAR LA PÁGINA ---
        window.addEventListener('DOMContentLoaded', () => {
            // Intenta buscar el botón del día actual
            let diaHoy = <?php echo (int)date('j'); ?>;
            let btnHoy = document.getElementById('btn-dia-' + diaHoy);
            
            if(btnHoy) {
                // Si hay cobros programados para hoy, los muestra
                btnHoy.click();
            } else {
                // Si no, simplemente selecciona el primer día que haya en el mapa
                let primerDia = document.querySelector('.dia-card');
                if(primerDia) primerDia.click();
            }
        });

        // --- INICIALIZAR GRÁFICA ---
        const ctx = document.getElementById('planChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($planes_labels); ?>,
                datasets: [{
                    label: 'Clientes Activos',
                    data: <?php echo json_encode($planes_data); ?>,
                    backgroundColor: ['rgba(59, 130, 246, 0.8)', 'rgba(16, 185, 129, 0.8)', 'rgba(251, 191, 36, 0.8)', 'rgba(139, 92, 246, 0.8)'],
                    borderRadius: 6
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { grid: { color: 'rgba(255, 255, 255, 0.05)' }, ticks: { color: '#64748b', stepSize: 1 } }, y: { grid: { display: false }, ticks: { color: '#f8fafc', font: { weight: 'bold' } } } } }
        });
    </script>
</body>
</html>