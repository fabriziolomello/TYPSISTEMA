<?php
// app/views/informes/movimientos.php

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$titulo    = "Informe: Movimientos de stock";
$css_extra = '<link rel="stylesheet" href="' . BASE_URL . 'public/css/informes.css">';


$esAdmin = ($_SESSION['usuario_rol'] ?? '') === 'ADMIN';
if (!$esAdmin) { header('Location: ' . BASE_URL . 'app/views/dashboard/index.php'); exit; }

$db   = new Database();
$conn = $db->getConnection();

// Filtros con defaults
$desde    = $_GET['desde']    ?? date('Y-m-01');
$hasta    = $_GET['hasta']    ?? date('Y-m-d');
$deposito = (int)($_GET['deposito'] ?? 0);
$tipo     = $_GET['tipo']     ?? '';

// Validar fechas
$reFecha = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($reFecha, $desde)) $desde = date('Y-m-01');
if (!preg_match($reFecha, $hasta)) $hasta = date('Y-m-d');

// Validar tipo
$tiposValidos = ['INGRESO', 'EGRESO', 'AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO'];
if (!in_array($tipo, $tiposValidos, true)) $tipo = '';

// Depósitos para select
$depositos = $conn->query("SELECT id, nombre FROM deposito ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

// Construir query
$params = [$desde, $hasta];
$types  = 'ss';
$where  = ["DATE(ms.fecha_hora) BETWEEN ? AND ?"];

if ($deposito > 0) {
    $where[]  = "ms.id_deposito = ?";
    $params[] = $deposito;
    $types   .= 'i';
}

if ($tipo !== '') {
    $where[]  = "ms.tipo = ?";
    $params[] = $tipo;
    $types   .= 's';
}

$sqlWhere = 'WHERE ' . implode(' AND ', $where);

$sql = "
    SELECT ms.fecha_hora,
           d.nombre  AS deposito,
           p.nombre  AS producto,
           pv.nombre_variante AS variante,
           ms.tipo,
           ms.cantidad,
           u.nombre  AS usuario,
           mm.observaciones
    FROM movimiento_stock ms
    LEFT JOIN movimiento_manual mm   ON mm.id  = ms.id_movimiento_manual
    LEFT JOIN producto_variante pv   ON pv.id  = ms.id_variante
    LEFT JOIN productos p            ON p.id   = pv.id_producto
    LEFT JOIN deposito d             ON d.id   = ms.id_deposito
    LEFT JOIN usuarios u             ON u.id   = mm.id_usuario
    $sqlWhere
    ORDER BY ms.fecha_hora DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Etiquetas de tipo
function labelTipo(string $tipo): string {
    $map = [
        'INGRESO'          => ['Ingreso',          '#dcfce7', '#15803d'],
        'EGRESO'           => ['Egreso',            '#fee2e2', '#b91c1c'],
        'AJUSTE_POSITIVO'  => ['Ajuste +',          '#dbeafe', '#1d4ed8'],
        'AJUSTE_NEGATIVO'  => ['Ajuste -',          '#fef9c3', '#854d0e'],
    ];
    [$label, $bg, $color] = $map[$tipo] ?? [$tipo, '#f1f5f9', '#475569'];
    return '<span class="badge" style="background:' . $bg . ';color:' . $color . '">' . htmlspecialchars($label) . '</span>';
}

// ── CSV export ──
if (($_GET['formato'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="informe_movimientos_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Fecha y hora', 'Depósito', 'Producto', 'Variante', 'Tipo', 'Cantidad', 'Usuario', 'Observaciones'], ';');
    foreach ($rows as $row) {
        $variante = strtolower($row['variante'] ?? '') === 'unica' ? '-' : ($row['variante'] ?? '-');
        fputcsv($out, [
            $row['fecha_hora'],
            $row['deposito']  ?? '-',
            $row['producto']  ?? '-',
            $variante,
            $row['tipo'],
            $row['cantidad'],
            $row['usuario']       ?? '-',
            $row['observaciones'] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}

// URL CSV
$csvUrl = '?desde=' . urlencode($desde)
        . '&hasta=' . urlencode($hasta)
        . '&deposito=' . $deposito
        . '&tipo=' . urlencode($tipo)
        . '&formato=csv';

ob_start();
?>
<div class="inf-container">

    <div class="inf-header">
        <h1 class="inf-titulo">Movimientos de stock</h1>
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
                <label>Depósito</label>
                <select name="deposito">
                    <option value="0">Todos</option>
                    <?php foreach ($depositos as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= $deposito === (int)$d['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="inf-field">
                <label>Tipo</label>
                <select name="tipo">
                    <option value="">Todos</option>
                    <option value="INGRESO"         <?= $tipo === 'INGRESO'         ? 'selected' : '' ?>>Ingreso</option>
                    <option value="EGRESO"          <?= $tipo === 'EGRESO'          ? 'selected' : '' ?>>Egreso</option>
                    <option value="AJUSTE_POSITIVO" <?= $tipo === 'AJUSTE_POSITIVO' ? 'selected' : '' ?>>Ajuste positivo</option>
                    <option value="AJUSTE_NEGATIVO" <?= $tipo === 'AJUSTE_NEGATIVO' ? 'selected' : '' ?>>Ajuste negativo</option>
                </select>
            </div>
            <div class="inf-filtros-acciones">
                <button type="submit" class="btn-primary">Filtrar</button>
                <a href="<?= BASE_URL ?>app/views/informes/movimientos.php" class="btn-link">Limpiar</a>
            </div>
        </form>
    </div>

    <!-- Tabla -->
    <div class="inf-tabla-wrapper">
        <?php if (empty($rows)): ?>
            <p class="inf-sin-datos">No hay movimientos para los filtros seleccionados.</p>
        <?php else: ?>
        <table class="inf-tabla">
            <thead>
                <tr>
                    <th>Fecha y hora</th>
                    <th>Depósito</th>
                    <th>Producto</th>
                    <th>Variante</th>
                    <th class="col-centro">Tipo</th>
                    <th class="col-monto">Cantidad</th>
                    <th>Usuario</th>
                    <th>Observaciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <?php $varTxt = strtolower($row['variante'] ?? '') === 'unica' ? '-' : htmlspecialchars($row['variante'] ?? '-'); ?>
                <tr>
                    <td style="white-space:nowrap"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($row['fecha_hora']))) ?></td>
                    <td><?= htmlspecialchars($row['deposito'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($row['producto'] ?? '-') ?></td>
                    <td><?= $varTxt ?></td>
                    <td class="col-centro"><?= labelTipo($row['tipo']) ?></td>
                    <td class="col-monto"><?= (int)$row['cantidad'] ?></td>
                    <td><?= htmlspecialchars($row['usuario'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($row['observaciones'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5"><strong>Total (<?= count($rows) ?> movimientos)</strong></td>
                    <td class="col-monto"><strong><?= array_sum(array_column($rows, 'cantidad')) ?></strong></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
        <?php endif; ?>
    </div>

    <p class="inf-totales"><?= count($rows) ?> movimiento(s) encontrado(s)</p>

</div>
<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';
