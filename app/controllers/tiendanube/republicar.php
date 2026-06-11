<?php
// app/controllers/tiendanube/republicar.php
// Borra un producto de TN y lo vuelve a publicar con los datos actuales

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

$body      = json_decode(file_get_contents('php://input'), true);
$idProd    = (int)($body['id_producto'] ?? 0);

if (!$idProd) {
    echo json_encode(['success' => false, 'error' => 'id_producto requerido']);
    exit;
}

try {
    $db   = new Database();
    $conn = $db->getConnection();

    $config     = tn_get_config($conn);
    $idDeposito = (int)$config['id_deposito'];

    // Obtener datos del producto
    $stmt = $conn->prepare("
        SELECT p.id, p.nombre, p.codigo_barras, p.stock_actual,
               MAX(CASE WHEN lp.tipo_lista = 'MINORISTA' THEN lp.precio END) AS precio_minorista
        FROM productos p
        LEFT JOIN lista_precio lp ON lp.id_producto = p.id
        WHERE p.id = ? AND p.activo = 1
        GROUP BY p.id, p.nombre, p.codigo_barras, p.stock_actual
    ");
    $stmt->bind_param('i', $idProd);
    $stmt->execute();
    $prod = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$prod) {
        echo json_encode(['success' => false, 'error' => 'Producto no encontrado']);
        exit;
    }

    // Buscar si tiene publicación previa en TN
    $stmt = $conn->prepare("SELECT tn_product_id FROM tiendanube_producto WHERE id_producto = ?");
    $stmt->bind_param('i', $idProd);
    $stmt->execute();
    $tnRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Borrar en TN si existe (ignorar error si ya fue borrado manualmente)
    if ($tnRow) {
        try {
            tn_request('DELETE', 'products/' . (int)$tnRow['tn_product_id'], [], $config);
        } catch (Throwable $e) {
            // Si ya no existe en TN no es un error bloqueante
        }

        // Limpiar registros locales
        $conn->query("DELETE FROM tiendanube_variante WHERE id_variante IN (
            SELECT id FROM producto_variante WHERE id_producto = $idProd
        )");
        $conn->query("DELETE FROM tiendanube_producto WHERE id_producto = $idProd");
    }

    // Obtener variantes activas
    $stmtVar = $conn->prepare("
        SELECT pv.id, pv.color, pv.talle,
               COALESCE(pv.codigo_barras, p.codigo_barras) AS sku,
               COALESCE(sd.stock_actual, 0) AS stock
        FROM producto_variante pv
        INNER JOIN productos p ON p.id = pv.id_producto
        LEFT JOIN stock_deposito sd ON sd.id_variante = pv.id AND sd.id_deposito = ?
        WHERE pv.id_producto = ? AND pv.activo = 1
    ");
    $stmtVar->bind_param('ii', $idDeposito, $idProd);
    $stmtVar->execute();
    $variantes = $stmtVar->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtVar->close();

    if (empty($variantes)) {
        $variantes = [[
            'id'    => 0,
            'sku'   => $prod['codigo_barras'] ?? null,
            'stock' => (int)($prod['stock_actual'] ?? 0),
            'color' => null,
            'talle' => null,
        ]];
    }

    $precio   = (float)($prod['precio_minorista'] ?? 0);
    $usaColor = !empty(array_filter(array_column($variantes, 'color')));
    $usaTalle = !empty(array_filter(array_column($variantes, 'talle')));
    $tieneVariantesReales = $usaColor || $usaTalle;

    $todosSkus    = array_filter(array_column($variantes, 'sku'));
    $skuDuplicado = count($todosSkus) > 1 && count($todosSkus) !== count(array_unique($todosSkus));

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

    $tnBody = ['name' => ['es' => $prod['nombre']], 'variants' => $tnVariantes];
    if ($tieneVariantesReales) {
        $attrs = [];
        if ($usaColor) $attrs[] = ['es' => 'Color'];
        if ($usaTalle) $attrs[] = ['es' => 'Talle'];
        $tnBody['attributes'] = $attrs;
    }

    $tnProd      = tn_request('POST', 'products', $tnBody, $config);
    $tnProductId = (int)$tnProd['id'];

    $stmtProd = $conn->prepare("INSERT INTO tiendanube_producto (id_producto, tn_product_id, sincronizado_at) VALUES (?, ?, NOW())");
    $stmtProd->bind_param('ii', $idProd, $tnProductId);
    $stmtProd->execute();
    $stmtProd->close();

    $stmtVarMap = $conn->prepare("INSERT IGNORE INTO tiendanube_variante (id_variante, tn_variant_id) VALUES (?, ?)");
    $tnVars = $tnProd['variants'] ?? [];
    foreach ($variantes as $i => $v) {
        $tnVarId = (int)($tnVars[$i]['id'] ?? 0);
        if ($tnVarId && $v['id']) {
            $stmtVarMap->bind_param('ii', $v['id'], $tnVarId);
            $stmtVarMap->execute();
        }
    }
    $stmtVarMap->close();

    echo json_encode(['success' => true, 'tn_product_id' => $tnProductId]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
