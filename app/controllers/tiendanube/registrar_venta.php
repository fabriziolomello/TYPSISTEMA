<?php
// app/controllers/tiendanube/registrar_venta.php
// Convierte un pedido de Tienda Nube en una venta del sistema

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

$data       = json_decode(file_get_contents('php://input'), true);
$tnOrderId  = (int)($data['tn_order_id'] ?? 0);

if (!$tnOrderId) {
    echo json_encode(['success' => false, 'error' => 'ID de pedido inválido']);
    exit;
}

try {
    $db   = new Database();
    $conn = $db->getConnection();
    $conn->set_charset('utf8mb4');

    // Verificar que no esté ya registrado
    $yaReg = $conn->query("SELECT id_venta FROM tiendanube_pedido WHERE tn_order_id = $tnOrderId")->fetch_assoc();
    if ($yaReg) {
        echo json_encode(['success' => false, 'error' => "Este pedido ya fue registrado como venta #{$yaReg['id_venta']}"]);
        exit;
    }

    $config     = tn_get_config($conn);
    $idDeposito = (int)$config['id_deposito'];

    // Traer pedido desde TN
    $pedido = tn_request('GET', "orders/{$tnOrderId}", [], $config);
    if (empty($pedido['id'])) {
        echo json_encode(['success' => false, 'error' => 'No se encontró el pedido en Tienda Nube']);
        exit;
    }

    $items = $pedido['products'] ?? [];
    if (empty($items)) {
        echo json_encode(['success' => false, 'error' => 'El pedido no tiene productos']);
        exit;
    }

    // Mapear variantes TN → variantes locales
    $carrito    = [];
    $sinMapear  = [];

    foreach ($items as $item) {
        $tnVarId  = (int)($item['variant_id'] ?? 0);
        $cantidad = (int)($item['quantity']   ?? 1);
        $precio   = (float)($item['price']    ?? 0);

        if (!$tnVarId) { $sinMapear[] = $item['name'] ?? 'desconocido'; continue; }

        $fila = $conn->query("
            SELECT tv.id_variante, pv.id_producto
            FROM tiendanube_variante tv
            INNER JOIN producto_variante pv ON pv.id = tv.id_variante
            WHERE tv.tn_variant_id = $tnVarId
            LIMIT 1
        ")->fetch_assoc();

        if (!$fila) { $sinMapear[] = $item['name'] ?? "variante TN $tnVarId"; continue; }

        $carrito[] = [
            'id_variante'  => (int)$fila['id_variante'],
            'id_producto'  => (int)$fila['id_producto'],
            'cantidad'     => $cantidad,
            'precio'       => $precio,
        ];
    }

    if (empty($carrito)) {
        echo json_encode(['success' => false, 'error' => 'Ningún producto del pedido está mapeado en el sistema. Productos sin mapear: ' . implode(', ', $sinMapear)]);
        exit;
    }

    // Caja abierta del depósito TN
    $rowCaja = $conn->query("
        SELECT id FROM caja
        WHERE estado = 'ABIERTA' AND id_sucursal = $idDeposito
        ORDER BY fecha DESC LIMIT 1
    ")->fetch_assoc();

    if (!$rowCaja) {
        echo json_encode(['success' => false, 'error' => 'No hay caja abierta en el depósito configurado para Tienda Nube']);
        exit;
    }

    $idCaja    = (int)$rowCaja['id'];
    $idUsuario = (int)($_SESSION['usuario']['id'] ?? $_SESSION['usuario_id'] ?? 0);
    $total     = (float)($pedido['total'] ?? array_sum(array_map(fn($i) => $i['precio'] * $i['cantidad'], $carrito)));
    $nroPedido = $pedido['number'] ?? $tnOrderId;
    $obsVenta  = "TiendaNube #{$nroPedido}";

    // Método de pago TN → sistema
    $metodoPago = 'TRANSFERENCIA';
    $gateway    = strtolower($pedido['payment_details']['method'] ?? $pedido['gateway'] ?? '');
    if (str_contains($gateway, 'mercadopago') || str_contains($gateway, 'mercado')) $metodoPago = 'MERCADOPAGO';
    elseif (str_contains($gateway, 'efectivo') || str_contains($gateway, 'cash'))   $metodoPago = 'EFECTIVO';
    elseif (str_contains($gateway, 'tarjeta') || str_contains($gateway, 'card'))    $metodoPago = 'TARJETA';

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->begin_transaction();

    // INSERT venta
    $stmtV = $conn->prepare("
        INSERT INTO ventas (fecha_hora, id_usuario, id_cliente, id_caja, id_sucursal, tipo_venta, total, estado_pago, observaciones)
        VALUES (NOW(), ?, NULL, ?, ?, ?, 'MINORISTA', ?, 'PAGADA', ?)
    ");
    $stmtV->bind_param('iiiids', $idUsuario, $idCaja, $idDeposito, $idDeposito, $total, $obsVenta);
    $stmtV->execute();
    $idVenta = $conn->insert_id;
    $stmtV->close();

    $stmtDet   = $conn->prepare("INSERT INTO detalle_ventas (id_venta, id_variante, id_lista_precio, cantidad, precio_unitario, descuento, subtotal) VALUES (?, ?, NULL, ?, ?, 0, ?)");
    $stmtMov   = $conn->prepare("INSERT INTO movimiento_stock (fecha_hora, id_variante, tipo, cantidad, id_venta, observaciones, id_deposito) VALUES (NOW(), ?, 'VENTA', ?, ?, '', ?)");
    $stmtProd  = $conn->prepare("UPDATE productos SET stock_actual = stock_actual - ? WHERE id = ?");
    $stmtVar   = $conn->prepare("UPDATE producto_variante SET stock_actual = stock_actual - ? WHERE id = ?");
    $stmtDep   = $conn->prepare("INSERT INTO stock_deposito (id_variante, id_deposito, stock_actual) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE stock_actual = GREATEST(0, stock_actual - ?)");

    foreach ($carrito as $it) {
        $subtotal = $it['precio'] * $it['cantidad'];
        $stmtDet->bind_param('iiiddd', $idVenta, $it['id_variante'], $it['cantidad'], $it['precio'], $subtotal);
        $stmtDet->execute();
        $stmtMov->bind_param('iiii', $it['id_variante'], $it['cantidad'], $idVenta, $idDeposito);
        $stmtMov->execute();
        $stmtProd->bind_param('ii', $it['cantidad'], $it['id_producto']);
        $stmtProd->execute();
        $stmtVar->bind_param('ii', $it['cantidad'], $it['id_variante']);
        $stmtVar->execute();
        $stmtDep->bind_param('iii', $it['id_variante'], $idDeposito, $it['cantidad']);
        $stmtDep->execute();
    }

    $stmtDet->close(); $stmtMov->close(); $stmtProd->close(); $stmtVar->close(); $stmtDep->close();

    // Movimiento de caja
    $stmtCaja = $conn->prepare("INSERT INTO movimiento_caja (id_caja, id_venta, fecha_hora, tipo, monto, medio_pago, referencia, id_usuario) VALUES (?, ?, NOW(), 'VENTA', ?, ?, '', ?)");
    $stmtCaja->bind_param('iidsi', $idCaja, $idVenta, $total, $metodoPago, $idUsuario);
    $stmtCaja->execute();
    $stmtCaja->close();

    // Registrar mapeo pedido → venta
    $stmtPed = $conn->prepare("INSERT INTO tiendanube_pedido (tn_order_id, id_venta) VALUES (?, ?)");
    $stmtPed->bind_param('ii', $tnOrderId, $idVenta);
    $stmtPed->execute();
    $stmtPed->close();

    $conn->commit();

    $aviso = empty($sinMapear) ? null : 'Productos sin mapear (no descontados): ' . implode(', ', $sinMapear);

    echo json_encode(['success' => true, 'id_venta' => $idVenta, 'aviso' => $aviso]);

} catch (Throwable $e) {
    if (isset($conn)) { try { $conn->rollback(); } catch (Throwable $ignored) {} }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
