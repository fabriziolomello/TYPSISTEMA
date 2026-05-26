<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

if (($_SESSION['usuario_rol'] ?? '') !== 'ADMIN') {
    echo json_encode(['success' => false, 'error' => 'Sin permisos']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (empty($data['confirmar'])) {
    echo json_encode(['success' => false, 'error' => 'Confirmación requerida']);
    exit;
}

try {
    $db   = new Database();
    $conn = $db->getConnection();

    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    $tablas = [
        'detalle_ventas', 'movimiento_caja', 'caja_cierre_detalle',
        'ventas', 'caja',
        'movimiento_stock', 'movimiento_manual',
        'stock_deposito', 'lista_precio', 'producto_variante', 'productos',
        'clientes', 'proveedor', 'categoria', 'subcategoria',
        'tiendanube_pedido', 'tiendanube_variante', 'tiendanube_producto', 'tiendanube_config',
    ];

    foreach ($tablas as $tabla) {
        $conn->query("TRUNCATE TABLE `$tabla`");
    }

    $conn->query("SET FOREIGN_KEY_CHECKS = 1");

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
