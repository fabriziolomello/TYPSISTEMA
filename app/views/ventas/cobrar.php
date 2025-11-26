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

// 1) Venta + cliente
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

// 2) Total cobrado actual
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

// 3) Pagos ya registrados
$sqlPagos = "
    SELECT *
    FROM movimiento_caja
    WHERE id_venta = {$id}
      AND tipo = 'VENTA'
    ORDER BY fecha_hora ASC
";
$resPagos = $conn->query($sqlPagos);
$pagos = [];
if ($resPagos && $resPagos->num_rows > 0) {
    while ($row = $resPagos->fetch_assoc()) {
        $pagos[] = $row;
    }
}

// 4) Procesar nuevo pago
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $medio_pago = $_POST['medio_pago'] ?? '';
    $monto      = (float)($_POST['monto'] ?? 0);

    $mediosValidos = ['EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'QR'];
    if (!in_array($medio_pago, $mediosValidos)) {
        $errores[] = "Medio de pago inválido.";
    }

    if ($monto <= 0) {
        $errores[] = "El monto debe ser mayor a 0.";
    } elseif ($monto > $saldo) {
        $errores[] = "El monto no puede ser mayor al saldo pendiente.";
    }

    if (empty($errores)) {
        // TODO: usar id_caja real cuando tengas aperturas de caja
        $id_caja    = 1;
        $id_usuario = $_SESSION['id_usuario'] ?? 1;

        $stmt = $conn->prepare("
            INSERT INTO movimiento_caja
                (id_caja, id_venta, fecha_hora, tipo, monto, medio_pago, referencia, id_usuario)
            VALUES
                (?, ?, NOW(), 'VENTA', ?, ?, '', ?)
        ");
        // tipos: i (id_caja), i (id_venta), d (monto), s (medio_pago), i (id_usuario)
        $stmt->bind_param('iidsi', $id_caja, $id, $monto, $medio_pago, $id_usuario);
        $stmt->execute();
        $stmt->close();

        // Recalcular total cobrado después del nuevo pago
        $sqlCobrado2 = "
            SELECT COALESCE(SUM(mc.monto), 0) AS total_cobrado
            FROM movimiento_caja mc
            WHERE mc.id_venta = {$id}
              AND mc.tipo = 'VENTA'
        ";
        $resCobrado2 = $conn->query($sqlCobrado2);
        $totalCobrado2 = 0;
        if ($resCobrado2 && $resCobrado2->num_rows > 0) {
            $rowCobrado2   = $resCobrado2->fetch_assoc();
            $totalCobrado2 = $rowCobrado2['total_cobrado'] ?? 0;
        }

        // Actualizar estado_pago de la venta
        if ($totalCobrado2 >= $venta['total']) {
            $nuevoEstado = 'PAGADA';
        } elseif ($totalCobrado2 > 0) {
            $nuevoEstado = 'PARCIAL';
        } else {
            $nuevoEstado = 'PENDIENTE';
        }

        $stmt2 = $conn->prepare("UPDATE ventas SET estado_pago = ? WHERE id = ?");
        $stmt2->bind_param('si', $nuevoEstado, $id);
        $stmt2->execute();
        $stmt2->close();

        // Redirigir para evitar re-envío de formulario y refrescar datos
        header("Location: cobrar.php?id={$id}");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Cobrar venta #<?= (int)$venta['id'] ?></title>
  <style>
    body {
      background:#f5f5f5;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      margin:0;
      padding:16px;
    }
    .cobrar {
      background:#fff;
      padding:16px;
      border-radius:8px;
      box-shadow:0 2px 8px rgba(0,0,0,0.1);
      max-width: 900px;
      margin: 0 auto;
    }
    .cobrar h1 {
      margin:0 0 8px;
      font-size:18px;
    }
    .cobrar__info p {
      margin:2px 0;
      font-size:14px;
    }
    .cobrar__resumen p {
      margin:2px 0;
      font-size:14px;
    }
    .cobrar__errores {
      color:#b71c1c;
      margin-top:8px;
      margin-bottom:8px;
      font-size:13px;
    }
    form {
      margin-top:12px;
      margin-bottom:16px;
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      align-items:flex-end;
    }
    label {
      font-size:14px;
      display:block;
      margin-bottom:2px;
    }
    select, input[type="number"] {
      padding:6px;
      font-size:14px;
      min-width:160px;
    }
    button {
      padding:8px 14px;
      font-size:14px;
      border:none;
      border-radius:4px;
      background:#1976d2;
      color:#fff;
      cursor:pointer;
    }
    button:hover {
      background:#0f5ca8;
    }
    table {
      width:100%;
      border-collapse:collapse;
      margin-top:10px;
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
  </style>
</head>
<body>
  <div class="cobrar">
    <h1>Cobrar venta #<?= (int)$venta['id'] ?></h1>

    <div class="cobrar__info">
      <p><strong>Cliente:</strong> <?= htmlspecialchars($venta['nombre_cliente'] ?? 'CONSUMIDOR FINAL') ?></p>
      <p><strong>Fecha:</strong> <?= date('d/m/Y H:i', strtotime($venta['fecha_hora'])) ?></p>
      <p><strong>Tipo de venta:</strong> <?= htmlspecialchars($venta['tipo_venta']) ?></p>
      <p><strong>Estado de pago:</strong> <?= htmlspecialchars($venta['estado_pago']) ?></p>
    </div>

    <div class="cobrar__resumen">
      <p><strong>Total venta:</strong> $<?= number_format($venta['total'], 2, ',', '.') ?></p>
      <p><strong>Total cobrado:</strong> $<?= number_format($totalCobrado, 2, ',', '.') ?></p>
      <p><strong>Saldo pendiente:</strong> $<?= number_format($saldo, 2, ',', '.') ?></p>
    </div>

    <?php if (!empty($errores)): ?>
      <div class="cobrar__errores">
        <?php foreach ($errores as $e): ?>
          <div>• <?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($saldo > 0): ?>
      <form method="post">
        <div>
          <label for="medio_pago">Medio de pago</label>
          <select name="medio_pago" id="medio_pago" required>
            <option value="">Seleccionar...</option>
            <option value="EFECTIVO">Efectivo</option>
            <option value="TARJETA">Tarjeta</option>
            <option value="TRANSFERENCIA">Transferencia</option>
            <option value="QR">QR</option>
          </select>
        </div>
        <div>
          <label for="monto">Monto a cobrar</label>
          <input
            type="number"
            step="0.01"
            min="0.01"
            max="<?= htmlspecialchars($saldo) ?>"
            name="monto"
            id="monto"
            required
          >
        </div>
        <div>
          <button type="submit">Registrar pago</button>
        </div>
      </form>
    <?php else: ?>
      <p><strong>La venta ya está totalmente cobrada.</strong></p>
    <?php endif; ?>

    <h2>Pagos registrados</h2>
    <table>
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Medio</th>
          <th>Monto</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($pagos)): ?>
          <tr><td colspan="3">No hay pagos registrados.</td></tr>
        <?php else: ?>
          <?php foreach ($pagos as $pago): ?>
            <tr>
              <td><?= date('d/m/Y H:i', strtotime($pago['fecha_hora'])) ?></td>
              <td><?= htmlspecialchars($pago['medio_pago']) ?></td>
              <td>$<?= number_format($pago['monto'], 2, ',', '.') ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</body>
</html>