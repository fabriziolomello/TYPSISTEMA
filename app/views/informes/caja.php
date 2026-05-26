<?php
// app/views/informes/caja.php

$titulo    = "Informe: Caja";
$css_extra = '<link rel="stylesheet" href="' . BASE_URL . 'public/css/informes.css">';

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$esAdmin = ($_SESSION['usuario_rol'] ?? '') === 'ADMIN';

// Solo admins pueden acceder
if (!$esAdmin) {
    header('Location: ' . BASE_URL . 'app/views/dashboard/index.php');
    exit;
}

$db   = new Database();
$conn = $db->getConnection();

// Filtros con defaults
$desde     = $_GET['desde']    ?? date('Y-m-01');
$hasta     = $_GET['hasta']    ?? date('Y-m-d');
$filtroSuc = (int)($_GET['sucursal'] ?? 0);

// Validar fechas
$reFecha = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($reFecha, $desde)) $desde = date('Y-m-01');
if (!preg_match($reFecha, $hasta)) $hasta = date('Y-m-d');

// Sucursales para select
$sucursales = $conn->query("SELECT id, nombre FROM deposito ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

// Construir query
$params = [$desde, $hasta];
$types  = 'ss';
$where  = ["c.fecha BETWEEN ? AND ?"];

if ($filtroSuc > 0) {
    $where[]  = "c.id_sucursal = ?";
    $params[] = $filtroSuc;
    $types   .= 'i';
}

$sqlWhere = 'WHERE ' . implode(' AND ', $where);

$sql = "
    SELECT c.id,
           c.fecha,
           c.estado,
           c.saldo_inicial,
           c.saldo_final,
           d.nombre  AS sucursal,
           ua.nombre AS usuario_apertura,
           MAX(CASE WHEN cd.medio_pago = 'EFECTIVO'      THEN cd.total_real ELSE 0 END) AS efectivo,
           MAX(CASE WHEN cd.medio_pago = 'TARJETA'       THEN cd.total_real ELSE 0 END) AS tarjeta,
           MAX(CASE WHEN cd.medio_pago = 'TRANSFERENCIA' THEN cd.total_real ELSE 0 END) AS transferencia,
           MAX(CASE WHEN cd.medio_pago = 'QR'            THEN cd.total_real ELSE 0 END) AS qr
    FROM caja c
    LEFT JOIN deposito d             ON d.id  = c.id_sucursal
    LEFT JOIN usuarios ua            ON ua.id = c.id_usuario_apertura
    LEFT JOIN caja_cierre_detalle cd ON cd.id_caja = c.id
    $sqlWhere
    GROUP BY c.id, c.fecha, c.estado, c.saldo_inicial, c.saldo_final, d.nombre, ua.nombre
    ORDER BY c.fecha DESC, c.id DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calcular total recaudado por fila
foreach ($rows as &$row) {
    $row['efectivo']      = (float)($row['efectivo']      ?? 0);
    $row['tarjeta']       = (float)($row['tarjeta']       ?? 0);
    $row['transferencia'] = (float)($row['transferencia'] ?? 0);
    $row['qr']            = (float)($row['qr']            ?? 0);
    $row['total_recaudado'] = $row['efectivo'] + $row['tarjeta'] + $row['transferencia'] + $row['qr'];
}
unset($row);

// Totales
$sumaEfectivo      = array_sum(array_column($rows, 'efectivo'));
$sumaTarjeta       = array_sum(array_column($rows, 'tarjeta'));
$sumaTransferencia = array_sum(array_column($rows, 'transferencia'));
$sumaQr            = array_sum(array_column($rows, 'qr'));
$sumaTotal         = array_sum(array_column($rows, 'total_recaudado'));

// ── CSV export ──
if (($_GET['formato'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="informe_caja_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Fecha', 'Estado', 'Sucursal', 'Usuario', 'Saldo inicial', 'Saldo final', 'Efectivo', 'Tarjeta', 'Transferencia', 'QR', 'Total recaudado'], ';');
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['fecha'],
            $row['estado'],
            $row['sucursal']         ?? '-',
            $row['usuario_apertura'] ?? '-',
            number_format((float)($row['saldo_inicial'] ?? 0), 2, '.', ''),
            number_format((float)($row['saldo_final']   ?? 0), 2, '.', ''),
            number_format((float)$row['efectivo'],          2, '.', ''),
            number_format((float)$row['tarjeta'],           2, '.', ''),
            number_format((float)$row['transferencia'],     2, '.', ''),
            number_format((float)$row['qr'],                2, '.', ''),
            number_format($row['total_recaudado'],          2, '.', ''),
        ], ';');
    }
    fclose($out);
    exit;
}

// URL CSV
$csvUrl = '?desde=' . urlencode($desde)
        . '&hasta=' . urlencode($hasta)
        . '&sucursal=' . $filtroSuc
        . '&formato=csv';

ob_start();
?>
<div class="inf-container">

    <div class="inf-header">
        <h1 class="inf-titulo">Informe de caja</h1>
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
            <div class="inf-filtros-acciones">
                <button type="submit" class="btn-primary">Filtrar</button>
                <a href="<?= BASE_URL ?>app/views/informes/caja.php" class="btn-link">Limpiar</a>
            </div>
        </form>
    </div>

    <!-- Tabla -->
    <div class="inf-tabla-wrapper">
        <?php if (empty($rows)): ?>
            <p class="inf-sin-datos">No hay registros de caja para los filtros seleccionados.</p>
        <?php else: ?>
        <table class="inf-tabla">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th class="col-centro">Estado</th>
                    <th>Sucursal</th>
                    <th>Usuario</th>
                    <th class="col-monto">Saldo inicial</th>
                    <th class="col-monto">Saldo final</th>
                    <th class="col-monto">Efectivo</th>
                    <th class="col-monto">Tarjeta</th>
                    <th class="col-monto">Transferencia</th>
                    <th class="col-monto">QR</th>
                    <th class="col-monto">Total recaudado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td style="white-space:nowrap"><?= htmlspecialchars($row['fecha']) ?></td>
                    <td class="col-centro"><span class="badge <?= $row['estado'] === 'ABIERTA' ? 'badge-parcial' : 'badge-pagada' ?>"><?= htmlspecialchars($row['estado']) ?></span></td>
                    <td><?= htmlspecialchars($row['sucursal']         ?? '-') ?></td>
                    <td><?= htmlspecialchars($row['usuario_apertura'] ?? '-') ?></td>
                    <td class="col-monto">$<?= number_format((float)($row['saldo_inicial'] ?? 0), 2, ',', '.') ?></td>
                    <td class="col-monto">$<?= number_format((float)($row['saldo_final']   ?? 0), 2, ',', '.') ?></td>
                    <td class="col-monto">$<?= number_format((float)$row['efectivo'],          2, ',', '.') ?></td>
                    <td class="col-monto">$<?= number_format((float)$row['tarjeta'],           2, ',', '.') ?></td>
                    <td class="col-monto">$<?= number_format((float)$row['transferencia'],     2, ',', '.') ?></td>
                    <td class="col-monto">$<?= number_format((float)$row['qr'],                2, ',', '.') ?></td>
                    <td class="col-monto"><strong>$<?= number_format($row['total_recaudado'],  2, ',', '.') ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="6"><strong>Totales (<?= count($rows) ?> cajas)</strong></td>
                    <td class="col-monto">$<?= number_format($sumaEfectivo,      2, ',', '.') ?></td>
                    <td class="col-monto">$<?= number_format($sumaTarjeta,       2, ',', '.') ?></td>
                    <td class="col-monto">$<?= number_format($sumaTransferencia, 2, ',', '.') ?></td>
                    <td class="col-monto">$<?= number_format($sumaQr,            2, ',', '.') ?></td>
                    <td class="col-monto"><strong>$<?= number_format($sumaTotal, 2, ',', '.') ?></strong></td>
                </tr>
            </tfoot>
        </table>
        <?php endif; ?>
    </div>

    <p class="inf-totales"><?= count($rows) ?> caja(s) encontrada(s)</p>

</div>
<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';
