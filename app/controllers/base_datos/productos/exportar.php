<?php
// app/controllers/base_datos/productos/exportar.php

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

$db   = new Database();
$conn = $db->getConnection();

$res = $conn->query("
    SELECT
        p.nombre,
        p.codigo_barras AS codigo_producto,
        c.nombre AS categoria,
        pr.nombre AS proveedor,
        p.precio_costo AS costo,
        MAX(CASE WHEN lp.tipo_lista = 'MINORISTA' THEN lp.precio END) AS precio_minorista,
        MAX(CASE WHEN lp.tipo_lista = 'MAYORISTA' THEN lp.precio END) AS precio_mayorista,
        pv.nombre_variante AS variante,
        COALESCE(pv.codigo_barras, p.codigo_barras) AS codigo_variante,
        pv.stock_actual AS stock,
        IF(p.activo = 1, 'Activo', 'Inactivo') AS estado
    FROM productos p
    LEFT JOIN categoria c ON c.id = p.id_categoria
    LEFT JOIN proveedor pr ON pr.id = p.id_proveedor
    LEFT JOIN lista_precio lp ON lp.id_producto = p.id
    LEFT JOIN producto_variante pv ON pv.id_producto = p.id
    GROUP BY p.id, p.nombre, p.codigo_barras, c.nombre, pr.nombre, p.precio_costo, p.activo,
             pv.id, pv.nombre_variante, pv.codigo_barras, pv.stock_actual
    ORDER BY p.nombre ASC, pv.nombre_variante ASC
");

$filename = 'productos_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');

// BOM para que Excel abra bien el UTF-8
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Cabecera
fputcsv($out, ['Nombre', 'Código producto', 'Categoría', 'Proveedor', 'Costo', 'Precio minorista', 'Precio mayorista', 'Variante', 'Código variante', 'Stock', 'Estado'], ';');

while ($row = $res->fetch_assoc()) {
    fputcsv($out, [
        $row['nombre'],
        $row['codigo_producto'] ?? '',
        $row['categoria'] ?? '',
        $row['proveedor'] ?? '',
        number_format($row['costo'] ?? 0, 2, ',', '.'),
        number_format($row['precio_minorista'] ?? 0, 2, ',', '.'),
        number_format($row['precio_mayorista'] ?? 0, 2, ',', '.'),
        $row['variante'] === 'unica' ? '' : $row['variante'],
        $row['codigo_variante'] ?? '',
        $row['stock'],
        $row['estado'],
    ], ';');
}

fclose($out);
exit;
