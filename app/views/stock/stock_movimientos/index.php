<?php
// app/views/stock/stock_movimientos/index.php

$titulo = "Ingreso / Egreso (Stock)";
$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/stock_movimientos.css">';

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

$db   = new Database();
$conn = $db->getConnection();

// ---------------------------
// Filtros (GET)
// ---------------------------
$tipo  = isset($_GET['tipo']) ? trim($_GET['tipo']) : '';
$desde = isset($_GET['desde']) ? trim($_GET['desde']) : '';
$hasta = isset($_GET['hasta']) ? trim($_GET['hasta']) : '';

$tiposPermitidos = ['INGRESO', 'AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO'];
if ($tipo !== '' && !in_array($tipo, $tiposPermitidos, true)) {
    $tipo = '';
}

// Validación simple de fechas YYYY-MM-DD
$reFecha = '/^\d{4}-\d{2}-\d{2}$/';
if ($desde !== '' && !preg_match($reFecha, $desde)) $desde = '';
if ($hasta !== '' && !preg_match($reFecha, $hasta)) $hasta = '';

// ---------------------------
// Query listado
// ---------------------------
$sql = "
    SELECT
        ms.id,
        ms.fecha_hora,
        ms.tipo,
        ms.cantidad,
        ms.observaciones,
        p.nombre AS producto,
        pv.nombre_variante AS variante
    FROM movimiento_stock ms
    INNER JOIN producto_variante pv ON pv.id = ms.id_variante
    INNER JOIN productos p ON p.id = pv.id_producto
    WHERE ms.id_venta IS NULL
      AND ms.tipo <> 'VENTA'
";

$params = [];
$types  = '';

if ($tipo !== '') {
    $sql .= " AND ms.tipo = ? ";
    $params[] = $tipo;
    $types .= 's';
}

if ($desde !== '' && $hasta !== '') {
    $sql .= " AND ms.fecha_hora BETWEEN ? AND ? ";
    $params[] = $desde . " 00:00:00";
    $params[] = $hasta . " 23:59:59";
    $types .= 'ss';
} elseif ($desde !== '') {
    $sql .= " AND ms.fecha_hora >= ? ";
    $params[] = $desde . " 00:00:00";
    $types .= 's';
} elseif ($hasta !== '') {
    $sql .= " AND ms.fecha_hora <= ? ";
    $params[] = $hasta . " 23:59:59";
    $types .= 's';
}

$sql .= " ORDER BY ms.fecha_hora DESC LIMIT 300 ";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Error preparando consulta: " . $conn->error);
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$ok  = isset($_GET['ok']) ? (int)$_GET['ok'] : 0;
$err = isset($_GET['err']) ? trim($_GET['err']) : '';

function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// Para links (ajustá si tu carpeta raíz cambia)
$BASE = "/TYPSISTEMA";

// ---------------------------
// Render con layout
// ---------------------------
ob_start();
?>

<h1>Ingreso / Egreso (Stock)</h1>

<p>
  <a href="<?= $BASE ?>/app/views/stock/stock_movimientos/nuevo.php">Nuevo movimiento</a>
</p>

<?php if ($ok === 1): ?>
  <p>Movimiento guardado correctamente.</p>
<?php endif; ?>

<?php if ($err !== ''): ?>
  <p><?= h($err) ?></p>
<?php endif; ?>

<form method="get" action="">
  <label>Tipo:</label>
  <select name="tipo">
    <option value="" <?= $tipo === '' ? 'selected' : '' ?>>Todos</option>
    <option value="INGRESO" <?= $tipo === 'INGRESO' ? 'selected' : '' ?>>Ingreso</option>
    <option value="AJUSTE_POSITIVO" <?= $tipo === 'AJUSTE_POSITIVO' ? 'selected' : '' ?>>Ajuste +</option>
    <option value="AJUSTE_NEGATIVO" <?= $tipo === 'AJUSTE_NEGATIVO' ? 'selected' : '' ?>>Ajuste -</option>
  </select>

  <label>Desde:</label>
  <input type="date" name="desde" value="<?= h($desde) ?>">

  <label>Hasta:</label>
  <input type="date" name="hasta" value="<?= h($hasta) ?>">

  <button type="submit">Filtrar</button>

  <a href="<?= $BASE ?>/app/views/stock/stock_movimientos/index.php">Limpiar</a>
</form>

<hr>

<table border="1" cellpadding="6" cellspacing="0">
  <thead>
    <tr>
      <th>Fecha</th>
      <th>Tipo</th>
      <th>Producto</th>
      <th>Variante</th>
      <th>Cantidad</th>
      <th>Observaciones</th>
    </tr>
  </thead>
  <tbody>
    <?php if ($result->num_rows === 0): ?>
      <tr>
        <td colspan="6">Sin movimientos para los filtros seleccionados.</td>
      </tr>
    <?php else: ?>
      <?php while ($row = $result->fetch_assoc()): ?>
        <tr>
          <td><?= h($row['fecha_hora']) ?></td>
          <td><?= h($row['tipo']) ?></td>
          <td><?= h($row['producto']) ?></td>
          <td><?= h($row['variante']) ?></td>
          <td><?= h($row['cantidad']) ?></td>
          <td><?= h($row['observaciones']) ?></td>
        </tr>
      <?php endwhile; ?>
    <?php endif; ?>
  </tbody>
</table>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../../layouts/main.php';