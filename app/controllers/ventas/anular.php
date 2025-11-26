<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

if (!isset($_GET['id'])) {
    die("ID de venta inválido.");
}

$id_venta = (int) $_GET['id'];
if ($id_venta <= 0) {
    die("ID de venta inválido.");
}

$db   = new Database();
$conn = $db->getConnection();

try {
    // Usamos transacción para que todo sea atómico
    $conn->begin_transaction();

    // 1) Traer detalle de la venta
    $sqlItems = "
        SELECT id_variante, cantidad
        FROM detalle_ventas
        WHERE id_venta = ?
    ";
    $stmtItems = $conn->prepare($sqlItems);
    $stmtItems->bind_param('i', $id_venta);
    $stmtItems->execute();
    $resItems = $stmtItems->get_result();

    // 2) Devolver stock en producto_variante (columna stock_actual)
    while ($row = $resItems->fetch_assoc()) {
        $idVariante = (int)$row['id_variante'];
        $cantidad   = (int)$row['cantidad'];

        $sqlStock = "
            UPDATE producto_variante
            SET stock_actual = stock_actual + ?
            WHERE id = ?
        ";
        $stmtStock = $conn->prepare($sqlStock);
        $stmtStock->bind_param('ii', $cantidad, $idVariante);
        $stmtStock->execute();
        $stmtStock->close();
    }

    $stmtItems->close();

    // 3) Eliminar movimientos de caja asociados a esta venta
    $sqlDeleteCaja = "
        DELETE FROM movimiento_caja
        WHERE id_venta = ?
    ";
    $stmtCaja = $conn->prepare($sqlDeleteCaja);
    $stmtCaja->bind_param('i', $id_venta);
    $stmtCaja->execute();
    $stmtCaja->close();

    // 4) Marcar venta como ANULADA
    $sqlVenta = "
        UPDATE ventas
        SET estado_pago = 'ANULADA'
        WHERE id = ?
    ";
    $stmtVenta = $conn->prepare($sqlVenta);
    $stmtVenta->bind_param('i', $id_venta);
    $stmtVenta->execute();
    $stmtVenta->close();

    // 5) Confirmar transacción
    $conn->commit();

    // 6) Volver al dashboard
    header("Location: /TYPSISTEMA/app/views/dashboard/index.php");
    exit;

} catch (Throwable $e) {
    // Si algo falla, revertimos todo
    $conn->rollback();
    echo "Error al anular la venta: " . $e->getMessage();
}