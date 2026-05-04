<?php
// app/controllers/tiendanube/sincronizar.php
// Actualiza stock y precio en TN para todos los productos ya publicados

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/api.php';

if (($_SESSION['usuario_rol'] ?? '') !== 'ADMIN') {
    echo json_encode(['success' => false, 'error' => 'Sin permisos']);
    exit;
}

try {
    $db   = new Database();
    $conn = $db->getConnection();

    $config     = tn_get_config($conn);
    $idDeposito = (int)$config['id_deposito'];

    // Variantes publicadas con su mapping TN
    $variantes = $conn->query("
        SELECT
            tv.id_variante,
            tv.tn_variant_id,
            tp.tn_product_id,
            COALESCE(sd.stock_actual, 0) AS stock,
            MAX(CASE WHEN lp.tipo_lista = 'MINORISTA' THEN lp.precio END) AS precio_minorista
        FROM tiendanube_variante tv
        INNER JOIN producto_variante pv ON pv.id = tv.id_variante
        INNER JOIN tiendanube_producto tp ON tp.id_producto = pv.id_producto
        LEFT JOIN stock_deposito sd ON sd.id_variante = tv.id_variante AND sd.id_deposito = $idDeposito
        LEFT JOIN lista_precio lp ON lp.id_producto = pv.id_producto
        WHERE pv.activo = 1
        GROUP BY tv.id_variante, tv.tn_variant_id, tp.tn_product_id, sd.stock_actual
    ")->fetch_all(MYSQLI_ASSOC);

    if (empty($variantes)) {
        echo json_encode(['success' => true, 'mensaje' => 'No hay productos publicados para sincronizar.', 'sincronizados' => 0]);
        exit;
    }

    $sincronizados = 0;
    $errores       = [];

    $stmtSync = $conn->prepare("UPDATE tiendanube_producto SET sincronizado_at = NOW() WHERE tn_product_id = ?");

    foreach ($variantes as $v) {
        try {
            tn_request('PUT',
                "products/{$v['tn_product_id']}/variants/{$v['tn_variant_id']}",
                [
                    'stock' => (int)$v['stock'],
                    'price' => number_format((float)($v['precio_minorista'] ?? 0), 2, '.', ''),
                ],
                $config
            );

            $stmtSync->bind_param('i', $v['tn_product_id']);
            $stmtSync->execute();

            $sincronizados++;
        } catch (Throwable $e) {
            $errores[] = "Variante {$v['id_variante']}: " . $e->getMessage();
        }
    }

    $stmtSync->close();

    echo json_encode([
        'success'       => true,
        'sincronizados' => $sincronizados,
        'errores'       => $errores,
    ]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
