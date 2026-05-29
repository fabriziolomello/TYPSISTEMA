<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

$q           = trim($_GET['q']               ?? '');
$idCategoria = (int)($_GET['id_categoria']   ?? 0);
$idSub       = (int)($_GET['id_subcategoria'] ?? 0);
$idDeposito  = (int)($_SESSION['usuario_deposito'] ?? 1);

$db   = new Database();
$conn = $db->getConnection();

$sql = "
    SELECT
        pv.id             AS id_variante,
        p.nombre          AS nombre_producto,
        pv.nombre_variante,
        COALESCE(pv.codigo_barras, p.codigo_barras) AS codigo_barras,
        COALESCE(sd.stock_actual, 0)                AS stock_actual
    FROM producto_variante pv
    INNER JOIN productos p ON p.id = pv.id_producto
    LEFT  JOIN stock_deposito sd ON sd.id_variante = pv.id AND sd.id_deposito = ?
    WHERE pv.activo = 1 AND p.activo = 1
";

$params = [$idDeposito];
$types  = 'i';

if ($q !== '') {
    $like    = '%' . $q . '%';
    $sql    .= " AND (p.nombre LIKE ? OR pv.codigo_barras LIKE ? OR p.codigo_barras LIKE ? OR pv.nombre_variante LIKE ?)";
    $params  = array_merge($params, [$like, $like, $like, $like]);
    $types  .= 'ssss';
}

if ($idCategoria > 0) {
    $sql    .= " AND p.id_categoria = ?";
    $params[] = $idCategoria;
    $types  .= 'i';
}

if ($idSub > 0) {
    $sql    .= " AND p.id_subcategoria = ?";
    $params[] = $idSub;
    $types  .= 'i';
}

$sql .= " ORDER BY p.nombre ASC, pv.nombre_variante ASC LIMIT 200";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$productos = [];
while ($row = $res->fetch_assoc()) {
    $productos[] = $row;
}

echo json_encode(['success' => true, 'productos' => $productos]);
