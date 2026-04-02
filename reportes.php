<?php
// --- 1. SEGURIDAD ---
session_start();
if (!isset($_SESSION['admin_auth'])) {
    header("Location: login.php");
    exit();
}

// --- 2. CONEXIÓN ---
$conn = new mysqli("localhost", "root", "", "isp_gestion");

// --- 3. CÁLCULOS DE CAJA ---
// Suma total de todos los pagos
$total_res = $conn->query("SELECT SUM(monto) as total FROM pagos");
$total_caja = $total_res->fetch_assoc()['total'] ?? 0;

// Pagos del día de hoy
$hoy = date('Y-m-d');
$hoy_res = $conn->query("SELECT SUM(monto) as total_hoy FROM pagos WHERE DATE(fecha_pago) = '$hoy'");
$total_hoy = $hoy_res->fetch_assoc()['total_hoy'] ?? 0;

// --- 4. CONSULTA DE TABLA ---
$historial = $conn->query("SELECT * FROM pagos ORDER BY fecha_pago DESC LIMIT 100");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes de Cobranza</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg: #0f172a; --card: #1e293b; --primary: #3b82f6;
            --success: #10b981; --text: #f8fafc; --dim: #94a3b8; --border: #334155;
        }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 20px; }
        
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .back-btn { text-decoration: none; color: var(--primary); font-weight: bold; display: flex; align-items: center; gap: 8px; }

        /* Tarjetas de Resumen */
        .stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 25px; }
        .stat-card { background: var(--card); padding: 20px; border-radius: 15px; border: 1px solid var(--border); text-align: center; }
        .stat-card .value { font-size: 1.8rem; font-weight: bold; color: var(--success); display: block; }
        .stat-card .label { font-size: 0.8rem; color: var(--dim); text-transform: uppercase; margin-top: 5px; }

        /* Tabla de Reportes */
        .table-container { background: var(--card); border-radius: 15px; border: 1px solid var(--border); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        th { background: rgba(59, 130, 246, 0.1); color: var(--primary); text-align: left; padding: 15px; border-bottom: 1px solid var(--border); }
        td { padding: 15px; border-bottom: 1px solid var(--border); color: var(--text); }
        tr:last-child td { border-bottom: none; }
        
        .metodo-badge { background: #0f172a; padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; border: 1px solid var(--border); }
        .fecha { color: var(--dim); font-size: 0.8rem; }
    </style>
</head>
<body>

    <div class="header">
        <a href="index.php" class="back-btn"><i class="fas fa-arrow-left"></i> Volver</a>
        <h2 style="margin:0;"><i class="fas fa-file-invoice-dollar"></i> Reporte de Pagos</h2>
        <div style="width: 50px;"></div> </div>

    <div class="stats-grid">
        <div class="stat-card">
            <span class="value">$<?php echo number_format($total_hoy, 2); ?></span>
            <span class="label">Cobrado Hoy</span>
        </div>
        <div class="stat-card">
            <span class="value">$<?php echo number_format($total_caja, 2); ?></span>
            <span class="label">Total Histórico</span>
        </div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Monto</th>
                    <th>Método</th>
                    <th>Fecha / Hora</th>
                </tr>
            </thead>
            <tbody>
                <?php if($historial->num_rows > 0): ?>
                    <?php while($p = $historial->fetch_assoc()): ?>
                    <tr>
                        <td><b><?php echo $p['user_mikrotik']; ?></b></td>
                        <td style="color: var(--success); font-weight: bold;">$<?php echo number_format($p['monto'], 2); ?></td>
                        <td><span class="metodo-badge"><?php echo $p['metodo']; ?></span></td>
                        <td class="fecha"><?php echo date('d/m/Y H:i', strtotime($p['fecha_pago'])); ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align:center; padding: 30px; color: var(--dim);">No hay pagos registrados aún.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</body>
</html>