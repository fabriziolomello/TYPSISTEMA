<?php
require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

$titulo    = "Ajuste de stock";
$css_extra = '<link rel="stylesheet" href="' . BASE_URL . 'public/css/stock_movimientos.css">';

$db   = new Database();
$conn = $db->getConnection();

$obs   = trim($_GET['obs']   ?? '');
$desde = trim($_GET['desde'] ?? '');
$hasta = trim($_GET['hasta'] ?? '');

$reFecha = '/^\d{4}-\d{2}-\d{2}$/';
if ($desde !== '' && !preg_match($reFecha, $desde)) $desde = '';
if ($hasta !== '' && !preg_match($reFecha, $hasta)) $hasta = '';

$sql = "
    SELECT
        mm.id,
        mm.fecha,
        mm.observaciones,
        u.nombre          AS usuario,
        COUNT(ms.id)      AS cant_productos,
        SUM(ms.cantidad)  AS cant_unidades
    FROM movimiento_manual mm
    INNER JOIN movimiento_stock ms ON ms.id_movimiento_manual = mm.id
        AND ms.tipo IN ('AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO')
    LEFT JOIN usuarios u ON u.id = mm.id_usuario
    WHERE 1=1
";

$params = [];
$types  = '';

if ($obs !== '') {
    $sql    .= " AND mm.observaciones LIKE ?";
    $params[] = '%' . $obs . '%';
    $types  .= 's';
}
if ($desde !== '') {
    $sql    .= " AND mm.fecha >= ?";
    $params[] = $desde;
    $types  .= 's';
}
if ($hasta !== '') {
    $sql    .= " AND mm.fecha <= ?";
    $params[] = $hasta;
    $types  .= 's';
}

$sql .= " GROUP BY mm.id ORDER BY mm.fecha DESC, mm.id DESC LIMIT 200";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

ob_start();
?>

<h1>Ajuste de stock</h1>

<p style="margin-bottom:16px;">
    <a href="<?= BASE_URL ?>app/views/stock/ajuste/nuevo.php" class="btn-primary">Nuevo ajuste</a>
</p>

<?php if (isset($_GET['ok'])): ?>
<div style="padding:12px 16px;background:#d4edda;border-radius:6px;color:#155724;font-size:14px;margin-bottom:16px;">
    Ajuste guardado correctamente.
</div>
<?php endif; ?>

<form method="get" action="" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:20px;">
    <div>
        <label style="display:block;font-size:13px;margin-bottom:4px;">Observaciones</label>
        <input type="text" name="obs" value="<?= h($obs) ?>" placeholder="Buscar..."
            style="padding:7px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;">
    </div>
    <div>
        <label style="display:block;font-size:13px;margin-bottom:4px;">Desde</label>
        <input type="date" name="desde" value="<?= h($desde) ?>"
            style="padding:7px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;">
    </div>
    <div>
        <label style="display:block;font-size:13px;margin-bottom:4px;">Hasta</label>
        <input type="date" name="hasta" value="<?= h($hasta) ?>"
            style="padding:7px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;">
    </div>
    <div style="display:flex;gap:8px;">
        <button type="submit" class="btn-primary">Filtrar</button>
        <a href="<?= BASE_URL ?>app/views/stock/ajuste/index.php" class="btn-link">Limpiar</a>
    </div>
</form>

<table>
    <thead>
        <tr>
            <th>Fecha</th>
            <th>Usuario</th>
            <th>Observaciones</th>
            <th style="text-align:center;">Productos</th>
            <th style="text-align:center;">Unidades ajustadas</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($result->num_rows === 0): ?>
            <tr><td colspan="5" style="color:#999;text-align:center;padding:20px;">No hay ajustes registrados.</td></tr>
        <?php else: ?>
            <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?= h($row['fecha']) ?></td>
                <td><?= h($row['usuario']) ?></td>
                <td><?= h($row['observaciones']) ?></td>
                <td style="text-align:center;"><?= (int)$row['cant_productos'] ?></td>
                <td style="text-align:center;"><?= (int)$row['cant_unidades'] ?></td>
            </tr>
            <?php endwhile; ?>
        <?php endif; ?>
    </tbody>
</table>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../../layouts/main.php';
