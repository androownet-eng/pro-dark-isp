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
$ips_json = shell_exec("\"$python\" \"$dir" . "obtener_ips.py\" 2>&1");
$ips_online = json_decode($ips_json, true) ?: [];

// --- 3. PROCESAMIENTO DE ACCIONES (MOTOR UNIFICADO) ---

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
        // Recibimos la fecha y hora combinada (ej: 2026-03-24T14:30)
        $fecha_limite = isset($_GET['fecha_limite']) ? $_GET['fecha_limite'] : date("Y-m-d H:i");
        $vencimiento = date("Y-m-d H:i:s", strtotime($fecha_limite));
        
        // Actualizamos la DB: Estado activo y nueva fecha de prórroga
        $conn->query("UPDATE abonados SET estado='activo', prorroga_hasta='$vencimiento' WHERE user_mikrotik='$u'");
    }

    shell_exec("\"$python\" \"$dir" . "estado_cliente.py\" \"$u\" \"sync\" 2>&1");
    if (isset($_GET['ajax'])) exit;
    header("Location: config.php"); exit;
}

// B. Crear Usuario (CORREGIDO: Ahora envía la contraseña real a Python)
if (isset($_POST['crear_usuario'])) {
    $u = $conn->real_escape_string($_POST['user']);
    $p_pass = $conn->real_escape_string($_POST['pass']); 
    $p_plan = $conn->real_escape_string($_POST['plan']);
    $d = (int)$_POST['dia_pago'];
    $h = $_POST['hora_corte'];

    $sql = "INSERT INTO abonados (user_mikrotik, plan, dia_pago, hora_corte, estado) 
            VALUES ('$u', '$p_plan', $d, '$h', 'activo')";

    if ($conn->query($sql)) {
        // Se envía a Python: usuario, acción, plan y la CONTRASEÑA nueva
        $comando = "\"$python\" \"$dir" . "estado_cliente.py\" \"$u\" \"activar\" \"$p_plan\" \"$p_pass\"";
        shell_exec($comando . " 2>&1");

        header("Location: config.php?res=creado"); 
        exit;
    } else {
        echo "Error: " . $conn->error;
    }
}

// F. Eliminar Cliente (AJUSTADO: Llama a Python antes de borrar de la DB)
if (isset($_GET['delete_user'])) {
    $u = $conn->real_escape_string($_GET['delete_user']);

    // 1. Mandamos la orden a Python para eliminar del MikroTik (RB4011)
    $comando_del = "\"$python\" \"$dir" . "estado_cliente.py\" \"$u\" \"eliminar\" 2>&1";
    shell_exec($comando_del);

    // 2. Ahora lo borramos de la base de datos
    $conn->query("DELETE FROM abonados WHERE user_mikrotik = '$u'");

    header("Location: config.php?res=eliminado");
    exit;
}

// C. Editar Cliente (El Modal)
if (isset($_POST['editar_cliente'])) {
    $u = $conn->real_escape_string($_POST['user_edit']); 
    $n_plan = $conn->real_escape_string($_POST['nuevo_plan']); 
    $n_dia = (int)$_POST['nuevo_dia']; 
    $n_hora = $_POST['nueva_hora'];
    
    $n_estado = ($n_dia >= (int)date('j')) ? 'activo' : 'cortado';

    $sql = "UPDATE abonados SET plan='$n_plan', dia_pago=$n_dia, hora_corte='$n_hora', estado='$n_estado' WHERE user_mikrotik='$u'";
    
    if($conn->query($sql)){
        $accion_py = ($n_estado == 'activo') ? 'activar' : 'cortar';
        shell_exec("\"$python\" \"{$dir}estado_cliente.py\" \"$u\" \"$accion_py\" 2>&1");
    }
    header("Location: config.php?res=editado"); exit;
}

// D. Sincronizar Ahora (AUTOMATIZADO)
if (isset($_POST['sincronizar_ahora'])) {
    // Usamos directamente las variables que vienen del cerebro global
    $py_exe = $python; 
    $py_script = $dir . "estado_cliente.py"; 
    
    $res = $conn->query("SELECT user_mikrotik FROM abonados");
    
    while ($row = $res->fetch_assoc()) {
        $u = escapeshellarg($row['user_mikrotik']);
        shell_exec("\"$py_exe\" \"$py_script\" $u \"sync\" 2>&1");
    }
    header("Location: config.php?sync=ok");
    exit;
}

// E. Acciones Masivas
if (isset($_POST['accion_masiva']) && !empty($_POST['usuarios_sel'])) {
    $accion = $_POST['accion_masiva'];
    $n_plan = $_POST['plan_masivo'] ?? '';

    foreach ($_POST['usuarios_sel'] as $u) {
        $u = $conn->real_escape_string($u);
        if ($accion == 'cambiar_plan') {
            $conn->query("UPDATE abonados SET plan='$n_plan' WHERE user_mikrotik='$u'");
            shell_exec("\"$python\" \"{$dir}estado_cliente.py\" \"$u\" \"activar\" 2>&1");
        } elseif ($accion == 'activar') {
            $conn->query("UPDATE abonados SET estado='activo' WHERE user_mikrotik='$u'");
            shell_exec("\"$python\" \"{$dir}estado_cliente.py\" \"$u\" \"activar\" 2>&1");
        } elseif ($accion == 'cortar') {
            $conn->query("UPDATE abonados SET estado='cortado', proteccion=0, proteccion_manual=0 WHERE user_mikrotik='$u'");
            shell_exec("\"$python\" \"{$dir}estado_cliente.py\" \"$u\" \"cortar\" 2>&1");
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISP Manager TOTAL</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    :root { 
        --bg: #0f172a;           --card: #1e293b;         --primary: #3b82f6;      
        --success: #10b981;      --danger: #ef4444;       --warning: #f59e0b;      
        --text: #f1f5f9;         --text-dim: #94a3b8;     --border: #334155;       
    }

    body { font-family: 'Segoe UI', sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 8px; padding-bottom: 250px; }

    .btn { border: none; padding: 10px; border-radius: 10px; font-weight: bold; cursor: pointer; color: white; display: inline-flex; align-items: center; gap: 5px; font-size: 13px; text-decoration:none; transition: transform 0.1s; }
    .btn:active { transform: scale(0.95); }
    .btn-blue { background: var(--primary); box-shadow: 0 4px 10px rgba(59, 130, 246, 0.2); } 
    .btn-gray { background: #334155; } 
    .btn-orange { background: var(--warning); color: #000; }

    .card { background: var(--card); border-radius: 12px; padding: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2); margin-bottom: 8px; border: 1px solid var(--border); }
    table { width: 100%; border-collapse: collapse; background: var(--card); border-radius: 12px; overflow: hidden; }
    th, td { padding: 12px 8px; border-bottom: 1px solid var(--border); text-align: left; font-size: 13px; }
    th { background: rgba(15, 23, 42, 0.5); color: var(--primary); font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
    td { color: var(--text-dim); }
    td strong { color: var(--text); }
    input, select { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 10px; margin: 5px 0; background: #0f172a; color: white; }
    .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
    .online { background: var(--success); box-shadow: 0 0 10px var(--success); }
    .offline { background: #475569; }
    .col-check { display: none; text-align: center; }
    .mass-bar { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: #1e293b; color: white; padding: 25px; z-index: 1000; border-radius: 24px 24px 0 0; box-shadow: 0 -10px 25px rgba(0,0,0,0.5); border-top: 1px solid var(--primary); }
    #modalEdit { display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); background: var(--card); padding:25px; border-radius:20px; border:1px solid var(--primary); z-index:1100; width: 90%; max-width: 350px; box-shadow: 0 0 40px rgba(0,0,0,0.6); }
    .shield-icon { color: #22d3ee; }
    .shield-manual { color: var(--warning) !important; filter: drop-shadow(0 0 5px rgba(245, 158, 11, 0.5)); animation: pulse-gold 2s infinite; }
    @keyframes pulse-gold { 0% { transform: scale(1); } 50% { transform: scale(1.15); opacity: 0.8; } 100% { transform: scale(1); } }
    .shield-switch { position: relative; display: inline-block; width: 36px; height: 18px; }
    .shield-switch input { opacity: 0; width: 0; height: 0; }
    .shield-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #334155; transition: .3s; border-radius: 20px; }
    .shield-slider:before { position: absolute; content: ""; height: 12px; width: 12px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; }
    input:checked + .shield-slider { background-color: var(--warning); }
    input:checked + .shield-slider:before { transform: translateX(18px); }
    .shield-box { display: flex; align-items: center; gap: 6px; }
    tr.loading { opacity: 0.3; pointer-events: none; }
    details summary { background: var(--card); padding: 12px; border-radius: 10px; cursor: pointer; border: 1px solid var(--border); font-weight: bold; color: var(--primary); }
</style>
</head>
<body>
    <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
        <a href="index.php" class="btn btn-gray"><i class="fas fa-home"></i></a>
        <div id="status-sync" style="font-size:10px; color:#666; align-self:center; background:white; padding:4px 8px; border-radius:15px;">
             <i class="fas fa-bolt"></i> <span id="syncText">MODO LIVE</span>
        </div>
        <b style="color:var(--primary);">Día <?php echo $dia_actual; ?></b>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px; margin-bottom: 8px;">
        <div class="card" style="text-align:center; border-bottom: 3px solid var(--success);">
            <small style="font-size:9px;">ACTIVOS</small><br>
            <b style="color:var(--success)"><?php echo $conn->query("SELECT id FROM abonados WHERE estado='activo'")->num_rows; ?></b>
        </div>
        <div class="card" style="text-align:center; border-bottom: 3px solid var(--danger);">
            <small style="font-size:9px;">CORTADOS</small><br>
            <b style="color:var(--danger)"><?php echo $conn->query("SELECT id FROM abonados WHERE estado='cortado'")->num_rows; ?></b>
        </div>
        <div class="card" style="text-align:center; border-bottom: 3px solid var(--warning);">
            <small style="font-size:9px;">VENCEN</small><br>
            <b style="color:var(--warning)"><?php echo $conn->query("SELECT id FROM abonados WHERE dia_pago=$dia_actual")->num_rows; ?></b>
        </div>
    </div>

    <details class="card">
        <summary>+ REGISTRAR ABONADO</summary>
        <form method="POST" style="margin-top:10px;">
            <input type="text" name="user" placeholder="Usuario Mikrotik" required>
            <input type="text" name="pass" placeholder="Contraseña PPP" required> 
            
            <select name="plan">
                <?php $p_res = $conn->query("SELECT * FROM planes"); while($p = $p_res->fetch_assoc()) { echo "<option value='".$p['perfil_mikrotik']."'>".$p['nombre_visible']."</option>"; } ?>
            </select>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                <input type="number" name="dia_pago" placeholder="Día" required>
                <input type="time" name="hora_corte" value="08:00" required>
            </div>
            <button type="submit" name="crear_usuario" class="btn btn-blue" style="width:100%; justify-content:center; margin-top:5px;">GUARDAR</button>
        </form>
    </details>

    <div style="display:flex; gap:5px; margin: 8px 0;">
        <input type="text" id="busqueda" placeholder="🔍 Buscar..." style="flex-grow:1; margin:0;">
        <form method="POST" style="display:inline;"><button type="submit" name="sincronizar_ahora" class="btn btn-blue"><i class="fas fa-sync"></i></button></form>
        <button type="button" onclick="activarModoMasivo()" id="btnM" class="btn btn-orange"><b>M</b></button>
    </div>

    <form method="POST">
        <div id="barraMasiva" class="mass-bar">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <b id="contador" style="color:var(--warning)">0 seleccionados</b>
                <button type="button" onclick="cancelarMasivo()" style="background:none; border:none; color:white; font-size:24px;">&times;</button>
            </div>
            <select name="plan_masivo">
                <option value="">-- Cambiar Plan --</option>
                <?php $p_res2 = $conn->query("SELECT * FROM planes"); while($p2 = $p_res2->fetch_assoc()) { echo "<option value='".$p2['perfil_mikrotik']."'>".$p2['nombre_visible']."</option>"; } ?>
            </select>
            <button type="submit" name="accion_masiva" value="cambiar_plan" class="btn btn-orange" style="width:100%; justify-content:center; margin-bottom:10px;">APLICAR PLAN</button>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                <button type="submit" name="accion_masiva" value="activar" class="btn" style="background:var(--success); justify-content:center;">ACTIVA</button>
                <button type="submit" name="accion_masiva" value="cortar" class="btn" style="background:var(--danger); justify-content:center;">CORTA</button>
            </div>
        </div>

        <div class="card" style="padding:0; overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th class="col-check"><input type="checkbox" id="checkAll"></th>
                        <th>CLIENTE</th>
                        <th>ESCUDO</th> 
                        <th>ESTADO</th>
                        <th>VENCE</th>
                        <th>OPC</th>
                    </tr>
                </thead>
                <tbody id="tablaBody">
                    <?php while($r = $clientes->fetch_assoc()): 
                        $u = $r['user_mikrotik']; $online = isset($ips_online[$u]);
                        $manual = ($r['proteccion_manual'] == 1);
                        $auto = ($r['proteccion'] == 1 && !$manual);
                    ?>
                    <tr id="fila-<?php echo $u; ?>">
                        <td class="col-check"><input type="checkbox" name="usuarios_sel[]" value="<?php echo $u; ?>" class="user-check" onchange="actualizarBarra()"></td>
                        <td>
                            <b><?php echo $u; ?></b><br>
                            <small style="color:#64748b"><?php echo str_replace('PPPoeClientes-', '', $r['plan']); ?></small>
                        </td>
                        <td>
                            <div class="shield-box">
                                <label class="shield-switch">
                                    <input type="checkbox" <?php echo ($manual) ? 'checked' : ''; ?> 
                                           onclick="ejecutarAccionLive('config.php?accion=toggle_inmunidad&user=<?php echo $u; ?>', this)">
                                    <span class="shield-slider"></span>
                                </label>
                                <span class="shield-place">
                                    <?php if($manual): ?>
                                        <i class="fas fa-shield-alt shield-manual"></i>
                                    <?php elseif($auto): ?>
                                        <i class="fas fa-shield-alt shield-icon"></i>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </td>
                        <td class="td-estado">
                            <div style="display:flex; align-items:center; gap:8px;" id="container-<?php echo $u; ?>">
                                <?php if($r['estado'] == 'activo'): ?>
                                    <a href="javascript:void(0);" onclick="ejecutarAccionLive('config.php?accion=cortar&user=<?php echo $u; ?>', this)" style="font-size:24px; color:var(--success)" class="btn-toggle"><i class="fas fa-toggle-on"></i></a>
                                <?php else: ?>
                                    <a href="javascript:void(0);" onclick="ejecutarAccionLive('config.php?accion=activar_directo&user=<?php echo $u; ?>', this)" style="font-size:24px; color:var(--danger)" class="btn-toggle"><i class="fas fa-toggle-off"></i></a>
                                <?php endif; ?>
                                <span class="status-dot <?php echo ($online?'online':'offline'); ?>"></span>
                            </div>
                        </td>
                        <td>
                            <b><?php echo $r['dia_pago']; ?></b><br>
                            <small style="font-size:9px; color:var(--primary)"><?php echo date("h:i A", strtotime($r['hora_corte'])); ?></small>
                        </td>
                        <td>
                            <div style="display:flex; gap:10px;">
                                <button type="button" onclick="abrirEdit('<?php echo $u; ?>', '<?php echo $r['dia_pago']; ?>', '<?php echo $r['hora_corte']; ?>')" style="background:none; border:none; color:var(--warning); font-size:18px;"><i class="fas fa-edit"></i></button>
                                <a href="config.php?delete_user=<?php echo $u; ?>" onclick="return confirm('¿Seguro?')" style="color:var(--danger);"><i class="fas fa-trash-alt"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </form>
    
    <div id="modalEdit">
        <h4 id="userTit" style="margin:0 0 10px 0;"></h4>
        <form method="POST">
            <input type="hidden" name="user_edit" id="userInp">
            <label style="font-size:12px;">Plan:</label>
            <select name="nuevo_plan">
                <?php $p_res3 = $conn->query("SELECT * FROM planes"); while($p3 = $p_res3->fetch_assoc()) { echo "<option value='".$p3['perfil_mikrotik']."'>".$p3['nombre_visible']."</option>"; } ?>
            </select>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                <div>
                    <label style="font-size:12px;">Día:</label>
                    <input type="number" name="nuevo_dia" id="diaInp">
                </div>
                <div>
                    <label style="font-size:12px;">Hora:</label>
                    <input type="time" name="nueva_hora" id="horaInp">
                </div>
            </div>
            <button type="submit" name="editar_cliente" class="btn btn-blue" style="width:100%; justify-content:center; margin-top:10px;">ACTUALIZAR</button>
            <button type="button" onclick="document.getElementById('modalEdit').style.display='none'" class="btn btn-gray" style="width:100%; justify-content:center; margin-top:5px;">CERRAR</button>
        </form>
    </div>

    <script>
        function ejecutarAccionLive(url, elemento) {
            const fila = elemento.closest('tr');
            fila.classList.add('loading');
            fetch(url + "&ajax=1").then(() => {
                monitorDeEstados();
                fila.classList.remove('loading');
            });
        }

        function monitorDeEstados() {
            fetch('config.php?get_status_live=1')
            .then(res => res.json())
            .then(data => {
                for (const user in data) {
                    const fila = document.getElementById('fila-' + user);
                    if (!fila) continue;
                    const info = data[user]; 
                    const container = document.getElementById('container-' + user);
                    const dot = fila.querySelector('.status-dot');
                    const dotClass = dot.classList.contains('online') ? 'online' : 'offline';

                    if (info.estado === 'activo') {
                        container.innerHTML = `
                            <a href="javascript:void(0);" onclick="ejecutarAccionLive('config.php?accion=cortar&user=${user}', this)" style="font-size:24px; color:var(--success)" class="btn-toggle"><i class="fas fa-toggle-on"></i></a>
                            <span class="status-dot ${dotClass}"></span>`;
                    } else {
                        container.innerHTML = `
                            <a href="javascript:void(0);" onclick="ejecutarAccionLive('config.php?accion=activar_directo&user=${user}', this)" style="font-size:24px; color:var(--danger)" class="btn-toggle"><i class="fas fa-toggle-off"></i></a>
                            <span class="status-dot ${dotClass}"></span>`;
                    }
                }
                document.getElementById('syncText').innerHTML = 'LIVE OK';
            });
        }

        setInterval(monitorDeEstados, 3000);

        function activarModoMasivo() { document.querySelectorAll('.col-check').forEach(el => el.style.display = 'table-cell'); document.getElementById('btnM').style.display = 'none'; }
        function cancelarMasivo() { document.querySelectorAll('.user-check').forEach(c => c.checked = false); document.querySelectorAll('.col-check').forEach(el => el.style.display = 'none'); document.getElementById('btnM').style.display = 'inline-flex'; document.getElementById('barraMasiva').style.display = 'none'; }
        function actualizarBarra() { let n = document.querySelectorAll('.user-check:checked').length; document.getElementById('barraMasiva').style.display = n > 0 ? 'block' : 'none'; document.getElementById('contador').innerText = n + " seleccionados"; }
        document.getElementById('checkAll').onclick = function() { document.querySelectorAll('.user-check').forEach(c => c.checked = this.checked); actualizarBarra(); }
        
        document.getElementById('busqueda').onkeyup = function() { 
            let f = this.value.toLowerCase(); 
            document.querySelectorAll('#tablaBody tr').forEach(tr => { 
                tr.style.display = tr.innerText.toLowerCase().includes(f) ? '' : 'none'; 
            }); 
        };
        
        function abrirEdit(u, d, h) { 
            document.getElementById('modalEdit').style.display = 'block'; 
            document.getElementById('userTit').innerText = "Editar: " + u; 
            document.getElementById('userInp').value = u; 
            document.getElementById('diaInp').value = d; 
            document.getElementById('horaInp').value = h; 
        }
    </script>
</body>
</html>