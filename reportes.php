<?php
// --- 1. SEGURIDAD Y CONFIGURACIÓN ---
require_once "config_global.php"; 

// Zona horaria para reportes precisos en Venezuela
date_default_timezone_set('America/Caracas'); 

// --- 2. CÁLCULOS DE CAJA ---
$total_res = $conn->query("SELECT SUM(monto) as total FROM pagos");
$total_caja = $total_res->fetch_assoc()['total'] ?? 0;

$hoy_res = $conn->query("SELECT SUM(monto) as total_hoy FROM pagos WHERE DATE(fecha_pago) = CURDATE()");
$total_hoy = $hoy_res->fetch_assoc()['total_hoy'] ?? 0;

// --- 3. CONSULTA DE TABLA ---
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
            /* Mantenemos tu paleta de colores Pro-Dark Light */
            --bg-body: #020617; --bg-card: #0f172a; --primary: #3b82f6;
            --success: #10b981; --danger: #ef4444; --text-main: #f8fafc; --text-dim: #64748b;
            --border: rgba(255,255,255,0.05);
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg-body); color: var(--text-main); margin: 0; padding: 20px; }
        
        /* Navegación Circular Superior */
        .header-nav { display: flex; gap: 10px; margin-bottom: 25px; }
        .btn-nav { flex: 1; display: flex; align-items: center; justify-content: center; gap: 8px; padding: 12px; border-radius: 12px; text-decoration: none; font-weight: 700; font-size: 0.8rem; transition: 0.3s; }
        .bg-blue { background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3); }
        .bg-dark { background: #1e293b; color: var(--text-dim); border: 1px solid var(--border); }
        .bg-dark:hover { color: white; border-color: var(--primary); }

        /* Tarjetas con Glassmorphism */
        .stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 25px; }
        .stat-card { 
            background: rgba(15, 23, 42, 0.6); 
            backdrop-filter: blur(10px); 
            padding: 20px; 
            border-radius: 20px; 
            text-align: center; 
            border: 1px solid var(--border);
            transition: 0.3s;
        }
        .stat-card:hover { transform: translateY(-5px); border-color: var(--success); }
        .stat-card .value { font-size: 1.6rem; font-weight: 900; color: var(--success); display: block; }
        .stat-card label { font-size: 0.65rem; color: var(--text-dim); text-transform: uppercase; font-weight: 800; letter-spacing: 1px; }

        /* Contenedor de Tabla */
        .table-section { background: var(--bg-card); border-radius: 24px; padding: 10px; border: 1px solid var(--border); }
        .list-header { padding: 15px; font-size: 0.75rem; font-weight: 800; color: var(--text-dim); text-transform: uppercase; display: flex; align-items: center; gap: 8px; }
        
        table { width: 100%; border-collapse: collapse; }
        td { padding: 15px; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
        tr:last-child td { border-bottom: none; }
        tr:hover { background: rgba(255,255,255,0.01); }
        
        .client-name { font-weight: 700; color: var(--primary); display: block; }
        .pay-date { font-size: 0.7rem; color: var(--text-dim); }
        .method-tag { font-size: 0.6rem; background: rgba(255,255,255,0.05); padding: 4px 8px; border-radius: 6px; border: 1px solid var(--border); text-transform: uppercase; color: var(--text-dim); }
    </style>
</head>
<body>

    <div class="header-nav">
        <a href="index.php" class="btn-nav bg-blue">
            <i class="fas fa-th-large"></i> DASHBOARD
        </a>
        <a href="config.php" class="btn-nav bg-dark">
            <i class="fas fa-users-cog"></i> PANEL
        </a>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <label>Recaudado Hoy</label>
            <span class="value">$<?php echo number_format($total_hoy, 2); ?></span>
        </div>
        <div class="stat-card" style="border-color: var(--primary);">
            <label>Total Global</label>
            <span class="value" style="color: var(--primary);">$<?php echo number_format($total_caja, 2); ?></span>
        </div>
    </div>

    <div class="table-section">
        <div class="list-header"><i class="fas fa-history"></i> Historial de Cobranza</div>
        <table>
            <tbody>
                <?php while($p = $historial->fetch_assoc()): ?>
                <tr>
                    <td>
                        <span class="client-name"><?php echo $p['user_mikrotik']; ?></span>
                        <span class="pay-date"><?php echo date('d M, h:i A', strtotime($p['fecha_pago'])); ?></span>
                    </td>
                    <td style="text-align: right;">
                        <div style="font-weight: 800; color: var(--success);">$<?php echo number_format($p['monto'], 2); ?></div>
                        <span class="method-tag"><?php echo $p['metodo']; ?></span>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

</body>
</html>