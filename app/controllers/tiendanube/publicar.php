<?php
// app/controllers/tiendanube/publicar.php
// Publica en TN los productos del sistema que aún no fueron publicados

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

    // Productos activos que NO están en tiendanube_producto
    $productos = $conn->query("
        SELECT
            p.id,
            p.nombre,
            p.precio_costo,
            MAX(CASE WHEN lp.tipo_lista = 'MINORISTA' THEN lp.precio END) AS precio_minorista
        FROM productos p
        LEFT JOIN lista_precio lp ON lp.id_producto = p.id
        WHERE p.activo = 1
          AND p.id NOT IN (SELECT id_producto FROM tiendanube_producto)
        GROUP BY p.id, p.nombre, p.precio_costo
        ORDER BY p.nombre ASC
    ")->fetch_all(MYSQLI_ASSOC);

    if (empty($productos)) {
        echo json_encode(['success' => true, 'mensaje' => 'Todos los productos ya están publicados.', 'publicados' => 0]);
        exit;
    }

    $stmtVariantes = $conn->prepare("
        SELECT pv.id, pv.nombre_variante, pv.color, pv.talle,
               COALESCE(pv.codigo_barras, p.codigo_barras) AS sku,
               COALESCE(sd.stock_actual, 0) AS stock
        FROM producto_variante pv
        INNER JOIN productos p ON p.id = pv.id_producto
        LEFT JOIN stock_deposito sd ON sd.id_variante = pv.id AND sd.id_deposito = ?
        WHERE pv.id_producto = ? AND pv.activo = 1
    ");

    $stmtProd = $conn->prepare("INSERT INTO tiendanube_producto (id_producto, tn_product_id, sincronizado_at) VALUES (?, ?, NOW())");
    $stmtVar  = $conn->prepare("INSERT IGNORE INTO tiendanube_variante (id_variante, tn_variant_id) VALUES (?, ?)");

    $publicados = 0;
    $errores    = [];

    foreach ($productos as $prod) {
        try {
            $stmtVariantes->bind_param('ii', $idDeposito, $prod['id']);
            $stmtVariantes->execute();
            $variantes = $stmtVariantes->get_result()->fetch_all(MYSQLI_ASSOC);

            if (empty($variantes)) continue;

            $precio = (float)($prod['precio_minorista'] ?? 0);

            // Detectar qué atributos usa este producto
            $usaColor = !empty(array_filter(array_column($variantes, 'color')));
            $usaTalle = !empty(array_filter(array_column($variantes, 'talle')));
            $tieneVariantesReales = $usaColor || $usaTalle;

            $tnVariantes = [];
            foreach ($variantes as $v) {
                $tnVar = [
                    'price' => number_format($precio, 2, '.', ''),
                    'stock' => (int)$v['stock'],
                ];
                if ($v['sku']) $tnVar['sku'] = $v['sku'];
                if ($tieneVariantesReales) {
                    $values = [];
                    if ($usaColor) $values[] = ['es' => $v['color'] ?? ''];
                    if ($usaTalle) $values[] = ['es' => $v['talle'] ?? ''];
                    $tnVar['values'] = $values;
                }
                $tnVariantes[] = $tnVar;
            }

            $body = [
                'name'     => ['es' => $prod['nombre']],
                'variants' => $tnVariantes,
            ];

            if ($tieneVariantesReales) {
                $attrs = [];
                if ($usaColor) $attrs[] = ['es' => 'Color'];
                if ($usaTalle) $attrs[] = ['es' => 'Talle'];
                $body['attributes'] = $attrs;
            }

            $tnProd = tn_request('POST', 'products', $body, $config);
            $tnProductId = (int)$tnProd['id'];

            $stmtProd->bind_param('ii', $prod['id'], $tnProductId);
            $stmtProd->execute();

            // Mapear variantes del sistema con variantes de TN
            $tnVars = $tnProd['variants'] ?? [];
            foreach ($variantes as $i => $v) {
                $tnVarId = (int)($tnVars[$i]['id'] ?? 0);
                if ($tnVarId) {
                    $stmtVar->bind_param('ii', $v['id'], $tnVarId);
                    $stmtVar->execute();
                }
            }

            $publicados++;

        } catch (Throwable $e) {
            $errores[] = $prod['nombre'] . ': ' . $e->getMessage();
        }
    }

    $stmtVariantes->close();
    $stmtProd->close();
    $stmtVar->close();

    echo json_encode([
        'success'   => true,
        'publicados' => $publicados,
        'errores'   => $errores,
    ]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
