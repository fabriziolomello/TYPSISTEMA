<?php
// app/controllers/tiendanube/publicar.php
// Publica en TN los productos del sistema que aún no fueron publicados (en lotes)

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/api.php';

$_esAdmin = ($_SESSION['usuario_rol'] ?? '') === 'ADMIN';
$_dep     = (int)($_SESSION['usuario_deposito'] ?? 0);
if (!$_esAdmin && $_dep !== 1) {
    echo json_encode(['success' => false, 'error' => 'Sin permisos']);
    exit;
}

try {
    $db   = new Database();
    $conn = $db->getConnection();

    $config     = tn_get_config($conn);
    $idDeposito = (int)$config['id_deposito'];
    $lote       = 20;

    // Cuántos quedan pendientes en total
    $restantes = (int)$conn->query("
        SELECT COUNT(*) FROM productos
        WHERE activo = 1 AND sincronizar_tn = 1
          AND id NOT IN (SELECT id_producto FROM tiendanube_producto)
    ")->fetch_row()[0];

    if ($restantes === 0) {
        echo json_encode(['success' => true, 'publicados' => 0, 'restantes' => 0, 'hay_mas' => false]);
        exit;
    }

    // Solo el lote actual
    $productos = $conn->query("
        SELECT
            p.id,
            p.nombre,
            p.codigo_barras,
            p.stock_actual,
            p.precio_costo,
            MAX(CASE WHEN lp.tipo_lista = 'MINORISTA' THEN lp.precio END) AS precio_minorista
        FROM productos p
        LEFT JOIN lista_precio lp ON lp.id_producto = p.id
        WHERE p.activo = 1
          AND p.sincronizar_tn = 1
          AND p.id NOT IN (SELECT id_producto FROM tiendanube_producto)
        GROUP BY p.id, p.nombre, p.codigo_barras, p.stock_actual, p.precio_costo
        ORDER BY p.nombre ASC
        LIMIT $lote
    ")->fetch_all(MYSQLI_ASSOC);

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

            // Sin variantes en BD: publicar como producto de variante única
            if (empty($variantes)) {
                $variantes = [[
                    'id'    => 0,
                    'sku'   => $prod['codigo_barras'] ?? null,
                    'stock' => (int)($prod['stock_actual'] ?? 0),
                    'color' => null,
                    'talle' => null,
                ]];
            }

            $precio = (float)($prod['precio_minorista'] ?? 0);

            $usaColor = !empty(array_filter(array_column($variantes, 'color')));
            $usaTalle = !empty(array_filter(array_column($variantes, 'talle')));
            $tieneVariantesReales = $usaColor || $usaTalle;

            // Si múltiples variantes comparten el mismo SKU (heredado del producto), no enviar SKU
            // para evitar 422 por duplicado en TiendaNube
            $todosSkus     = array_filter(array_column($variantes, 'sku'));
            $skuDuplicado  = count($todosSkus) > 1 && count($todosSkus) !== count(array_unique($todosSkus));

            $tnVariantes = [];
            foreach ($variantes as $v) {
                $tnVar = [
                    'price' => number_format($precio, 2, '.', ''),
                    'stock' => max(0, (int)$v['stock']),
                ];
                if ($v['sku'] && !$skuDuplicado) $tnVar['sku'] = $v['sku'];
                if ($tieneVariantesReales) {
                    $values = [];
                    if ($usaColor) $values[] = ['es' => !empty($v['color']) ? $v['color'] : 'Único'];
                    if ($usaTalle) $values[] = ['es' => !empty($v['talle']) ? $v['talle'] : 'Único'];
                    $tnVar['values'] = $values;
                }
                $tnVariantes[] = $tnVar;
            }

            $body = ['name' => ['es' => $prod['nombre']], 'variants' => $tnVariantes];

            if ($tieneVariantesReales) {
                $attrs = [];
                if ($usaColor) $attrs[] = ['es' => 'Color'];
                if ($usaTalle) $attrs[] = ['es' => 'Talle'];
                $body['attributes'] = $attrs;
            }

            $tnProd      = tn_request('POST', 'products', $body, $config);
            $tnProductId = (int)$tnProd['id'];

            $stmtProd->bind_param('ii', $prod['id'], $tnProductId);
            $stmtProd->execute();

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

    // Cuántos quedan después de este lote
    $restantes = (int)$conn->query("
        SELECT COUNT(*) FROM productos
        WHERE activo = 1 AND sincronizar_tn = 1
          AND id NOT IN (SELECT id_producto FROM tiendanube_producto)
    ")->fetch_row()[0];

    echo json_encode([
        'success'    => true,
        'publicados' => $publicados,
        'restantes'  => $restantes,
        'hay_mas'    => $restantes > 0,
        'errores'    => $errores,
    ]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
