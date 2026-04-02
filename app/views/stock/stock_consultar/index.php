<?php
// app/views/stock/stock_consultar/index.php

$titulo    = "Consultar stock";
$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/stock.css">';

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

$db   = new Database();
$conn = $db->getConnection();

// Depósitos disponibles
$depositos = $conn->query("SELECT id, nombre FROM deposito ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

// Filtros
$q         = trim($_GET['q']         ?? '');
$categoria = (int)($_GET['categoria'] ?? 0);
$idDep     = (int)($_GET['deposito']  ?? 0); // 0 = todos

$stock  = $_GET['stock']  ?? 'all';
if (!in_array($stock,  ['all','con','sin'], true)) $stock  = 'all';

$estado = $_GET['estado'] ?? 'activos';
if (!in_array($estado, ['activos','inactivos','todos'], true)) $estado = 'activos';

$orden  = $_GET['orden']  ?? 'nombre_asc';
if (!in_array($orden,  ['nombre_asc','stock_desc','stock_asc'], true)) $orden = 'nombre_asc';

// Categorías
$categorias = $conn->query("SELECT id, nombre FROM categoria ORDER BY nombre ASC")->fetch_all(MYSQLI_ASSOC);

// WHERE dinámico
$conds  = [];
$params = [];
$types  = '';

if ($estado === 'activos')   $conds[] = "p.activo = 1 AND pv.activo = 1";
elseif ($estado === 'inactivos') $conds[] = "(p.activo = 0 OR pv.activo = 0)";

if ($categoria > 0) { $conds[] = "p.id_categoria = ?"; $params[] = $categoria; $types .= 'i'; }

if ($q !== '') {
    $conds[] = "(p.nombre LIKE CONCAT('%',?,'%') OR COALESCE(pv.codigo_barras, p.codigo_barras) LIKE CONCAT('%',?,'%') OR pv.nombre_variante LIKE CONCAT('%',?,'%'))";
    $params[] = $q; $params[] = $q; $params[] = $q;
    $types .= 'sss';
}

$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

// Stock por depósito o total
if ($idDep > 0) {
    // Columna stock = stock en ese depósito
    $stockCol   = "COALESCE(sd.stock_actual, 0)";
    $joinDep    = "LEFT JOIN stock_deposito sd ON sd.id_variante = pv.id AND sd.id_deposito = $idDep";
    $havingCond = '';
} else {
    // Columna stock = total (suma de todos los depósitos)
    $stockCol   = "COALESCE(SUM(sd.stock_actual), 0)";
    $joinDep    = "LEFT JOIN stock_deposito sd ON sd.id_variante = pv.id";
    $havingCond = '';
}

if ($stock === 'con') $havingCond = "HAVING stock_deposito > 0";
if ($stock === 'sin') $havingCond = "HAVING stock_deposito = 0";

$ordenSql = "p.nombre ASC, pv.nombre_variante ASC";
if ($orden === 'stock_desc') $ordenSql = "stock_deposito DESC, p.nombre ASC";
if ($orden === 'stock_asc')  $ordenSql = "stock_deposito ASC, p.nombre ASC";

// Columnas de stock por depósito (para mostrar todas cuando no hay filtro)
$colsDepositoSql = '';
foreach ($depositos as $d) {
    $did = (int)$d['id'];
    $colsDepositoSql .= ", COALESCE(MAX(CASE WHEN sda.id_deposito = $did THEN sda.stock_actual END), 0) AS stock_dep_$did";
}

$sql = "
    SELECT
        COALESCE(pv.codigo_barras, p.codigo_barras) AS codigo_barras,
        p.id AS id_producto,
        pv.id AS id_variante,
        p.nombre AS producto,
        pv.nombre_variante AS variante,
        c.nombre AS categoria,
        p.precio_costo,
        $stockCol AS stock_deposito
        $colsDepositoSql
    FROM producto_variante pv
    INNER JOIN productos p ON p.id = pv.id_producto
    LEFT JOIN categoria c ON c.id = p.id_categoria
    $joinDep
    LEFT JOIN stock_deposito sda ON sda.id_variante = pv.id
    $where
    GROUP BY pv.id, p.id, p.nombre, pv.nombre_variante, pv.codigo_barras, p.codigo_barras, c.nombre, p.precio_costo, p.activo, pv.activo
    $havingCond
    ORDER BY $ordenSql
";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

ob_start();
?>

<h1 class="stock-title">Consultar stock</h1>

<form method="GET" class="stock-filters">
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar por nombre, código o variante..." class="stock-input">

    <select name="categoria" class="stock-select">
        <option value="0">Todas las categorías</option>
        <?php foreach ($categorias as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= (int)$cat['id'] === $categoria ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat['nombre']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="deposito" class="stock-select">
        <option value="0">Todos los depósitos</option>
        <?php foreach ($depositos as $d): ?>
            <option value="<?= $d['id'] ?>" <?= (int)$d['id'] === $idDep ? 'selected' : '' ?>>
                <?= htmlspecialchars($d['nombre']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="stock" class="stock-select">
        <option value="all" <?= $stock==='all'?'selected':'' ?>>Stock: Todos</option>
        <option value="con" <?= $stock==='con'?'selected':'' ?>>Con stock</option>
        <option value="sin" <?= $stock==='sin'?'selected':'' ?>>Sin stock</option>
    </select>

    <select name="estado" class="stock-select">
        <option value="activos"   <?= $estado==='activos'?'selected':'' ?>>Activos</option>
        <option value="inactivos" <?= $estado==='inactivos'?'selected':'' ?>>Inactivos</option>
        <option value="todos"     <?= $estado==='todos'?'selected':'' ?>>Todos</option>
    </select>

    <select name="orden" class="stock-select">
        <option value="nombre_asc" <?= $orden==='nombre_asc'?'selected':'' ?>>Nombre (A–Z)</option>
        <option value="stock_desc" <?= $orden==='stock_desc'?'selected':'' ?>>Stock (Mayor a menor)</option>
        <option value="stock_asc"  <?= $orden==='stock_asc'?'selected':'' ?>>Stock (Menor a mayor)</option>
    </select>

    <button type="submit" class="btn-primary">Aplicar</button>
    <a href="/TYPSISTEMA/app/views/stock/stock_consultar/index.php" class="btn-link">Limpiar</a>
</form>

<table class="stock-table">
    <thead>
        <tr>
            <th>Código de barras</th>
            <th>Producto</th>
            <th>Variante</th>
            <th>Categoría</th>
            <th>Precio costo</th>
            <?php if ($idDep > 0): ?>
                <?php foreach ($depositos as $d): ?>
                    <?php if ((int)$d['id'] === $idDep): ?>
                        <th><?= htmlspecialchars($d['nombre']) ?></th>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ($depositos as $d): ?>
                    <th><?= htmlspecialchars($d['nombre']) ?></th>
                <?php endforeach; ?>
                <th>Total</th>
            <?php endif; ?>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($items)): ?>
            <tr><td colspan="10" class="stock-empty">No hay resultados con esos filtros.</td></tr>
        <?php else: ?>
            <?php foreach ($items as $it): ?>
                <?php
                    $totalStock = $idDep > 0
                        ? (int)$it['stock_deposito']
                        : array_sum(array_map(fn($d) => (int)($it['stock_dep_' . $d['id']] ?? 0), $depositos));
                ?>
                <tr class="<?= $totalStock === 0 ? 'sin-stock' : '' ?>">
                    <td><?= htmlspecialchars($it['codigo_barras'] ?? '') ?></td>
                    <td><?= htmlspecialchars($it['producto']) ?></td>
                    <td><?= strtolower($it['variante'] ?? '') === 'unica' ? '' : htmlspecialchars($it['variante'] ?? '') ?></td>
                    <td><?= htmlspecialchars($it['categoria'] ?? '-') ?></td>
                    <td><?= $it['precio_costo'] > 0 ? number_format((float)$it['precio_costo'], 2, ',', '.') : '-' ?></td>
                    <?php if ($idDep > 0): ?>
                        <td class="stock-num"><?= (int)$it['stock_deposito'] ?></td>
                    <?php else: ?>
                        <?php foreach ($depositos as $d): ?>
                            <td class="stock-num"><?= (int)($it['stock_dep_' . $d['id']] ?? 0) ?></td>
                        <?php endforeach; ?>
                        <td class="stock-num stock-total"><?= $totalStock ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../../layouts/main.php';
