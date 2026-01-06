<?php
// app/views/stock/index.php

$titulo = "Consultar stock";
$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/stock.css">';
require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

$db   = new Database();
$conn = $db->getConnection();

// -------------------------
// 1) Filtros (GET)
// -------------------------
$q         = trim($_GET['q'] ?? '');
$categoria = (int)($_GET['categoria'] ?? 0);

// stock: all | con | sin
$stock = $_GET['stock'] ?? 'all';
if (!in_array($stock, ['all', 'con', 'sin'], true)) $stock = 'all';

// estado: activos | inactivos | todos
$estado = $_GET['estado'] ?? 'activos';
if (!in_array($estado, ['activos', 'inactivos', 'todos'], true)) $estado = 'activos';

// orden: nombre_asc | stock_desc | stock_asc
$orden = $_GET['orden'] ?? 'nombre_asc';
if (!in_array($orden, ['nombre_asc', 'stock_desc', 'stock_asc'], true)) $orden = 'nombre_asc';

$ordenSql = "p.nombre ASC, pv.nombre_variante ASC";
if ($orden === 'stock_desc') $ordenSql = "pv.stock_actual DESC, p.nombre ASC";
if ($orden === 'stock_asc')  $ordenSql = "pv.stock_actual ASC, p.nombre ASC";

// -------------------------
// 2) Categorías (select)
// -------------------------
$categorias = [];
$resCat = $conn->query("SELECT id, nombre FROM categoria ORDER BY nombre ASC");
if ($resCat) {
  while ($row = $resCat->fetch_assoc()) $categorias[] = $row;
}

// -------------------------
// 3) WHERE dinámico
// -------------------------
$conds  = [];
$params = [];
$types  = "";

// Estado
if ($estado === 'activos') {
  $conds[] = "p.activo = 1 AND pv.activo = 1";
} elseif ($estado === 'inactivos') {
  $conds[] = "(p.activo = 0 OR pv.activo = 0)";
}

// Categoría
if ($categoria > 0) {
  $conds[] = "p.id_categoria = ?";
  $types  .= "i";
  $params[] = $categoria;
}

// Stock
if ($stock === 'con') {
  $conds[] = "pv.stock_actual > 0";
} elseif ($stock === 'sin') {
  $conds[] = "pv.stock_actual = 0";
}

// Buscar
if ($q !== '') {
  $conds[] = "(p.nombre LIKE CONCAT('%', ?, '%')
           OR p.codigo_barras LIKE CONCAT('%', ?, '%')
           OR pv.codigo_barras LIKE CONCAT('%', ?, '%')
           OR pv.nombre_variante LIKE CONCAT('%', ?, '%'))";
  $types  .= "ssss";
  $params[] = $q;
  $params[] = $q;
  $params[] = $q;
  $params[] = $q;
}

$where = $conds ? ("WHERE " . implode(" AND ", $conds)) : "";

// -------------------------
// 4) Query principal
//    (1 fila = 1 variante)
// -------------------------
$sql = "
  SELECT
    COALESCE(pv.codigo_barras, p.codigo_barras) AS codigo_barras,
    p.nombre AS producto,
    pv.nombre_variante AS variante,
    c.nombre AS categoria,
    p.precio_costo,
    pv.stock_actual,
    p.activo AS producto_activo,
    pv.activo AS variante_activo
  FROM producto_variante pv
  INNER JOIN productos p ON p.id = pv.id_producto
  LEFT JOIN categoria c ON c.id = p.id_categoria
  $where
  ORDER BY $ordenSql
";

$stmt = $conn->prepare($sql);
if (!$stmt) die("Error prepare: " . $conn->error);

if (!empty($params)) {
  $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($row = $res->fetch_assoc()) $items[] = $row;

// -------------------------
// 5) Render (buffer)
// -------------------------
ob_start();
?>

<h1 class="stock-title">Consultar stock</h1>

<form method="GET" class="stock-filters">
  <input
    type="text"
    name="q"
    value="<?= htmlspecialchars($q) ?>"
    placeholder="Buscar por nombre, código o variante..."
    class="stock-input"
  />

  <select name="categoria" class="stock-select">
    <option value="0">Todas las categorías</option>
    <?php foreach ($categorias as $cat): ?>
      <option value="<?= (int)$cat['id'] ?>" <?= ((int)$cat['id'] === $categoria) ? 'selected' : '' ?>>
        <?= htmlspecialchars($cat['nombre']) ?>
      </option>
    <?php endforeach; ?>
  </select>

  <select name="stock" class="stock-select">
    <option value="all" <?= $stock==='all'?'selected':'' ?>>Stock: Todos</option>
    <option value="con" <?= $stock==='con'?'selected':'' ?>>Stock: Con stock</option>
    <option value="sin" <?= $stock==='sin'?'selected':'' ?>>Stock: Sin stock</option>
  </select>

  <select name="estado" class="stock-select">
    <option value="activos"   <?= $estado==='activos'?'selected':'' ?>>Estado: Activos</option>
    <option value="inactivos" <?= $estado==='inactivos'?'selected':'' ?>>Estado: Inactivos</option>
    <option value="todos"     <?= $estado==='todos'?'selected':'' ?>>Estado: Todos</option>
  </select>

  <select name="orden" class="stock-select">
    <option value="nombre_asc" <?= $orden==='nombre_asc'?'selected':'' ?>>Orden: Nombre (A–Z)</option>
    <option value="stock_desc" <?= $orden==='stock_desc'?'selected':'' ?>>Orden: Stock (Mayor a menor)</option>
    <option value="stock_asc"  <?= $orden==='stock_asc'?'selected':'' ?>>Orden: Stock (Menor a mayor)</option>
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
      <th>Stock</th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($items)): ?>
      <tr>
        <td colspan="6" class="stock-empty">
          No hay resultados con esos filtros.
        </td>
      </tr>
    <?php else: ?>
      <?php foreach ($items as $it): ?>
        <tr class="<?= ((int)$it['stock_actual'] === 0) ? 'sin-stock' : '' ?>">
          <td><?= htmlspecialchars($it['codigo_barras'] ?? '') ?></td>
          <td><?= htmlspecialchars($it['producto'] ?? '') ?></td>
          <td><?= htmlspecialchars($it['variante'] ?? '') ?></td>
          <td><?= htmlspecialchars($it['categoria'] ?? '-') ?></td>
          <td><?= number_format((float)($it['precio_costo'] ?? 0), 2, ',', '.') ?></td>
          <td class="stock-num">
            <?= (int)$it['stock_actual'] ?>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </tbody>
</table>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../../layouts/main.php';