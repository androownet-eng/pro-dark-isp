<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- 1. SEGURIDAD: EL CANDADO ---
session_start();
if (!isset($_SESSION['admin_auth'])) {
    header("Location: login.php");
    exit();
}

// 1. IMPORTAR CONFIGURACIÓN CENTRALIZADA
require_once "config_global.php";
$python = $python_path; // Ahora usa la variable del cerebro
$dir = $base_dir;       // Ahora usa la variable del cerebro

$dia_actual = date('j');
$mes_actual_inicio = date('Y-m-01'); 

// --- MEJORA: ENDPOINT PARA CONSULTA LIVE ---
if (isset($_GET['get_status_live'])) {
    $res = $conn->query("SELECT user_mikrotik, estado, proteccion, proteccion_manual, prorroga_hasta FROM abonados");
    $data = [];
    while($row = $res->fetch_assoc()) { $data[$row['user_mikrotik']] = $row; }
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// 2. MONITOR LIVE (Puntos ON/OFF)
$ips_json = shell_exec("\"$python\" \"{$dir}obtener_ips.py\" 2>&1");
$ips_online = json_decode($ips_json, true) ?: [];

// --- 3. PROCESAMIENTO DE ACCIONES (MOTOR UNIFICADO BLINDADO) ---

// A. Acciones por GET (Botones rápidos de la tabla)
if (isset($_GET['accion']) && isset($_GET['user'])) {
    $u = $conn->real_escape_string($_GET['user']);
    $accion = $_GET['accion'];

    if ($accion == 'toggle_inmunidad') {
        $conn->query("UPDATE abonados SET proteccion_manual = NOT proteccion_manual, proteccion = NOT proteccion_manual, pagado_hasta = IF(proteccion_manual=1, CURDATE(), '2000-01-01'), estado='activo' WHERE user_mikrotik='$u'");
    } 
    elseif ($accion == 'cortar') {
        $conn->query("UPDATE abonados SET estado='cortado', prorroga_hasta=NULL, pagado_hasta='2000-01-01', proteccion=0, proteccion_manual=0 WHERE user_mikrotik='$u'");
    } 
    elseif ($accion == 'activar_directo') {
        $conn->query("UPDATE abonados SET estado='activo' WHERE user_mikrotik='$u'");
    }
    elseif ($accion == 'prorroga') {
        $u = $conn->real_escape_string($_GET['user']);
        $fecha_raw = isset($_GET['fecha_limite']) ? $_GET['fecha_limite'] : '';

        if (!empty($fecha_raw)) {
            $timestamp = strtotime($fecha_raw);
            if ($timestamp) {
                $vencimiento = date("Y-m-d H:i:s", $timestamp);
                $sql = "UPDATE abonados SET estado = 'activo', prorroga_hasta = '$vencimiento' WHERE user_mikrotik = '$u'";

                if ($conn->query($sql)) {
                    // BLINDAJE
                    $u_esc = escapeshellarg($u);
                    shell_exec("\"$python\" \"{$dir}estado_cliente.py\" $u_esc \"activar\" 2>&1");
                }
            }
        }
        if (isset($_GET['ajax'])) exit;
        header("Location: config.php"); 
        exit;
    }
}

// B. Crear Usuario (BLINDADO)
if (isset($_POST['crear_usuario'])) {
    $u = $conn->real_escape_string($_POST['user']);
    $p_pass = $conn->real_escape_string($_POST['pass']); 
    $p_plan = $conn->real_escape_string($_POST['plan']);
    $d = (int)$_POST['dia_pago'];
    $h = $_POST['hora_corte'];

    $sql = "INSERT INTO abonados (user_mikrotik, plan, dia_pago, hora_corte, estado) 
            VALUES ('$u', '$p_plan', $d, '$h', 'activo')";

    if ($conn->query($sql)) {
        // BLINDAJE: Protegemos cada variable antes de enviarla a la terminal
        $u_esc = escapeshellarg($u);
        $plan_esc = escapeshellarg($p_plan);
        $pass_esc = escapeshellarg($p_pass);

        $comando = "\"$python\" \"{$dir}estado_cliente.py\" $u_esc \"activar\" $plan_esc $pass_esc";
        shell_exec($comando . " 2>&1");

        header("Location: config.php?res=creado"); 
        exit;
    } else {
        echo "Error: " . $conn->error;
    }
}

// F. Eliminar Cliente (BLINDADO)
if (isset($_GET['delete_user'])) {
    $u = $conn->real_escape_string($_GET['delete_user']);

    // BLINDAJE
    $u_esc = escapeshellarg($u);
    $comando_del = "\"$python\" \"{$dir}estado_cliente.py\" $u_esc \"eliminar\" 2>&1";
    shell_exec($comando_del);

    $conn->query("DELETE FROM abonados WHERE user_mikrotik = '$u'");
    header("Location: config.php?res=eliminado");
    exit;
}

// C. Editar Cliente (BLINDADO)
if (isset($_POST['editar_cliente'])) {
    $u = $conn->real_escape_string($_POST['user_edit']); 
    $n_plan = $conn->real_escape_string($_POST['nuevo_plan']); 
    $n_dia = (int)$_POST['nuevo_dia']; 
    $n_hora = $_POST['nueva_hora'];

    $n_estado = ($n_dia >= (int)date('j')) ? 'activo' : 'cortado';

    $sql = "UPDATE abonados SET plan='$n_plan', dia_pago=$n_dia, hora_corte='$n_hora', estado='$n_estado' WHERE user_mikrotik='$u'";

    if($conn->query($sql)){
        $accion_py = ($n_estado == 'activo') ? 'activar' : 'cortar';
        // BLINDAJE
        $u_esc = escapeshellarg($u);
        shell_exec("\"$python\" \"{$dir}estado_cliente.py\" $u_esc \"$accion_py\" 2>&1");
    }
    header("Location: config.php?res=editado"); exit;
}

// D. Sincronizar Ahora (AUTOMATIZADO)
if (isset($_POST['sincronizar_ahora'])) {
    $py_exe = $python; 
    $py_script = $dir . "estado_cliente.py"; 

    $res = $conn->query("SELECT user_mikrotik FROM abonados");

    while ($row = $res->fetch_assoc()) {
        $u = escapeshellarg($row['user_mikrotik']); // Ya estaba blindado aquí
        shell_exec("\"$py_exe\" \"$py_script\" $u \"sync\" 2>&1");
    }
    header("Location: config.php?sync=ok");
    exit;
}

// --- G. IMPORTAR DESDE MIKROTIK A BASE DE DATOS (Centralizado) ---
if (isset($_POST['importar_desde_mt'])) {
    $comando = "\"$python\" \"{$dir}get_secrets.py\"";
    $json_extraido = shell_exec($comando . " 2>&1");
    $lista_usuarios = json_decode($json_extraido, true);

    if ($lista_usuarios && !isset($lista_usuarios['error'])) {
        foreach ($lista_usuarios as $u_mt) {
            $nombre = $conn->real_escape_string($u_mt['name']);
            $plan = $conn->real_escape_string($u_mt['profile']);

            $sql_import = "INSERT INTO abonados (user_mikrotik, plan, estado, dia_pago, hora_corte) 
                           VALUES ('$nombre', '$plan', 'activo', 1, '08:00:00') 
                           ON DUPLICATE KEY UPDATE plan = '$plan'";
            $conn->query($sql_import);
        }
        header("Location: config.php?res=import_ok");
        exit;
    } else {
        echo "<script>alert('Error de conexión con el RB4011');</script>";
    }
}

// E. Acciones Masivas (BLINDADO)
if (isset($_POST['accion_masiva']) && !empty($_POST['usuarios_sel'])) {
    $accion = $_POST['accion_masiva'];
    $n_plan = $_POST['plan_masivo'] ?? '';

    foreach ($_POST['usuarios_sel'] as $u) {
        $u = $conn->real_escape_string($u);
        // BLINDAJE
        $u_esc = escapeshellarg($u);

        if ($accion == 'cambiar_plan') {
            $conn->query("UPDATE abonados SET plan='$n_plan' WHERE user_mikrotik='$u'");
            shell_exec("\"$python\" \"{$dir}estado_cliente.py\" $u_esc \"activar\" 2>&1");
        } elseif ($accion == 'activar') {
            $conn->query("UPDATE abonados SET estado='activo' WHERE user_mikrotik='$u'");
            shell_exec("\"$python\" \"{$dir}estado_cliente.py\" $u_esc \"activar\" 2>&1");
        } elseif ($accion == 'cortar') {
            $conn->query("UPDATE abonados SET estado='cortado', proteccion=0, proteccion_manual=0 WHERE user_mikrotik='$u'");
            shell_exec("\"$python\" \"{$dir}estado_cliente.py\" $u_esc \"cortar\" 2>&1");
        }
    }
    header("Location: config.php?res=masivo"); exit;
}

$clientes = $conn->query("SELECT * FROM abonados ORDER BY user_mikrotik ASC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ISP Manager TOTAL</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    :root { 
        --bg: #000000;           --card: #0f172a;         --primary: #3b82f6;      
        --success: #10b981;      --danger: #ef4444;       --warning: #f59e0b;      
        --text: #f1f5f9;         --text-dim: #94a3b8;     --border: #334155;       
    }

    * { box-sizing: border-box; }

    body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 10px; padding-bottom: 250px; line-height: 1.5; }

    /* Botones Sólidos y Profesionales */
    .btn { border: none; padding: 12px; border-radius: 12px; font-weight: 700; cursor: pointer; color: white; display: inline-flex; align-items: center; gap: 6px; font-size: 13px; text-decoration:none; transition: 0.2s; justify-content: center;}
    .btn:active { transform: scale(0.96); }
    .btn-blue { background: linear-gradient(135deg, #3b82f6, #2563eb); box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3); } 
    .btn-gray { background: #1e293b; color: var(--text-dim); border: 1px solid var(--border); } 
    .btn-orange { background: rgba(245, 158, 11, 0.1); color: var(--warning); border: 1px solid rgba(245, 158, 11, 0.3); }
    .btn-orange:hover { background: var(--warning); color: #000; }

    /* Tarjetas Sólidas */
    .card { background: var(--card); border-radius: 16px; padding: 15px; box-shadow: 0 8px 15px rgba(0, 0, 0, 0.4); margin-bottom: 10px; border: 1px solid var(--border); transition: transform 0.3s; }
    
    /* Contenedor de Estadísticas (Mobile-First) */
    .stats-container { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 15px; }

    /* --- TABLA MODIFICADA: SOLO CSS PARA EL ANCHO --- */
    .table-responsive { overflow-x: hidden; -webkit-overflow-scrolling: touch; border-radius: 16px; background: var(--card); border: 1px solid var(--border); }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    th, td { padding: 15px 10px; border-bottom: 1px solid var(--border); text-align: left; font-size: 13px; white-space: normal; word-wrap: break-word; overflow-wrap: break-word; }
    th { background: rgba(15, 23, 42, 0.8); color: var(--primary); font-size: 11px; text-transform: uppercase; letter-spacing: 0.8px; }
    td { color: var(--text-dim); }
    td strong { color: var(--text); }
    
    /* Porcentajes para asegurar que cada columna respete su espacio */
    .col-check { width: 35px; }
    th:nth-child(2), td:nth-child(2) { width: 35%; }
    th:nth-child(3), td:nth-child(3) { width: 25%; }
    th:nth-child(4), td:nth-child(4) { width: 20%; }
    th:nth-child(5), td:nth-child(5) { width: 20%; }
    /* ------------------------------------------------ */

    /* Inputs y Selectores Sólidos Estilo Pill */
    input, select { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 20px; margin: 8px 0; background: #000000; color: white; font-size: 1rem; }
    input:focus, select:focus { border-color: var(--primary); outline: none; }

    /* Controles de Acción (Buscador y Masivo) */
    .action-bar { display: flex; gap: 10px; margin-bottom: 15px; align-items: center; flex-wrap: wrap; }
    .action-bar input { flex-grow: 1; margin: 0; min-width: 200px; }

    /* Status Neón Sólido */
    .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
    .online { background: var(--success); box-shadow: 0 0 10px var(--success); }
    .offline { background: #475569; }

    /* Masivo y Modales Sólidos */
    .mass-bar { 
    display: none; 
    position: fixed; 
    bottom: 0; 
    left: 0; 
    right: 0; 
    background: #111827; 
    color: white; 
    padding: 20px 15px 30px 15px; 
    z-index: 5000; /* Súbelo a 5000 para que nada la tape excepto los modales */
    border-radius: 28px 28px 0 0; 
    box-shadow: 0 -10px 30px rgba(0,0,0,0.6); 
    border-top: 2px solid var(--primary); 
}
   
    /* CSS ESCUDOS ACTUALIZADO */
   .shield-switch { position: relative; display: inline-block; width: 34px; height: 18px; margin-right: 5px; }
    .shield-switch input { opacity: 0; width: 0; height: 0; }
    .shield-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #334155; transition: .3s; border-radius: 20px; }
    .shield-slider:before { position: absolute; content: ""; height: 12px; width: 12px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; }
    input:checked + .shield-slider { background-color: var(--warning); }
    input:checked + .shield-slider:before { transform: translateX(16px); }
    
    /* Estilos de los Escudos con Brillo */
    .shield-manual { color: var(--warning) !important; filter: drop-shadow(0 0 5px rgba(245, 158, 11, 0.8)); animation: pulse-gold 2s infinite; font-size: 14px; }
    .shield-auto { color: var(--primary) !important; filter: drop-shadow(0 0 5px rgba(59, 130, 246, 0.5)); font-size: 14px; }
    
    /* Escudo Rojo de Prórroga */
    .escudo-rojo {
        font-size: 9px; 
        background: rgba(239, 68, 68, 0.15); 
        color: #ef4444; 
        padding: 3px 6px; 
        border-radius: 5px; 
        font-weight: 900; 
        border: 1px solid rgba(239, 68, 68, 0.3); 
        display: inline-block; 
        margin-top: 5px; 
        animation: pulse-red 1.5s infinite;
    }

    @keyframes pulse-gold { 0% { transform: scale(1); } 50% { transform: scale(1.15); opacity: 0.8; } 100% { transform: scale(1); } }
    @keyframes pulse-red { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
    
    details summary { background: var(--card); padding: 15px; border-radius: 12px; cursor: pointer; border: 1px solid var(--border); font-weight: 800; color: var(--primary); font-size: 0.9rem; list-style: none; display: flex; align-items: center; gap: 10px; }
    details summary i { transition: 0.3s; }
    details[open] summary i { transform: rotate(45deg); }

    /* Menú de Opciones Único (PC y Móvil) */
    .options-dropdown { position: relative; display: inline-block; }
    .options-btn { background: none; border: none; color: var(--text-dim); font-size: 20px; cursor: pointer; padding: 5px; }
    .options-content { display: none; position: absolute; right: 0; background-color: #1e293b; min-width: 140px; box-shadow: 0 8px 16px rgba(0,0,0,0.2); z-index: 100; border-radius: 8px; border: 1px solid var(--border); }
    .options-content a, .options-content button { color: var(--text-main); padding: 10px 15px; text-decoration: none; display: flex; align-items: center; gap: 10px; width: 100%; border: none; background: none; font-size: 13px; cursor: pointer; text-align: left; }
    .options-content a:hover, .options-content button:hover { background-color: #334155; }
    .options-dropdown:hover .options-content { display: block; }

    /* --- ADAPTACIONES MÓVILES EXTRAS --- */
    @media (max-width: 600px) {
        .stats-container { gap: 5px; }
        .stats-container .card { padding: 10px; }
        .stats-container b { font-size: 1.3rem !important; }
        
        .action-bar button { flex-grow: 1; }
        .btn { padding: 10px; font-size: 12px; }
        
        th, td { padding: 12px 8px; font-size: 12px; }
    }
</style>
</head>
<body>
    <div style="display:flex; justify-content:space-between; margin-bottom:15px; align-items:center;">
        <a href="index.php" class="btn btn-gray" style="border-radius:20px; padding: 8px 15px;"><i class="fas fa-home"></i> Dashboard</a>
        <div style="font-size: 0.6rem; color: var(--success); font-weight: 800; letter-spacing: 1px;">
            <i class="fas fa-sync-alt"></i> LIVE OK
        </div>
        <b style="color:var(--primary); font-size:0.8rem; text-transform:uppercase; letter-spacing:1px;">Día <?php echo $dia_actual; ?></b>
    </div>

    <div class="stats-container">
        <div class="card" style="text-align:center; border-top: 3px solid var(--success);">
            <small style="font-size:9px; color:var(--text-dim); text-transform:uppercase;">Activos</small><br>
            <b style="color:var(--success); font-size:1.6rem; font-weight:900;">
                <?php 
                // Cuenta activos O con prórroga vigente
                echo $conn->query("SELECT id FROM abonados WHERE estado='activo' OR (prorroga_hasta IS NOT NULL AND prorroga_hasta > NOW())")->num_rows; 
                ?>
            </b>
        </div>
        <div class="card" style="text-align:center; border-top: 3px solid var(--danger);">
            <small style="font-size:9px; color:var(--text-dim); text-transform:uppercase;">Cortes</small><br>
            <b style="color:var(--danger); font-size:1.6rem; font-weight:900;">
                <?php 
                // Cuenta solo cortados que NO tienen prórroga vigente
                echo $conn->query("SELECT id FROM abonados WHERE estado='cortado' AND (prorroga_hasta IS NULL OR prorroga_hasta <= NOW())")->num_rows; 
                ?>
            </b>
        </div>
        <div class="card" style="text-align:center; border-top: 3px solid var(--warning);">
            <small style="font-size:9px; color:var(--text-dim); text-transform:uppercase;">Vencen</small><br>
            <b style="color:var(--warning); font-size:1.6rem; font-weight:900;"><?php echo $conn->query("SELECT id FROM abonados WHERE dia_pago=$dia_actual")->num_rows; ?></b>
        </div>
    </div>

    <details class="card" style="margin-bottom: 15px; padding:0;">
        <summary><i class="fas fa-user-plus"></i> + NUEVO ABONADO</summary>
        <form method="POST" style="padding: 15px;">
            <input type="text" name="user" placeholder="Usuario Mikrotik (RB4011)" required>
            <input type="password" name="pass" placeholder="Contraseña PPP" required> 
            <select name="plan">
                <option value="">-- Seleccionar Plan --</option>
                <?php $p_res = $conn->query("SELECT * FROM planes"); while($p = $p_res->fetch_assoc()) { echo "<option value='".$p['perfil_mikrotik']."'>".$p['nombre_visible']."</option>"; } ?>
            </select>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                <input type="number" name="dia_pago" placeholder="Día" required>
                <input type="time" name="hora_corte" value="08:00" required>
            </div>
            <button type="submit" name="crear_usuario" class="btn btn-blue" style="width:100%; margin-top:15px; padding:15px; border-radius:20px;">INMUNIZAR Y GUARDAR</button>
        </form>
    </details>

    <div class="action-bar">
    <input type="text" id="busqueda" placeholder="🔍 Buscar abonado..." style="padding:12px; border-radius:18px;">
    
    <form method="POST" style="display:inline; margin:0;">
        <button type="submit" name="sincronizar_ahora" class="btn btn-blue" title="Sincronizar Panel -> MikroTik" style="padding:12px; border-radius:18px; width: 45px; height: 45px;"><i class="fas fa-sync"></i></button>
    </form>

    <form method="POST" style="display:inline; margin:0;">
        <button type="submit" name="importar_desde_mt" class="btn btn-gray" title="Importar MikroTik -> Panel" style="padding:12px; border-radius:18px; width: 45px; height: 45px; border: 1px dashed var(--primary); color: var(--primary);">
            <i class="fas fa-file-import"></i>
        </button>
    </form>

    <button type="button" onclick="activarModoMasivo()" id="btnM" class="btn btn-orange" style="padding:12px; border-radius:18px; width: 45px; height: 45px;"><b style="font-size:16px;">M</b></button>
</div>

<form method="POST">
    <div id="barraMasiva" class="mass-bar" style="display:none;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
            <b id="contador" style="color:var(--warning); font-size:1.1rem; font-weight:800;">0 SELECCIONADOS</b>
            <button type="button" onclick="cancelarMasivo()" style="background:none; border:none; color:white; font-size:28px; cursor:pointer;">&times;</button>
        </div>
        <select name="plan_masivo">
            <option value="">-- Cambiar Plan --</option>
            <?php $p_res2 = $conn->query("SELECT * FROM planes"); while($p2 = $p_res2->fetch_assoc()) { echo "<option value='".$p2['perfil_mikrotik']."'>".$p2['nombre_visible']."</option>"; } ?>
        </select>
        <button type="submit" name="accion_masiva" value="cambiar_plan" class="btn btn-orange" style="width:100%; margin-bottom:10px; padding:12px; border-radius:20px;">APLICAR PLAN</button>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <button type="submit" name="accion_masiva" value="activar" class="btn btn-blue">ACTIVA</button>
            <button type="submit" name="accion_masiva" value="cortar" class="btn" style="background:var(--danger);">CORTA</button>
        </div>
    </div>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th class="col-check" style="display:none; text-align:center;"><input type="checkbox" id="checkAll"></th>
                    <th>CLIENTE / PAGO</th>
                    <th style="text-align:center;">🛡️ ESCUDO</th> 
                    <th style="text-align:center;">ESTADO / PING</th>
                    <th style="text-align:right;">OPCIONES</th>
                </tr>
            </thead>
            <tbody id="tablaBody">
                <?php while($r = $clientes->fetch_assoc()): 
                    $u = $r['user_mikrotik']; $online = isset($ips_online[$u]);
                    $manual = ($r['proteccion_manual'] == 1);
                    $auto = ($r['proteccion'] == 1 && !$manual);
                    $tiene_prorroga = (!empty($r['prorroga_hasta']) && strtotime($r['prorroga_hasta']) > time());
                    $esta_realmente_activo = ($r['estado'] == 'activo' || $tiene_prorroga);
                ?>
                <tr id="fila-<?php echo $u; ?>">
                    <td class="col-check" style="display:none; text-align:center;"><input type="checkbox" name="usuarios_sel[]" value="<?php echo $u; ?>" class="user-check" onchange="actualizarBarra()"></td>
                    <td>
                        <b style="color:var(--primary); font-size:1rem; display:block;"><?php echo $u; ?></b>
                        <small style="color:var(--text-dim)"><?php echo str_replace('PPPoeClientes-', '', $r['plan']); ?></small><br>
                        <div style="font-size:11px; background: rgba(59, 130, 246, 0.05); color: var(--primary); padding: 3px 6px; border-radius: 4px; display: inline-block; border: 1px solid rgba(59, 130, 246, 0.1); margin-top: 4px;">
                            Día <?php echo $r['dia_pago']; ?> @ <?php echo date("h:i A", strtotime($r['hora_corte'])); ?>
                        </div>
                    </td>

                    <td style="text-align:center;">
                        <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:5px;">
                            <label class="shield-switch">
                                <input type="checkbox" <?php echo ($manual) ? 'checked' : ''; ?> 
                                       onclick="ejecutarAccionLive('config.php?accion=toggle_inmunidad&user=<?php echo $u; ?>', this)">
                                <span class="shield-slider"></span>
                            </label>
                            <span id="shield-icon-<?php echo $u; ?>" style="height:16px;">
                                <?php if($manual): ?>
                                    <i class="fas fa-shield-alt shield-manual" title="Inmunidad Manual"></i>
                                <?php elseif($auto): ?>
                                    <i class="fas fa-shield-alt shield-auto" title="Inmunidad por Pago"></i>
                                <?php endif; ?>
                            </span>
                        </div>
                    </td>

                    <td class="td-estado" style="text-align:center;">
                        <div style="display:flex; align-items:center; justify-content:center; gap:8px;" 
                             id="container-<?php echo $u; ?>" 
                             data-prorroga="<?php echo $tiene_prorroga ? '1' : '0'; ?>">
                            <?php if($esta_realmente_activo): ?>
                                <i class="fas fa-toggle-on" onclick="ejecutarAccionLive('config.php?accion=cortar&user=<?php echo $u; ?>', this)" style="font-size:24px; color:var(--success); cursor:pointer;"></i>
                            <?php else: ?>
                                <i class="fas fa-toggle-off" onclick="ejecutarAccionLive('config.php?accion=activar_directo&user=<?php echo $u; ?>', this)" style="font-size:24px; color:var(--danger); cursor:pointer;"></i>
                            <?php endif; ?>
                            <span class="status-dot <?php echo ($online?'online':'offline'); ?>"></span>
                        </div>
                    </td>
                    
                    <td style="text-align:right;">
                        <div class="options-dropdown">
                            <button type="button" class="options-btn"><i class="fas fa-ellipsis-v"></i></button>
                            <div class="options-content">
                                <button type="button" onclick="solicitarProrroga('<?php echo $u; ?>')" style="color:var(--warning)">
                                    <i class="fas fa-clock-rotate-left"></i> Dar Prórroga
                                </button>
                                <button type="button" onclick="abrirEdit('<?php echo $u; ?>', '<?php echo $r['dia_pago']; ?>', '<?php echo $r['hora_corte']; ?>')" style="color:var(--primary)">
                                    <i class="fas fa-edit"></i> Editar Ficha
                                </button>
                                <a href="config.php?delete_user=<?php echo $u; ?>" onclick="return confirm('¿Eliminar a <?php echo $u; ?> permanentemente?')" style="color:var(--danger)">
                                    <i class="fas fa-trash-alt"></i> Eliminar
                                </a>
                            </div>
                        </div>

                        <?php if($tiene_prorroga): ?>
                            <br>
                            <div class="escudo-rojo">
                                <i class="fas fa-shield-virus"></i> PRÓRROGA: <?php echo date("d/m H:i", strtotime($r['prorroga_hasta'])); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</form>

<div id="modalEdit" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(10px); align-items:center; justify-content:center;">
    <div style="background:var(--card); max-width:400px; width:90%; padding:25px; border-radius:24px; border:1px solid var(--border); box-shadow:0 20px 50px rgba(0,0,0,0.5);">
        <div style="text-align:center; margin-bottom:20px;">
            <div style="width:60px; height:60px; background:rgba(59, 130, 246, 0.1); color:var(--primary); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 15px; font-size:24px; border:1px solid rgba(59, 130, 246, 0.3);">
                <i class="fas fa-user-edit"></i>
            </div>
            <h3 id="userTit" style="margin:0; color:#fff; font-weight:900; letter-spacing:1px; text-transform:uppercase; font-size:1.2rem;"></h3>
            <p style="color:var(--text-dim); font-size:12px; margin-top:5px;">Actualizar datos del abonado</p>
        </div>

        <form method="POST">
            <input type="hidden" name="user_edit" id="userInp">
            
            <div style="margin-bottom:15px;">
                <label style="font-size:11px; color:var(--primary); font-weight:800; text-transform:uppercase; margin-left:5px;">Plan de Internet</label>
                <select name="nuevo_plan" style="width:100%; padding:15px; margin-top:5px; background:#0f172a; border:1px solid var(--border); color:white; border-radius:15px; font-size:15px; outline:none; appearance:none;">
                    <?php $p_res3 = $conn->query("SELECT * FROM planes"); while($p3 = $p_res3->fetch_assoc()) { echo "<option value='".$p3['perfil_mikrotik']."'>".$p3['nombre_visible']."</option>"; } ?>
                </select>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                <div>
                    <label style="font-size:11px; color:var(--primary); font-weight:800; text-transform:uppercase; margin-left:5px;">Día de Pago</label>
                    <input type="number" name="nuevo_dia" id="diaInp" style="width:100%; padding:15px; background:#0f172a; border:1px solid var(--border); color:white; border-radius:15px; outline:none; box-sizing:border-box;">
                </div>
                <div>
                    <label style="font-size:11px; color:var(--primary); font-weight:800; text-transform:uppercase; margin-left:5px;">Hora Corte</label>
                    <input type="time" name="nueva_hora" id="horaInp" style="width:100%; padding:15px; background:#0f172a; border:1px solid var(--border); color:white; border-radius:15px; outline:none; box-sizing:border-box;">
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr; gap:10px; margin-top:25px;">
                <button type="submit" name="editar_cliente" class="btn btn-blue" style="padding:15px; border-radius:15px; font-weight:800; text-transform:uppercase; letter-spacing:1px;">GUARDAR CAMBIOS</button>
                <button type="button" onclick="document.getElementById('modalEdit').style.display='none'" style="padding:10px; background:none; border:none; color:var(--text-dim); cursor:pointer; font-weight:700;">CANCELAR</button>
            </div>
        </form>
    </div>
</div>

<div id="modalProrroga" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(10px); align-items:center; justify-content:center;">
    <div style="background:var(--card); max-width:400px; width:90%; padding:25px; border-radius:24px; border:1px solid var(--border); box-shadow:0 20px 50px rgba(0,0,0,0.5);">
        <div style="text-align:center; margin-bottom:20px;">
            <div style="width:60px; height:60px; background:rgba(245, 158, 11, 0.1); color:var(--warning); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 15px; font-size:24px; border:1px solid rgba(245, 158, 11, 0.3);">
                <i class="fas fa-clock-rotate-left"></i>
            </div>
            <h3 id="prorrogaUserTit" style="margin:0; color:#fff; font-weight:900; letter-spacing:1px; text-transform:uppercase; font-size:1.2rem;">PRÓRROGA</h3>
            <p style="color:var(--text-dim); font-size:12px; margin-top:5px;">Establecer nuevo límite de navegación</p>
        </div>
        <div style="display:flex; flex-direction:column; gap:15px;">
            <div>
                <label style="font-size:11px; color:var(--warning); font-weight:800; text-transform:uppercase; margin-left:5px;">Vence el:</label>
                <input type="datetime-local" id="fechaProrrogaInput" style="width:100%; padding:15px; background:#0f172a; border:1px solid var(--border); color:white; border-radius:15px; font-size:16px; outline:none; box-sizing:border-box;">
            </div>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                <button type="button" onclick="confirmarProrroga()" class="btn btn-orange" style="padding:15px; border-radius:15px; font-weight:800; background:var(--warning); color:#000;">ACTIVAR</button>
                <button type="button" onclick="cerrarProrroga()" style="padding:15px; background:none; border:none; color:var(--text-dim); font-weight:700;">CANCELAR</button>
            </div>
        </div>
    </div>
</div>

<script>
    let usuarioProrrogaActual = "";

    function ejecutarAccionLive(url, elemento) {
        const fila = elemento.closest('tr');
        fila.style.opacity = '0.3'; 
        fetch(url + "&ajax=1").then(() => {
            location.reload(); 
        });
    }

    function monitorDeEstados() {
        fetch('config.php?get_status_live=1')
        .then(res => res.json())
        .then(data => {
            for (const user in data) {
                const container = document.getElementById('container-' + user);
                if (!container) continue;
                const info = data[user]; 
                const tieneProrroga = container.getAttribute('data-prorroga') === '1';
                const visualmenteActivo = (info.estado === 'activo' || tieneProrroga);
                const dot = container.querySelector('.status-dot');
                const dotClass = dot.classList.contains('online') ? 'online' : 'offline';

                if (visualmenteActivo) {
                    container.innerHTML = `<i class="fas fa-toggle-on" onclick="ejecutarAccionLive('config.php?accion=cortar&user=${user}', this)" style="font-size:24px; color:var(--success); cursor:pointer;"></i><span class="status-dot ${dotClass}"></span>`;
                } else {
                    container.innerHTML = `<i class="fas fa-toggle-off" onclick="ejecutarAccionLive('config.php?accion=activar_directo&user=${user}', this)" style="font-size:24px; color:var(--danger); cursor:pointer;"></i><span class="status-dot ${dotClass}"></span>`;
                }
            }
        });
    }

    setInterval(monitorDeEstados, 3000);

    function abrirEdit(u, d, h) { 
        const modal = document.getElementById('modalEdit');
        modal.style.display = 'flex'; 
        document.getElementById('userTit').innerText = u; 
        document.getElementById('userInp').value = u; 
        document.getElementById('diaInp').value = d; 
        document.getElementById('horaInp').value = h; 
    }

    function solicitarProrroga(usuario) {
        usuarioProrrogaActual = usuario;
        let hoy = new Date();
        hoy.setDate(hoy.getDate() + 1);
        hoy.setMinutes(hoy.getMinutes() - hoy.getTimezoneOffset());
        let fechaSugerida = hoy.toISOString().slice(0, 16);
        
        document.getElementById('fechaProrrogaInput').value = fechaSugerida;
        document.getElementById('prorrogaUserTit').innerText = usuario;
        document.getElementById('modalProrroga').style.display = 'flex'; 
    }

    function cerrarProrroga() {
        document.getElementById('modalProrroga').style.display = 'none';
    }

    function confirmarProrroga() {
        let fecha = document.getElementById('fechaProrrogaInput').value;
        if (fecha) {
            let fechaFormateada = fecha.replace('T', ' ');
            window.location.href = "config.php?accion=prorroga&user=" + usuarioProrrogaActual + "&fecha_limite=" + encodeURIComponent(fechaFormateada);
        }
    }

    // ==========================================
    // LÓGICA DEL MODO MASIVO (LIMPIA Y SIN DUPLICADOS)
    // ==========================================

    function activarModoMasivo() {
        // 1. Mostrar columnas de checkboxes
        document.querySelectorAll('.col-check').forEach(el => el.style.display = 'table-cell');
        // 2. Mostrar la barra de abajo
        document.getElementById('barraMasiva').style.display = 'block';
        // 3. Ocultar el botón M de arriba
        document.getElementById('btnM').style.display = 'none';
        // 4. Actualizar contador
        actualizarBarra();
    }

    function cancelarMasivo() {
        // 1. Ocultar la barra de abajo
        document.getElementById('barraMasiva').style.display = 'none';
        // 2. Ocultar columnas de checkboxes
        document.querySelectorAll('.col-check').forEach(el => el.style.display = 'none');
        // 3. Desmarcar todos los checks para evitar accidentes
        document.querySelectorAll('.user-check').forEach(check => check.checked = false);
        document.getElementById('checkAll').checked = false;
        // 4. Volver a mostrar el botón M
        document.getElementById('btnM').style.display = 'inline-block';
    }

    function actualizarBarra() {
        // Cuenta solo los que están marcados
        let seleccionados = document.querySelectorAll('.user-check:checked').length;
        document.getElementById('contador').innerText = seleccionados + " SELECCIONADOS";
    }

    // Checkbox de seleccionar todos
    document.getElementById('checkAll').onclick = function() {
        let checks = document.querySelectorAll('.user-check');
        checks.forEach(c => {
            // Solo marca los que no están ocultos por el buscador
            if(c.closest('tr').style.display !== 'none') {
                c.checked = this.checked;
            }
        });
        actualizarBarra();
    };

    // ==========================================
    // EXTRAS (BUSCADOR Y TECLADO)
    // ==========================================

    document.getElementById('busqueda').onkeyup = function() { 
        let f = this.value.toLowerCase(); 
        document.querySelectorAll('#tablaBody tr').forEach(tr => { 
            tr.style.display = tr.innerText.toLowerCase().includes(f) ? '' : 'none'; 
        }); 
    };

    // Cerrar el modo masivo o modales presionando Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === "Escape") {
            cancelarMasivo();
            document.getElementById('modalEdit').style.display = 'none';
            document.getElementById('modalProrroga').style.display = 'none';
        }
    });
</script>
</body>
</html>