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
    // Probamos varias claves posibles de sesión
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
        // Si no existe, dejamos id_cliente NULL por ahora
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

    // i = int, d = double, s = string
    $stmtVenta->bind_param(
        'iiisdss',
        $idUsuario,
        $idCliente,   // puede ser NULL
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

    // precio de lista minorista/mayorista
    $stmtLista = $mysqli->prepare("
        SELECT id, precio
        FROM lista_precio
        WHERE id_producto = ? AND tipo_lista = ?
        LIMIT 1
    ");

    // variante (para FK id_variante)
    $stmtVariante = $mysqli->prepare("
        SELECT id
        FROM producto_variante
        WHERE id_producto = ?
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
        // El POS manda el id de PRODUCTO en item['id']
        $idProducto = (int)$item['id'];
        $cantidad   = (int)$item['qty'];

        if ($cantidad <= 0) {
            throw new Exception("Cantidad inválida para producto ID $idProducto");
        }

        // 9.1) Buscar VARIANTE asociada a ese producto
        $stmtVariante->bind_param('i', $idProducto);
        $stmtVariante->execute();
        $stmtVariante->bind_result($idVariante);

        if (!$stmtVariante->fetch()) {
            throw new Exception("No se encontró variante para el producto ID $idProducto");
        }
        $stmtVariante->free_result();

        // 9.2) Buscar precio de lista según MINORISTA/MAYORISTA
        $stmtLista->bind_param('is', $idProducto, $lista_precios);
        $stmtLista->execute();
        $stmtLista->bind_result($idListaPrecio, $precioLista);

        if (!$stmtLista->fetch()) {
            throw new Exception("No se encontró precio de lista '$lista_precios' para producto ID $idProducto");
        }
        $stmtLista->free_result();

        $precioUnitario = (float)$precioLista;
        $descuento      = 0.0;
        $subtotal       = $precioUnitario * $cantidad;

        // 9.3) Insertar DETALLE_VENTAS (usa idVariante, no idProducto)
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

        // 9.4) Insertar MOVIMIENTO_STOCK
        $stmtStock->bind_param('iii', $idVariante, $cantidad, $idVenta);
        $stmtStock->execute();

        // 9.5) Actualizar stock en PRODUCTOS (para consultas generales)
        $stmtUpdateProd->bind_param('ii', $cantidad, $idProducto);
        $stmtUpdateProd->execute();

        // 9.6) Actualizar stock en PRODUCTO_VARIANTE (para respetar la FK)
        $stmtUpdateVariante->bind_param('ii', $cantidad, $idVariante);
        $stmtUpdateVariante->execute();
    }

    $stmtLista->close();
    $stmtVariante->close();
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

            // i = id_caja, i = id_venta, d = monto, s = medio_pago, i = id_usuario
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
        // si se abrió conexión, intentamos rollback
        $mysqli->rollback();
    }

    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
    exit;
}