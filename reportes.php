<?php
// --- 1. SEGURIDAD Y CONFIGURACIÓN ---
require_once "config_global.php"; // <--- CONEXIÓN CENTRALIZADA

// (Asumiendo que config_global.php ya tiene session_start() y la verificación de auth, 
// si no, lo dejamos aquí por seguridad)
if (!isset($_SESSION['admin_auth'])) {
    header("Location: login.php");
    exit();
}

// Zona horaria para reportes precisos en Venezuela
date_default_timezone_set('America/Caracas'); 

// --- 2. CÁLCULOS DE CAJA (Usamos la variable $conn de config_global.php) ---

// Suma total histórica
$total_res = $conn->query("SELECT SUM(monto) as total FROM pagos");
$total_caja = $total_res->fetch_assoc()['total'] ?? 0;

// Pagos del día de hoy (Optimizado con CURDATE())
$hoy = date('Y-m-d');
$hoy_res = $conn->query("SELECT SUM(monto) as total_hoy FROM pagos WHERE DATE(fecha_pago) = CURDATE()");
$total_hoy = $hoy_res->fetch_assoc()['total_hoy'] ?? 0;

// --- 3. CONSULTA DE TABLA ---
// Limitamos a 100 para no saturar el navegador si la base de datos crece mucho
$historial = $conn->query("SELECT user_mikrotik, monto, metodo, fecha_pago FROM pagos ORDER BY fecha_pago DESC LIMIT 100");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes Pro-Dark | Cobranza</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            /* Mantenemos tu paleta de colores Pro-Dark */
            --bg: #0f172a; --card: #1e293b; --primary: #3b82f6;
            --success: #10b981; --text: #f8fafc; --dim: #94a3b8; --border: #334155;
        }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 15px; }
        
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .back-btn { text-decoration: none; color: var(--primary); font-weight: bold; background: rgba(59, 130, 246, 0.1); padding: 8px 15px; border-radius: 10px; transition: 0.2s; }
        .back-btn:hover { background: var(--primary); color: white; }

        /* Tarjetas de Resumen */
        .stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px; }
        .stat-card { background: var(--card); padding: 15px; border-radius: 15px; border: 1px solid var(--border); text-align: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.2); }
        .stat-card .value { font-size: 1.5rem; font-weight: bold; color: var(--success); display: block; }
        .stat-card .label { font-size: 0.7rem; color: var(--dim); text-transform: uppercase; margin-top: 4px; letter-spacing: 1px; }

        /* Tabla Estilo Pro-Dark */
        .table-container { background: var(--card); border-radius: 15px; border: 1px solid var(--border); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 400px; }
        th { background: rgba(15, 23, 42, 0.5); color: var(--primary); text-align: left; padding: 12px; font-size: 0.8rem; border-bottom: 1px solid var(--border); }
        td { padding: 12px; border-bottom: 1px solid var(--border); font-size: 0.85rem; }
        tr:hover { background: rgba(255,255,255,0.02); }
        
        .metodo-badge { background: #0f172a; padding: 3px 7px; border-radius: 5px; font-size: 0.7rem; border: 1px solid var(--border); color: var(--dim); }
        .fecha { color: var(--dim); font-size: 0.75rem; display: block; }
    </style>
</head>
<body>

    <div class="header">
        <a href="config.php" class="back-btn"><i class="fas fa-chevron-left"></i> Panel</a>
        <h3 style="margin:0;"><i class="fas fa-receipt"></i> Historial</h3>
        <div style="width: 40px;"></div> 
    </div>

    <div class="stats-grid">
        <div class="stat-card" style="border-top: 3px solid var(--success);">
            <span class="value">$<?php echo number_format($total_hoy, 2); ?></span>
            <span class="label">Hoy</span>
        </div>
        <div class="stat-card" style="border-top: 3px solid var(--primary);">
            <span class="value">$<?php echo number_format($total_caja, 2); ?></span>
            <span class="label">Total Caja</span>
        </div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>CLIENTE</th>
                    <th>MONTO</th>
                    <th>INFO</th>
                </tr>
            </thead>
            <tbody>
                <?php if($historial->num_rows > 0): ?>
                    <?php while($p = $historial->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <b><?php echo $p['user_mikrotik']; ?></b>
                            <span class="fecha"><?php echo date('d/m/Y H:i', strtotime($p['fecha_pago'])); ?></span>
                        </td>
                        <td style="color: var(--success); font-weight: bold;">$<?php echo number_format($p['monto'], 2); ?></td>
                        <td><span class="metodo-badge"><?php echo $p['metodo']; ?></span></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3" style="text-align:center; padding: 40px; color: var(--dim);">No hay registros.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</body>
</html>