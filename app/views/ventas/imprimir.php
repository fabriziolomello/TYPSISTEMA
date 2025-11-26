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

// ------------------------------
// 1) Datos de la venta + cliente
// ------------------------------
$sqlVenta = "
    SELECT
        v.*,
        c.nombre AS nombre_cliente
    FROM ventas v
    LEFT JOIN clientes c ON v.id_cliente = c.id
    WHERE v.id = ?
";

$stmtVenta = $conn->prepare($sqlVenta);
$stmtVenta->bind_param('i', $id_venta);
$stmtVenta->execute();
$resultVenta = $stmtVenta->get_result();

if ($resultVenta->num_rows === 0) {
    die("Venta no encontrada.");
}

$venta = $resultVenta->fetch_assoc();
$stmtVenta->close();

// ------------------------------
// 2) Detalle de productos
// ------------------------------
$sqlItems = "
    SELECT
        dv.cantidad,
        dv.precio_unitario,
        dv.subtotal,
        p.nombre        AS nombre_producto,
        pv.nombre_variante
    FROM detalle_ventas dv
    INNER JOIN producto_variante pv
        ON dv.id_variante = pv.id
    INNER JOIN productos p
        ON pv.id_producto = p.id
    WHERE dv.id_venta = ?
";

$stmtItems = $conn->prepare($sqlItems);
$stmtItems->bind_param('i', $id_venta);
$stmtItems->execute();
$resultItems = $stmtItems->get_result();

$items = [];
while ($row = $resultItems->fetch_assoc()) {
    $nombreProducto = $row['nombre_producto'];

    // Si tiene variante distinta de 'UNICA', la agregamos entre paréntesis
    if (!empty($row['nombre_variante']) && strtoupper($row['nombre_variante']) !== 'UNICA') {
        $nombreProducto .= ' (' . $row['nombre_variante'] . ')';
    }

    $items[] = [
        'producto' => $nombreProducto,
        'cantidad' => $row['cantidad'],
        'precio'   => $row['precio_unitario'],
        'subtotal' => $row['subtotal'],
    ];
}
$stmtItems->close();

// ------------------------------
// 3) Cobros (movimiento_caja)
// ------------------------------
$sqlPagos = "
    SELECT medio_pago, monto
    FROM movimiento_caja
    WHERE id_venta = ?
      AND tipo = 'VENTA'
    ORDER BY id ASC
";

$stmtPagos = $conn->prepare($sqlPagos);
$stmtPagos->bind_param('i', $id_venta);
$stmtPagos->execute();
$resultPagos = $stmtPagos->get_result();

$pagos = [];
$total_cobrado = 0;

while ($row = $resultPagos->fetch_assoc()) {
    $pagos[] = $row;
    $total_cobrado += (float)$row['monto'];
}

$stmtPagos->close();

$saldo_pendiente = (float)$venta['total'] - $total_cobrado;

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Ticket venta #<?= htmlspecialchars($venta['id']) ?></title>
  <style>
    body {
      font-family: Arial, sans-serif;
      font-size: 12px;
      margin: 0;
      padding: 0;
    }
    .ticket {
      width: 320px;
      margin: 0 auto;
      padding: 10px;
    }
    .ticket-header {
      text-align: center;
      margin-bottom: 10px;
    }
    .ticket-header h1 {
      font-size: 16px;
      margin: 0 0 4px 0;
    }
    .ticket-header small {
      display: block;
      color: #555;
    }
    .ticket-info {
      margin-bottom: 8px;
    }
    .ticket-info div {
      margin-bottom: 2px;
    }
    .ticket-info strong {
      font-weight: 600;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 11px;
      margin-top: 8px;
    }
    th, td {
      padding: 3px 0;
    }
    th {
      border-bottom: 1px solid #000;
      text-align: left;
    }
    .col-cant {
      width: 18%;
      text-align: center;
    }
    .col-precio,
    .col-sub {
      width: 22%;
      text-align: right;
    }
    tfoot td {
      border-top: 1px solid #000;
      font-weight: 600;
    }
    .totales {
      margin-top: 8px;
      text-align: right;
    }
    .totales div {
      margin-bottom: 2px;
    }
    .totales strong {
      min-width: 100px;
      display: inline-block;
    }
    .center {
      text-align: center;
      margin-top: 10px;
    }
  </style>
</head>
<body onload="window.print()">
  <div class="ticket">
    <div class="ticket-header">
      <h1>Sistema TyP</h1>
      <small>Ticket de venta</small>
    </div>

    <div class="ticket-info">
      <div><strong>Venta #:</strong> <?= (int)$venta['id'] ?></div>
      <div><strong>Fecha:</strong> <?= date('d/m/Y H:i', strtotime($venta['fecha_hora'])) ?></div>
      <div><strong>Cliente:</strong> <?= htmlspecialchars($venta['nombre_cliente'] ?? 'CONSUMIDOR FINAL') ?></div>
      <div><strong>Tipo de venta:</strong> <?= htmlspecialchars($venta['tipo_venta']) ?></div>
      <div><strong>Estado:</strong> <?= htmlspecialchars($venta['estado_pago']) ?></div>
    </div>

    <table>
      <thead>
        <tr>
          <th>Producto</th>
          <th class="col-cant">Cant.</th>
          <th class="col-precio">Precio</th>
          <th class="col-sub">Subt.</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($items)): ?>
          <tr>
            <td colspan="4">Sin detalle de productos.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($items as $item): ?>
            <tr>
              <td><?= htmlspecialchars($item['producto']) ?></td>
              <td class="col-cant"><?= (int)$item['cantidad'] ?></td>
              <td class="col-precio">
                $<?= number_format($item['precio'], 2, ',', '.') ?>
              </td>
              <td class="col-sub">
                $<?= number_format($item['subtotal'], 2, ',', '.') ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="totales">
      <div><strong>Total venta:</strong> $<?= number_format($venta['total'], 2, ',', '.') ?></div>
      <div><strong>Total cobrado:</strong> $<?= number_format($total_cobrado, 2, ',', '.') ?></div>
      <div><strong>Saldo pendiente:</strong> $<?= number_format($saldo_pendiente, 2, ',', '.') ?></div>
    </div>

    <?php if (!empty($pagos)): ?>
      <div style="margin-top: 6px;">
        <strong>Pagos registrados:</strong>
        <?php foreach ($pagos as $p): ?>
          <div>
            - <?= htmlspecialchars($p['medio_pago']) ?>:
            $<?= number_format($p['monto'], 2, ',', '.') ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="center">
      ¡Gracias por su compra!
    </div>
  </div>
</body>
</html>