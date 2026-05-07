<?php
// app/views/informes/ventas.php

$titulo    = "Informe: Ventas por período";
$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/informes.css">';

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$db   = new Database();
$conn = $db->getConnection();

$esAdmin    = ($_SESSION['usuario_rol'] ?? '') === 'ADMIN';
if (!$esAdmin) { header('Location: /TYPSISTEMA/app/views/dashboard/index.php'); exit; }
$depUsuario = (int)($_SESSION['usuario_deposito'] ?? 1);

// Filtros con defaults
$desde       = $_GET['desde']       ?? date('Y-m-01');
$hasta       = $_GET['hasta']       ?? date('Y-m-d');
$tipoVenta   = $_GET['tipo_venta']  ?? '';
$estadoPago  = $_GET['estado_pago'] ?? '';
$filtroSuc   = $esAdmin ? (int)($_GET['sucursal'] ?? 0) : $depUsuario;

// Validar fechas
$reFecha = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($reFecha, $desde)) $desde = date('Y-m-01');
if (!preg_match($reFecha, $hasta)) $hasta = date('Y-m-d');

// Valores permitidos
$tiposValidos  = ['MINORISTA', 'MAYORISTA'];
$estadosValidos = ['PAGADA', 'PENDIENTE', 'PARCIAL', 'ANULADA'];
if (!in_array($tipoVenta,  $tiposValidos,  true)) $tipoVenta  = '';
if (!in_array($estadoPago, $estadosValidos, true)) $estadoPago = '';

// Sucursales (para el select de admin)
$sucursales = $conn->query("SELECT id, nombre FROM deposito ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

// Construir query
$params = [$desde, $hasta];
$types  = 'ss';
$where  = ["DATE(v.fecha_hora) BETWEEN ? AND ?"];

if (!$esAdmin) {
    $where[]  = "v.id_sucursal = ?";
    $params[] = $depUsuario;
    $types   .= 'i';
} elseif ($filtroSuc > 0) {
    $where[]  = "v.id_sucursal = ?";
    $params[] = $filtroSuc;
    $types   .= 'i';
}

if ($tipoVenta !== '') {
    $where[]  = "v.tipo_venta = ?";
    $params[] = $tipoVenta;
    $types   .= 's';
}

if ($estadoPago !== '') {
    $where[]  = "v.estado_pago = ?";
    $params[] = $estadoPago;
    $types   .= 's';
}

$sqlWhere = 'WHERE ' . implode(' AND ', $where);

$sql = "
    SELECT v.id, v.fecha_hora, v.tipo_venta, v.total, v.estado_pago,
           COALESCE(c.nombre, 'Sin cliente') AS cliente,
           u.nombre AS vendedor, d.nombre AS sucursal
    FROM ventas v
    LEFT JOIN clientes c ON c.id = v.id_cliente
    LEFT JOIN usuarios u ON u.id = v.id_usuario
    LEFT JOIN deposito d ON d.id = v.id_sucursal
    $sqlWhere
    ORDER BY v.fecha_hora DESC, v.id DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Totales
$sumaTotal = array_sum(array_column($rows, 'total'));

// ── CSV export — ANTES del ob_start ──
if (($_GET['formato'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="informe_ventas_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8
    fputcsv($out, ['N° Venta', 'Fecha', 'Cliente', 'Vendedor', 'Sucursal', 'Tipo', 'Total', 'Estado'], ';');
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['id'],
            date('d/m/Y H:i', strtotime($row['fecha_hora'])),
            $row['cliente'],
            $row['vendedor'] ?? '',
            $row['sucursal'] ?? '',
            $row['tipo_venta'],
            number_format((float)$row['total'], 2, '.', ''),
            $row['estado_pago'],
        ], ';');
    }
    fclose($out);
    exit;
}

// URL base para el botón CSV (conserva filtros actuales)
$csvUrl = '?desde=' . urlencode($desde)
        . '&hasta=' . urlencode($hasta)
        . '&tipo_venta='  . urlencode($tipoVenta)
        . '&estado_pago=' . urlencode($estadoPago)
        . '&sucursal='    . $filtroSuc
        . '&formato=csv';

function badgeEstado(string $estado): string {
    $map = [
        'PAGADA'    => 'badge-pagada',
        'PENDIENTE' => 'badge-pendiente',
        'PARCIAL'   => 'badge-parcial',
        'ANULADA'   => 'badge-anulada',
    ];
    $cls = $map[$estado] ?? '';
    return '<span class="badge ' . $cls . '">' . htmlspecialchars($estado) . '</span>';
}

ob_start();
?>
<div class="inf-container">

    <div class="inf-header">
        <h1 class="inf-titulo">Ventas por período</h1>
        <a href="<?= htmlspecialchars($csvUrl) ?>" class="btn-csv">Exportar CSV</a>
    </div>

    <!-- Filtros -->
    <div class="inf-card">
        <form method="get" class="inf-filtros">
            <div class="inf-field">
                <label>Desde</label>
                <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>">
            </div>
            <div class="inf-field">
                <label>Hasta</label>
                <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>">
            </div>
            <?php if ($esAdmin): ?>
            <div class="inf-field">
                <label>Sucursal</label>
                <select name="sucursal">
                    <option value="0">Todas</option>
                    <?php foreach ($sucursales as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= $filtroSuc === (int)$s['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="inf-field">
                <label>Tipo de venta</label>
                <select name="tipo_venta">
                    <option value="">Todos</option>
                    <option value="MINORISTA" <?= $tipoVenta === 'MINORISTA' ? 'selected' : '' ?>>Minorista</option>
                    <option value="MAYORISTA" <?= $tipoVenta === 'MAYORISTA' ? 'selected' : '' ?>>Mayorista</option>
                </select>
            </div>
            <div class="inf-field">
                <label>Estado de pago</label>
                <select name="estado_pago">
                    <option value="">Todos</option>
                    <option value="PAGADA"    <?= $estadoPago === 'PAGADA'    ? 'selected' : '' ?>>Pagada</option>
                    <option value="PENDIENTE" <?= $estadoPago === 'PENDIENTE' ? 'selected' : '' ?>>Pendiente</option>
                    <option value="PARCIAL"   <?= $estadoPago === 'PARCIAL'   ? 'selected' : '' ?>>Parcial</option>
                    <option value="ANULADA"   <?= $estadoPago === 'ANULADA'   ? 'selected' : '' ?>>Anulada</option>
                </select>
            </div>
            <div class="inf-filtros-acciones">
                <button type="submit" class="btn-primary">Filtrar</button>
                <a href="/TYPSISTEMA/app/views/informes/ventas.php" class="btn-link">Limpiar</a>
            </div>
        </form>
    </div>

    <!-- Tabla -->
    <div class="inf-tabla-wrapper">
        <?php if (empty($rows)): ?>
            <p class="inf-sin-datos">No hay ventas para los filtros seleccionados.</p>
        <?php else: ?>
        <table class="inf-tabla">
            <thead>
                <tr>
                    <th>N° Venta</th>
                    <th>Fecha</th>
                    <th>Cliente</th>
                    <th>Vendedor</th>
                    <th>Sucursal</th>
                    <th class="col-centro">Tipo</th>
                    <th class="col-monto">Total</th>
                    <th class="col-centro">Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= (int)$row['id'] ?></td>
                    <td style="white-space:nowrap"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($row['fecha_hora']))) ?></td>
                    <td><?= htmlspecialchars($row['cliente']) ?></td>
                    <td><?= htmlspecialchars($row['vendedor'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($row['sucursal'] ?? '-') ?></td>
                    <td class="col-centro"><?= htmlspecialchars($row['tipo_venta']) ?></td>
                    <td class="col-monto">$<?= number_format((float)$row['total'], 2, ',', '.') ?></td>
                    <td class="col-centro"><?= badgeEstado($row['estado_pago']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="6"><strong>Totales (<?= count($rows) ?> ventas)</strong></td>
                    <td class="col-monto">$<?= number_format($sumaTotal, 2, ',', '.') ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        <?php endif; ?>
    </div>

    <p class="inf-totales"><?= count($rows) ?> registro(s) encontrado(s)</p>

</div>
<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';
