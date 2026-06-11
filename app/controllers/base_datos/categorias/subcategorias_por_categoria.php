<?php
// app/controllers/base_datos/categorias/subcategorias_por_categoria.php
// Devuelve subcategorías usadas en productos de la categoría dada,
// más las que tengan id_categoria asignado explícitamente.

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

try {
    $idCategoria = (int)($_GET['id_categoria'] ?? 0);
    if ($idCategoria <= 0) {
        echo json_encode(['success' => true, 'subcategorias' => []]);
        exit;
    }

    $db   = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("
        SELECT DISTINCT sc.id, sc.nombre
        FROM subcategoria sc
        WHERE sc.id_categoria = ?
           OR sc.id IN (
               SELECT DISTINCT p.id_subcategoria
               FROM productos p
               WHERE p.id_categoria = ?
                 AND p.id_subcategoria IS NOT NULL
           )
        ORDER BY sc.nombre ASC
    ");
    $stmt->bind_param('ii', $idCategoria, $idCategoria);
    $stmt->execute();
    $subcategorias = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode(['success' => true, 'subcategorias' => $subcategorias]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
