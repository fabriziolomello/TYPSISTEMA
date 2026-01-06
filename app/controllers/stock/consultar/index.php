<?php
// app/controllers/stock/index.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$db = new Database();
$conn = $db->getConnection();

// -------------------------
// 1) Leer filtros (GET)
// -------------------------
$q        = trim($_GET['q'] ?? '');
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
// 2) Traer categorías (para el select en la vista)
// -------------------------
$categorias = [];
$resCat = $conn->query("SELECT id, nombre FROM categoria ORDER BY nombre ASC");
if ($resCat) {
  while ($row = $resCat->fetch_assoc()) $categorias[] = $row;
}

// -------------------------
// 3) Armar WHERE dinámico
// -------------------------
$conds = [];
$params = [];
$types = "";

// Estado
if ($estado === 'activos') {
  $conds[] = "p.activo = 1 AND pv.activo = 1";
} elseif ($estado === 'inactivos') {
  $conds[] = "(p.activo = 0 OR pv.activo = 0)";
}

// Categoría
if ($categoria > 0) {
  $conds[] = "p.id_categoria = ?";
  $types .= "i";
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
  $types .= "ssss";
  $params[] = $q;
  $params[] = $q;
  $params[] = $q;
  $params[] = $q;
}

$where = $conds ? ("WHERE " . implode(" AND ", $conds)) : "";

// -------------------------
// 4) Query principal (1 fila = 1 variante)
// -------------------------
$sql = "
  SELECT
    pv.id AS id_variante,
    p.id AS id_producto,
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
if (!$stmt) {
  die("Error prepare: " . $conn->error);
}

if (!empty($params)) {
  $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($row = $res->fetch_assoc()) {
  $items[] = $row;
}