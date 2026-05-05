<?php
// --- 1. NÚCLEO Y SEGURIDAD ---
require_once "config_global.php";

// EL PORTERO: Si no hay sesión iniciada, lo pateamos al login
if (!isset($_SESSION['admin_auth'])) {
    header("Location: login.php");
    exit();
}

$hoy = date('Y-m-d');
$mes_actual = date('m');
$ano_actual = date('Y');

// --- 2. CÁLCULO DE CAJA DEL DÍA ---
$res_caja = $conn->query("SELECT 
    SUM(monto) as total_dia,
    SUM(CASE WHEN metodo = 'Efectivo' THEN monto ELSE 0 END) as efectivo,
    SUM(CASE WHEN metodo = 'Pago Movil' THEN monto ELSE 0 END) as pagomovil,
    SUM(CASE WHEN metodo = 'Zelle' THEN monto ELSE 0 END) as zelle
    FROM pagos WHERE DATE(fecha_pago) = '$hoy'");
$caja = $res_caja->fetch_assoc();

// --- 3. DATOS PARA GRÁFICA DE TENDENCIA (MES COMPLETO) ---
$res_tendencia = $conn->query("SELECT DAY(fecha_pago) as dia, SUM(monto) as total 
    FROM pagos 
    WHERE MONTH(fecha_pago) = '$mes_actual' AND YEAR(fecha_pago) = '$ano_actual' 
    GROUP BY DAY(fecha_pago) 
    ORDER BY dia ASC");

$dias_labels = [];
$montos_data = [];

// Rellenamos el mes completo con 0s por defecto
$dias_en_mes = date('t');
for ($i = 1; $i <= $dias_en_mes; $i++) {
    $dias_labels[] = "Día $i";
    $montos_data[$i] = 0;
}

// Sobrescribimos los días que sí tienen pagos
while($t = $res_tendencia->fetch_assoc()){
    $montos_data[(int)$t['dia']] = (float)$t['total'];
}
$montos_data = array_values($montos_data); // Reindexar para el JSON de la gráfica

// --- 4. META DE COBRANZA REGRESIVA (MES ACTUAL) ---
$query_esperado = $conn->query("SELECT SUM(
    CASE 
        WHEN plan LIKE '%Low%' THEN 10
        WHEN plan LIKE '%Basic%' THEN 15
        WHEN plan LIKE '%Medium%' THEN 20
        ELSE 0 
    END
) as total_esperado FROM abonados");
$esperado_row = $query_esperado->fetch_assoc();
$meta_mensual = (float)($esperado_row['total_esperado'] ?? 0);

$query_recaudado = $conn->query("SELECT SUM(monto) as total_recaudado FROM pagos WHERE MONTH(fecha_pago) = '$mes_actual' AND YEAR(fecha_pago) = '$ano_actual'");
$recaudado_row = $query_recaudado->fetch_assoc();
$recaudado_mensual = (float)($recaudado_row['total_recaudado'] ?? 0);

$pendiente_mensual = max(0, $meta_mensual - $recaudado_mensual);
$porcentaje_cobro = ($meta_mensual > 0) ? round(($recaudado_mensual / $meta_mensual) * 100) : 0;

// --- 5. LISTADO DE PAGOS RECIENTES ---
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
            --success: #10b981; --warning: #fbbf24; --danger: #ef4444;
            --text-main: #f8fafc; --text-dim: #64748b; --border: rgba(255,255,255,0.05);
        }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg-body); color: var(--text-main); padding: 20px; margin: 0; }

        .header-caja { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .btn-back { text-decoration: none; color: var(--text-dim); font-size: 0.9rem; font-weight: 600; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
        .btn-back:hover { color: var(--primary); }

        /* Grid de Tarjetas Diarias */
        .grid-caja { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .card-money { background: var(--bg-card); padding: 20px; border-radius: 24px; border: 1px solid var(--border); text-align: center; position: relative; overflow: hidden; }
        .card-money i { font-size: 1.2rem; margin-bottom: 10px; display: block; color: var(--primary); opacity: 0.8; }
        .card-money .monto { font-size: 1.8rem; font-weight: 900; color: var(--success); display: block; letter-spacing: -1px; }
        .card-money label { font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase; font-weight: 800; letter-spacing: 1px; }

        /* Módulo de Caja Fuerte Regresiva */
        .caja-fuerte-panel { background: linear-gradient(180deg, #0f172a 0%, #020617 100%); border-radius: 24px; padding: 20px; border: 1px solid var(--primary); margin-bottom: 25px; }
        .caja-fuerte-title { font-size: 0.85rem; font-weight: 800; color: #fff; margin-bottom: 15px; text-transform: uppercase; display: flex; align-items: center; gap: 10px; }
        
        .caja-resumen { display: flex; justify-content: space-between; gap: 10px; }
        .caja-resumen div { flex: 1; text-align: center; background: rgba(0,0,0,0.3); padding: 15px; border-radius: 16px; border: 1px solid var(--border); }
        .caja-resumen span { display: block; font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase; font-weight: 800; margin-bottom: 5px; }
        .caja-resumen b { font-size: 1.5rem; font-weight: 900; }

        .progress-container { width: 100%; background: rgba(255,255,255,0.05); border-radius: 12px; height: 22px; margin-top: 15px; overflow: hidden; border: 1px solid var(--border); }
        .progress-bar { height: 100%; background: linear-gradient(90deg, var(--danger), var(--warning), var(--success)); transition: width 0.8s ease-in-out; display: flex; align-items: center; justify-content: flex-end; padding-right: 10px; font-size: 11px; font-weight: 900; color: #000; }

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

        @media (max-width: 600px) {
            .caja-resumen { flex-direction: column; }
        }
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

    <div class="caja-fuerte-panel">
        <div class="caja-fuerte-title">
            <i class="fas fa-bullseye" style="color: var(--primary); font-size: 1.2rem;"></i> 
            Meta de Cobranza (Mes de <?php 
                $meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre']; 
                echo $meses[date('n')-1]; 
            ?>)
        </div>
        
        <div class="caja-resumen">
            <div>
                <span>Proyección Total</span>
                <b style="color: #f8fafc;">$<?php echo number_format($meta_mensual, 2); ?></b>
            </div>
            <div style="border-bottom: 3px solid var(--success);">
                <span>Recaudado a la Fecha</span>
                <b style="color: var(--success);">$<?php echo number_format($recaudado_mensual, 2); ?></b>
            </div>
            <div style="border-bottom: 3px solid var(--warning);">
                <span>Pendiente en la Calle</span>
                <b style="color: var(--warning);">$<?php echo number_format($pendiente_mensual, 2); ?></b>
            </div>
        </div>

        <div class="progress-container">
            <div class="progress-bar" style="width: <?php echo $porcentaje_cobro; ?>%;">
                <?php echo ($porcentaje_cobro > 5) ? $porcentaje_cobro . '%' : ''; ?>
            </div>
        </div>
    </div>

    <div class="charts-grid">
        <div class="panel-box">
            <div class="panel-title"><i class="fas fa-chart-line" style="color:var(--success)"></i> Tendencia de Ingresos (Mes Completo)</div>
            <div class="chart-container">
                <canvas id="chartTendencia"></canvas>
            </div>
        </div>

        <div class="panel-box">
            <div class="panel-title"><i class="fas fa-chart-pie" style="color:var(--warning)"></i> Métodos (Hoy)</div>
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
        // --- CONFIGURACIÓN DE GRÁFICA DE TENDENCIA (MES COMPLETO) ---
        const ctxLine = document.getElementById('chartTendencia').getContext('2d');
        new Chart(ctxLine, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($dias_labels); ?>,
                datasets: [{
                    label: 'Ingresos Diarios ($)',
                    data: <?php echo json_encode($montos_data); ?>,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.3,
                    pointBackgroundColor: '#fbbf24',
                    pointBorderColor: '#0f172a',
                    pointRadius: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { 
                        grid: { display: false }, 
                        ticks: { color: '#64748b', maxTicksLimit: 15 } 
                    },
                    y: { 
                        grid: { color: 'rgba(255,255,255,0.05)' }, 
                        ticks: { color: '#f8fafc', font: { weight: 'bold' } } 
                    }
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