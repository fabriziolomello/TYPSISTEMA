<?php
// app/controllers/ventas/guardar.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

try {
    // -------------------------
    // 1) Leer JSON del body
    // -------------------------
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data) {
        throw new Exception("JSON inválido o vacío");
    }

    $carrito       = $data['carrito']       ?? [];
    $pagos         = $data['pagos']         ?? [];
    $tipo_venta    = $data['tipo_venta']    ?? 'MINORISTA';
    $lista_precios = $data['lista_precios'] ?? 'MINORISTA';
    $total_venta   = (float)($data['total_venta']   ?? 0);
    $total_abonado = (float)($data['total_abonado'] ?? 0);
    $saldo         = (float)($data['saldo']         ?? 0);
    $clienteNombre = trim($data['cliente']         ?? '');
    $observaciones = $data['observaciones']        ?? '';

    $descGlobalPct   = (float)($data['descuento_global_porcentaje'] ?? 0);
    $descGlobalMonto = (float)($data['descuento_global_monto']      ?? 0);

    if (empty($carrito)) {
        throw new Exception("El carrito está vacío");
    }

    // -------------------------
    // 2) Conexión / transacción
    // -------------------------
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $db      = new Database();
    $mysqli  = $db->getConnection();
    $mysqli->set_charset('utf8mb4');

    $mysqli->begin_transaction();

    // -------------------------
    // 3) Usuario logueado
    // -------------------------
    $idUsuario = $_SESSION['usuario']['id']
        ?? $_SESSION['usuario_id']
        ?? $_SESSION['id_usuario']
        ?? null;

    if (!$idUsuario) {
        throw new Exception("No se encontró el usuario en la sesión");
    }

    // -------------------------
    // 4) Caja abierta
    // -------------------------
    $sqlCaja = "
        SELECT id
        FROM caja
        WHERE estado = 'ABIERTA'
        ORDER BY fecha DESC
        LIMIT 1
    ";
    $resCaja = $mysqli->query($sqlCaja);

    if ($resCaja->num_rows === 0) {
        throw new Exception("No hay caja abierta. Abrí una caja antes de registrar ventas.");
    }

    $rowCaja = $resCaja->fetch_assoc();
    $idCaja  = (int)$rowCaja['id'];

    // -------------------------
    // 5) Cliente (opcional)
    // -------------------------
    $idCliente = null;

    if ($clienteNombre !== '' && strtolower($clienteNombre) !== 'consumidor final') {
        $stmtCli = $mysqli->prepare("
            SELECT id
            FROM clientes
            WHERE nombre = ?
            LIMIT 1
        ");
        $stmtCli->bind_param('s', $clienteNombre);
        $stmtCli->execute();
        $stmtCli->bind_result($idCliTmp);
        if ($stmtCli->fetch()) {
            $idCliente = (int)$idCliTmp;
        }
        $stmtCli->close();
    }

    // -------------------------
    // 6) Estado de pago
    // -------------------------
    if ($total_abonado <= 0) {
        $estado_pago = 'PENDIENTE';
    } elseif ($saldo > 0.00001) {
        $estado_pago = 'PARCIAL';
    } else {
        $estado_pago = 'PAGADA';
    }

    // -------------------------
    // 7) Insert en VENTAS
    // -------------------------
    $stmtVenta = $mysqli->prepare("
        INSERT INTO ventas
            (fecha_hora, id_usuario, id_cliente, id_caja, tipo_venta, total, estado_pago, observaciones)
        VALUES
            (NOW(), ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmtVenta->bind_param(
        'iiisdss',
        $idUsuario,
        $idCliente,
        $idCaja,
        $tipo_venta,
        $total_venta,
        $estado_pago,
        $observaciones
    );

    $stmtVenta->execute();
    $idVenta = $mysqli->insert_id;
    $stmtVenta->close();

    // -------------------------
    // 8) Preparar consultas auxiliares
    // -------------------------

    // precio de lista (para id_lista_precio)
    $stmtLista = $mysqli->prepare("
        SELECT id, precio
        FROM lista_precio
        WHERE id_producto = ? AND tipo_lista = ?
        LIMIT 1
    ");

    // detalle_ventas
    $stmtDet = $mysqli->prepare("
        INSERT INTO detalle_ventas
            (id_venta, id_variante, id_lista_precio, cantidad, precio_unitario, descuento, subtotal)
        VALUES
            (?, ?, ?, ?, ?, ?, ?)
    ");

    // movimiento_stock
    $stmtStock = $mysqli->prepare("
        INSERT INTO movimiento_stock
            (fecha_hora, id_variante, tipo, cantidad, id_venta, observaciones)
        VALUES
            (NOW(), ?, 'VENTA', ?, ?, '')
    ");

    // actualizar stock en productos
    $stmtUpdateProd = $mysqli->prepare("
        UPDATE productos
        SET stock_actual = stock_actual - ?
        WHERE id = ?
    ");

    // actualizar stock en producto_variante
    $stmtUpdateVariante = $mysqli->prepare("
        UPDATE producto_variante
        SET stock_actual = stock_actual - ?
        WHERE id = ?
    ");

    // -------------------------
    // 9) Recorrer carrito
    // -------------------------
    foreach ($carrito as $item) {
        $idProducto = (int)($item['id_producto'] ?? 0);
        $idVariante = (int)($item['id_variante'] ?? 0);
        $cantidad   = (int)($item['cantidad'] ?? 0);

        if ($idProducto <= 0 || $idVariante <= 0) {
            throw new Exception("Faltan datos de producto/variante en el carrito.");
        }

        if ($cantidad <= 0) {
            throw new Exception("Cantidad inválida para producto ID $idProducto / variante ID $idVariante");
        }

        // Buscar id_lista_precio
        $stmtLista->bind_param('is', $idProducto, $lista_precios);
        $stmtLista->execute();
        $stmtLista->bind_result($idListaPrecio, $precioLista);

        if (!$stmtLista->fetch()) {
            throw new Exception("No se encontró precio de lista '$lista_precios' para producto ID $idProducto");
        }
        $stmtLista->free_result();

        // Datos desde el POS
        $precioUnitario = (float)($item['precio_unitario'] ?? $precioLista);
        $subtotal       = (float)($item['subtotal'] ?? ($precioUnitario * $cantidad));

        // Descuento como monto (incluye desc. por línea + global prorrateado)
        $descuento = ($precioUnitario * $cantidad) - $subtotal;

        // Insertar DETALLE_VENTAS
        $stmtDet->bind_param(
            'iiiiddd',
            $idVenta,
            $idVariante,
            $idListaPrecio,
            $cantidad,
            $precioUnitario,
            $descuento,
            $subtotal
        );
        $stmtDet->execute();

        // Insertar MOVIMIENTO_STOCK
        $stmtStock->bind_param('iii', $idVariante, $cantidad, $idVenta);
        $stmtStock->execute();

        // Actualizar stock en PRODUCTOS
        $stmtUpdateProd->bind_param('ii', $cantidad, $idProducto);
        $stmtUpdateProd->execute();

        // Actualizar stock en PRODUCTO_VARIANTE
        $stmtUpdateVariante->bind_param('ii', $cantidad, $idVariante);
        $stmtUpdateVariante->execute();
    }

    $stmtLista->close();
    $stmtDet->close();
    $stmtStock->close();
    $stmtUpdateProd->close();
    $stmtUpdateVariante->close();

    // -------------------------
    // 10) Movimientos de caja
    // -------------------------
    if (!empty($pagos)) {
        $stmtCaja = $mysqli->prepare("
            INSERT INTO movimiento_caja
                (id_caja, id_venta, fecha_hora, tipo, monto, medio_pago, referencia, id_usuario)
            VALUES
                (?, ?, NOW(), 'VENTA', ?, ?, '', ?)
        ");

        foreach ($pagos as $pago) {
            $monto  = (float)($pago['monto']  ?? 0);
            $metodo = $pago['metodo']        ?? 'EFECTIVO';

            if ($monto <= 0) {
                continue;
            }

            $stmtCaja->bind_param('iidsi', $idCaja, $idVenta, $monto, $metodo, $idUsuario);
            $stmtCaja->execute();
        }

        $stmtCaja->close();
    }

    // -------------------------
    // 11) Actualizar saldo cliente (si queda saldo pendiente)
    // -------------------------
    if ($idCliente && $saldo > 0) {
        $stmtCliSaldo = $mysqli->prepare("
            UPDATE clientes
            SET saldo_pendiente = saldo_pendiente + ?
            WHERE id = ?
        ");
        $stmtCliSaldo->bind_param('di', $saldo, $idCliente);
        $stmtCliSaldo->execute();
        $stmtCliSaldo->close();
    }

    // -------------------------
    // 12) Commit y respuesta
    // -------------------------
    $mysqli->commit();

    echo json_encode([
        'success'  => true,
        'id_venta' => $idVenta,
    ]);
    exit;

} catch (Throwable $e) {

    if (isset($mysqli)) {
        try {
            $mysqli->rollback();
        } catch (Throwable $ignored) {
            // ignoramos errores de rollback
        }
    }

    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
    exit;
}