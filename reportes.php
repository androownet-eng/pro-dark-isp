<?php
// --- 1. NÚCLEO Y SEGURIDAD ---
require_once "config_global.php";

// EL PORTERO: Si no hay sesión iniciada, lo pateamos al login
if (!isset($_SESSION['admin_auth'])) {
    header("Location: login.php");
    exit();
}

// --- 2. CÁLCULO DE CAJA DEL DÍA ---
$hoy = date('Y-m-d');
$res_caja = $conn->query("SELECT 
    SUM(monto) as total_dia,
    SUM(CASE WHEN metodo = 'Efectivo' THEN monto ELSE 0 END) as efectivo,
    SUM(CASE WHEN metodo = 'Pago Movil' THEN monto ELSE 0 END) as pagomovil,
    SUM(CASE WHEN metodo = 'Zelle' THEN monto ELSE 0 END) as zelle
    FROM pagos WHERE DATE(fecha_pago) = '$hoy'");
$caja = $res_caja->fetch_assoc();

// --- 3. DATOS PARA GRÁFICA DE TENDENCIA (Últimos 7 días) ---
$recaudacion_7d = $conn->query("SELECT DATE(fecha_pago) as dia, SUM(monto) as total 
    FROM pagos 
    WHERE fecha_pago >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(fecha_pago) 
    ORDER BY dia ASC");

$dias_labels = [];
$montos_data = [];
while($r = $recaudacion_7d->fetch_assoc()){
    $dias_labels[] = date("d/m", strtotime($r['dia']));
    $montos_data[] = (float)$r['total'];
}

// --- 4. LISTADO DE PAGOS RECIENTES ---
$pagos_recientes = $conn->query("SELECT * FROM pagos ORDER BY fecha_pago DESC LIMIT 20");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pro-Dark ISP | Control de Caja</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg-body: #020617; --bg-card: #0f172a; --primary: #3b82f6;
            --success: #10b981; --warning: #fbbf24; --text-main: #f8fafc; 
            --text-dim: #64748b; --border: rgba(255,255,255,0.05);
        }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg-body); color: var(--text-main); padding: 20px; margin: 0; }
        
        .header-caja { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .btn-back { text-decoration: none; color: var(--text-dim); font-size: 0.9rem; font-weight: 600; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
        .btn-back:hover { color: var(--primary); }

        /* Grid de Tarjetas */
        .grid-caja { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .card-money { background: var(--bg-card); padding: 20px; border-radius: 24px; border: 1px solid var(--border); text-align: center; position: relative; overflow: hidden; }
        .card-money i { font-size: 1.2rem; margin-bottom: 10px; display: block; color: var(--primary); opacity: 0.8; }
        .card-money .monto { font-size: 1.8rem; font-weight: 900; color: var(--success); display: block; letter-spacing: -1px; }
        .card-money label { font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase; font-weight: 800; letter-spacing: 1px; }

        /* Layout de Gráficas */
        .charts-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 25px; }
        @media (max-width: 900px) { .charts-grid { grid-template-columns: 1fr; } }
        
        .panel-box { background: var(--bg-card); border-radius: 24px; padding: 20px; border: 1px solid var(--border); }
        .panel-title { font-size: 0.75rem; font-weight: 800; color: var(--text-dim); margin-bottom: 20px; text-transform: uppercase; display: flex; align-items: center; gap: 10px; }
        .chart-container { position: relative; height: 280px; width: 100%; }

        /* Tabla Estilizada */
        .tabla-box { background: var(--bg-card); border-radius: 24px; padding: 20px; border: 1px solid var(--border); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 500px; }
        th { text-align: left; padding: 15px; color: var(--text-dim); font-size: 0.7rem; text-transform: uppercase; border-bottom: 1px solid var(--border); }
        td { padding: 15px; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
        tr:hover { background: rgba(255,255,255,0.02); }
        
        .metodo-tag { padding: 4px 10px; border-radius: 8px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; }
        .tag-efectivo { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .tag-pagomovil { background: rgba(59, 130, 246, 0.1); color: var(--primary); }
        .tag-zelle { background: rgba(251, 191, 36, 0.1); color: var(--warning); }
    </style>
</head>
<body>

    <div class="header-caja">
        <a href="index.php" class="btn-back"><i class="fas fa-chevron-left"></i> Panel Principal</a>
        <h2 style="margin:0; font-weight: 900; letter-spacing: -1px;">FINANZAS <span style="color:var(--primary)">PRO-DARK</span></h2>
    </div>

    <div class="grid-caja">
        <div class="card-money" style="border-bottom: 3px solid var(--primary);">
            <i class="fas fa-vault"></i>
            <span class="monto">$<?php echo number_format($caja['total_dia'] ?? 0, 2); ?></span>
            <label>Recaudación Hoy</label>
        </div>
        <div class="card-money">
            <i class="fas fa-money-bill-1-wave"></i>
            <span class="monto">$<?php echo number_format($caja['efectivo'] ?? 0, 2); ?></span>
            <label>Efectivo</label>
        </div>
        <div class="card-money">
            <i class="fas fa-mobile-screen-button"></i>
            <span class="monto">$<?php echo number_format($caja['pagomovil'] ?? 0, 2); ?></span>
            <label>Pago Móvil</label>
        </div>
        <div class="card-money">
            <i class="fas fa-building-columns"></i>
            <span class="monto">$<?php echo number_format($caja['zelle'] ?? 0, 2); ?></span>
            <label>Zelle</label>
        </div>
    </div>

    <div class="charts-grid">
        <div class="panel-box">
            <div class="panel-title"><i class="fas fa-chart-line" style="color:var(--success)"></i> Tendencia de Ingresos (7 Días)</div>
            <div class="chart-container">
                <canvas id="chartTendencia"></canvas>
            </div>
        </div>

        <div class="panel-box">
            <div class="panel-title"><i class="fas fa-chart-pie" style="color:var(--warning)"></i> Distribución de Métodos</div>
            <div class="chart-container">
                <canvas id="chartMetodos"></canvas>
            </div>
        </div>
    </div>

    <div class="tabla-box">
        <div class="panel-title"><i class="fas fa-receipt"></i> Auditoría de Pagos Recientes</div>
        <table>
            <thead>
                <tr>
                    <th>Abonado</th>
                    <th>Monto</th>
                    <th>Método</th>
                    <th>Fecha y Hora</th>
                </tr>
            </thead>
            <tbody>
                <?php while($p = $pagos_recientes->fetch_assoc()): 
                    $clase_tag = 'tag-efectivo';
                    if($p['metodo'] == 'Pago Movil') $clase_tag = 'tag-pagomovil';
                    if($p['metodo'] == 'Zelle') $clase_tag = 'tag-zelle';
                ?>
                <tr>
                    <td style="font-weight: 700; color: var(--text-main);"><?php echo htmlspecialchars($p['user_mikrotik']); ?></td>
                    <td style="color: var(--success); font-weight: 800;">$<?php echo number_format($p['monto'], 2); ?></td>
                    <td><span class="metodo-tag <?php echo $clase_tag; ?>"><?php echo $p['metodo']; ?></span></td>
                    <td style="color: var(--text-dim);"><?php echo date("d/m/Y | H:i", strtotime($p['fecha_pago'])); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <script>
        // --- CONFIGURACIÓN DE GRÁFICA DE TENDENCIA ---
        const ctxLine = document.getElementById('chartTendencia').getContext('2d');
        new Chart(ctxLine, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($dias_labels); ?>,
                datasets: [{
                    label: 'Ingresos ($)',
                    data: <?php echo json_encode($montos_data); ?>,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 4,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#3b82f6',
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#64748b' } },
                    y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#64748b' } }
                }
            }
        });

        // --- CONFIGURACIÓN DE GRÁFICA DE MÉTODOS ---
        const ctxDoughnut = document.getElementById('chartMetodos').getContext('2d');
        new Chart(ctxDoughnut, {
            type: 'doughnut',
            data: {
                labels: ['Efectivo', 'Pago Móvil', 'Zelle'],
                datasets: [{
                    data: [
                        <?php echo $caja['efectivo'] ?? 0; ?>, 
                        <?php echo $caja['pagomovil'] ?? 0; ?>, 
                        <?php echo $caja['zelle'] ?? 0; ?>
                    ],
                    backgroundColor: ['#10b981', '#3b82f6', '#fbbf24'],
                    hoverOffset: 10,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: '#f8fafc', font: { size: 11, weight: 'bold' }, padding: 20 }
                    }
                },
                cutout: '70%'
            }
        });
    </script>

</body>
</html>