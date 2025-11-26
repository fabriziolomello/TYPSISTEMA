<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$db   = new Database();
$conn = $db->getConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die("Venta no válida.");
}

// -----------------------------
// 1) Datos de la venta + cliente
// -----------------------------
$sqlVenta = "
    SELECT 
        v.*,
        c.nombre AS nombre_cliente
    FROM ventas v
    LEFT JOIN clientes c ON v.id_cliente = c.id
    WHERE v.id = {$id}
    LIMIT 1
";

$resVenta = $conn->query($sqlVenta);

if (!$resVenta || $resVenta->num_rows === 0) {
    die("Venta no encontrada.");
}

$venta = $resVenta->fetch_assoc();

// -----------------------------
// 2) Detalle de la venta
//    detalle_ventas -> producto_variante -> productos
// -----------------------------
$sqlDetalle = "
    SELECT 
        dv.*,
        pv.nombre_variante,
        p.nombre AS nombre_producto
    FROM detalle_ventas dv
    LEFT JOIN producto_variante pv 
        ON dv.id_variante = pv.id
    LEFT JOIN productos p 
        ON pv.id_producto = p.id
    WHERE dv.id_venta = {$id}
";

$resDetalle = $conn->query($sqlDetalle);
$items = [];

if ($resDetalle && $resDetalle->num_rows > 0) {
    while ($row = $resDetalle->fetch_assoc()) {
        $items[] = $row;
    }
}

// -----------------------------
// 3) Total cobrado y saldo
// -----------------------------
$sqlCobrado = "
    SELECT COALESCE(SUM(mc.monto), 0) AS total_cobrado
    FROM movimiento_caja mc
    WHERE mc.id_venta = {$id}
      AND mc.tipo = 'VENTA'
";

$resCobrado = $conn->query($sqlCobrado);
$totalCobrado = 0;

if ($resCobrado && $resCobrado->num_rows > 0) {
    $rowCobrado   = $resCobrado->fetch_assoc();
    $totalCobrado = $rowCobrado['total_cobrado'] ?? 0;
}

$saldo = $venta['total'] - $totalCobrado;
if ($saldo < 0) $saldo = 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Detalle venta #<?= (int)$venta['id'] ?></title>
  <style>
    body {
      background:#f5f5f5;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      margin:0;
      padding:16px;
    }
    .venta-detalle {
      background:#fff;
      padding:16px;
      border-radius:8px;
      box-shadow:0 2px 8px rgba(0,0,0,0.1);
    }
    .venta-detalle h1 {
      margin:0 0 8px;
      font-size:18px;
    }
    .venta-detalle__info p {
      margin:2px 0;
      font-size:14px;
    }
    h2 {
      font-size:15px;
      margin-top:10px;
      margin-bottom:4px;
    }
    table {
      width:100%;
      border-collapse:collapse;
      margin-top:6px;
      font-size:13px;
    }
    th, td {
      border:1px solid #ddd;
      padding:4px 6px;
    }
    th {
      background:#f5f5f5;
      text-align:left;
    }
    .venta-detalle__totales {
      margin-top:10px;
      text-align:right;
      font-size:14px;
    }
    .venta-detalle__totales p {
      margin:2px 0;
    }
  </style>
</head>
<body>
  <div class="venta-detalle">
    <h1>Venta #<?= (int)$venta['id'] ?></h1>

    <div class="venta-detalle__info">
      <p><strong>Fecha:</strong> <?= date('d/m/Y H:i', strtotime($venta['fecha_hora'])) ?></p>
      <p><strong>Cliente:</strong> <?= htmlspecialchars($venta['nombre_cliente'] ?? 'CONSUMIDOR FINAL') ?></p>
      <p><strong>Tipo de venta:</strong> <?= htmlspecialchars($venta['tipo_venta']) ?></p>
      <p><strong>Estado de pago:</strong> <?= htmlspecialchars($venta['estado_pago']) ?></p>
    </div>

    <h2>Detalle de productos</h2>
    <table>
      <thead>
        <tr>
          <th>Producto</th>
          <th>Cant.</th>
          <th>Precio</th>
          <th>Subtotal</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($items)): ?>
          <tr><td colspan="4">No hay detalle cargado.</td></tr>
        <?php else: ?>
          <?php foreach ($items as $item): ?>
            <?php
              // Nombre = producto + variante (si existe)
              $nombre = $item['nombre_producto'] ?? '';
              $nombre = trim($item['nombre_producto'] ?? '');

// Si existe variante Y NO es "unica", entonces mostrarla entre paréntesis
$variante = $item['nombre_variante'] ?? '';

if ($variante !== '' && strtolower($variante) !== 'unica') {
    $nombre .= " ({$variante})";
}
              if ($nombre === '') {
                  $nombre = 'Producto';
              }

              $cantidad = (float)$item['cantidad'];
              $precio   = (float)$item['precio_unitario'];
              $subtotal = (float)$item['subtotal'];
            ?>
            <tr>
              <td><?= htmlspecialchars($nombre) ?></td>
              <td><?= $cantidad ?></td>
              <td>$<?= number_format($precio, 2, ',', '.') ?></td>
              <td>$<?= number_format($subtotal, 2, ',', '.') ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="venta-detalle__totales">
      <p><strong>Total venta:</strong> $<?= number_format($venta['total'], 2, ',', '.') ?></p>
      <p><strong>Total cobrado:</strong> $<?= number_format($totalCobrado, 2, ',', '.') ?></p>
      <p><strong>Saldo pendiente:</strong> $<?= number_format($saldo, 2, ',', '.') ?></p>
    </div>
  </div>
</body>
</html>