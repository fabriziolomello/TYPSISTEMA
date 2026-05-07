<?php
// app/views/informes/stock.php

$titulo    = "Informe: Stock actual";
$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/informes.css">';

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$esAdmin = ($_SESSION['usuario_rol'] ?? '') === 'ADMIN';
if (!$esAdmin) { header('Location: /TYPSISTEMA/app/views/dashboard/index.php'); exit; }

$db   = new Database();
$conn = $db->getConnection();

// Filtros
$deposito  = $_GET['deposito']  ?? '0';   // '0'=todos, '1'=Local, '2'=Distrib
$categoria = (int)($_GET['categoria'] ?? 0);

// Validar depósito
if (!in_array($deposito, ['0', '1', '2'], true)) $deposito = '0';
$depositoInt = (int)$deposito;

// Categorías para select
$categorias = $conn->query("SELECT id, nombre FROM categoria ORDER BY nombre ASC")->fetch_all(MYSQLI_ASSOC);

// Construir condiciones de WHERE
$params = [];
$types  = '';
$where  = ["p.activo = 1", "pv.activo = 1"];

if ($categoria > 0) {
    $where[]  = "p.id_categoria = ?";
    $params[] = $categoria;
    $types   .= 'i';
}

// Filtro de depósito: HAVING sobre la columna de stock específica
// Esto se aplica después del GROUP BY usando HAVING o simplemente filtrando en código
// Para simplicidad lo aplicamos como condición post-fetch
$sqlWhere = 'WHERE ' . implode(' AND ', $where);

$sql = "
    SELECT p.nombre AS producto,
           pv.nombre_variante AS variante,
           pv.codigo_barras,
           c.nombre AS categoria,
           COALESCE(sd1.stock_actual, 0) AS stock_local,
           COALESCE(sd2.stock_actual, 0) AS stock_distrib,
           pv.stock_actual AS stock_total
    FROM producto_variante pv
    INNER JOIN productos p  ON p.id  = pv.id_producto
    LEFT  JOIN categoria c  ON c.id  = p.id_categoria
    LEFT  JOIN stock_deposito sd1 ON sd1.id_variante = pv.id AND sd1.id_deposito = 1
    LEFT  JOIN stock_deposito sd2 ON sd2.id_variante = pv.id AND sd2.id_deposito = 2
    $sqlWhere
    ORDER BY p.nombre ASC, pv.nombre_variante ASC
";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$allRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Filtro por depósito (post-fetch)
if ($depositoInt === 1) {
    $rows = array_filter($allRows, fn($r) => (int)$r['stock_local'] > 0);
} elseif ($depositoInt === 2) {
    $rows = array_filter($allRows, fn($r) => (int)$r['stock_distrib'] > 0);
} else {
    $rows = $allRows;
}
$rows = array_values($rows);

// ── CSV export ──
if (($_GET['formato'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="informe_stock_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Producto', 'Variante', 'Código', 'Categoría', 'Stock Local', 'Stock Distrib', 'Stock Total'], ';');
    foreach ($rows as $row) {
        $variante = strtolower($row['variante'] ?? '') === 'unica' ? '-' : ($row['variante'] ?? '-');
        fputcsv($out, [
            $row['producto'],
            $variante,
            $row['codigo_barras'] ?? '',
            $row['categoria'] ?? '-',
            $row['stock_local'],
            $row['stock_distrib'],
            $row['stock_total'],
        ], ';');
    }
    fclose($out);
    exit;
}

// URL CSV
$csvUrl = '?deposito=' . urlencode($deposito)
        . '&categoria=' . $categoria
        . '&formato=csv';

ob_start();
?>
<div class="inf-container">

    <div class="inf-header">
        <h1 class="inf-titulo">Stock actual</h1>
        <a href="<?= htmlspecialchars($csvUrl) ?>" class="btn-csv">Exportar CSV</a>
    </div>

    <!-- Filtros -->
    <div class="inf-card">
        <form method="get" class="inf-filtros">
            <div class="inf-field">
                <label>Depósito</label>
                <select name="deposito">
                    <option value="0" <?= $deposito === '0' ? 'selected' : '' ?>>Todos</option>
                    <option value="1" <?= $deposito === '1' ? 'selected' : '' ?>>Local / Terrazas</option>
                    <option value="2" <?= $deposito === '2' ? 'selected' : '' ?>>Distribuidora</option>
                </select>
            </div>
            <div class="inf-field">
                <label>Categoría</label>
                <select name="categoria">
                    <option value="0">Todas las categorías</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>" <?= $categoria === (int)$cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="inf-filtros-acciones">
                <button type="submit" class="btn-primary">Filtrar</button>
                <a href="/TYPSISTEMA/app/views/informes/stock.php" class="btn-link">Limpiar</a>
            </div>
        </form>
    </div>

    <!-- Tabla -->
    <div class="inf-tabla-wrapper">
        <?php if (empty($rows)): ?>
            <p class="inf-sin-datos">No hay productos para los filtros seleccionados.</p>
        <?php else: ?>
        <table class="inf-tabla">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>Variante</th>
                    <th>Código</th>
                    <th>Categoría</th>
                    <th class="col-monto">Stock Local</th>
                    <th class="col-monto">Stock Distrib.</th>
                    <th class="col-monto">Stock Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <?php
                    $esCero    = (int)$row['stock_total'] === 0;
                    $varTxt    = strtolower($row['variante'] ?? '') === 'unica' ? '-' : htmlspecialchars($row['variante'] ?? '-');
                ?>
                <tr class="<?= $esCero ? 'stock-cero' : '' ?>">
                    <td><?= htmlspecialchars($row['producto']) ?></td>
                    <td><?= $varTxt ?></td>
                    <td><?= htmlspecialchars($row['codigo_barras'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($row['categoria'] ?? '-') ?></td>
                    <td class="col-monto"><?= (int)$row['stock_local'] ?></td>
                    <td class="col-monto"><?= (int)$row['stock_distrib'] ?></td>
                    <td class="col-monto"><strong><?= (int)$row['stock_total'] ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4"><strong>Total (<?= count($rows) ?> variantes)</strong></td>
                    <td class="col-monto"><?= array_sum(array_column($rows, 'stock_local')) ?></td>
                    <td class="col-monto"><?= array_sum(array_column($rows, 'stock_distrib')) ?></td>
                    <td class="col-monto"><strong><?= array_sum(array_column($rows, 'stock_total')) ?></strong></td>
                </tr>
            </tfoot>
        </table>
        <?php endif; ?>
    </div>

    <p class="inf-totales"><?= count($rows) ?> variante(s) encontrada(s)</p>

</div>
<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';
