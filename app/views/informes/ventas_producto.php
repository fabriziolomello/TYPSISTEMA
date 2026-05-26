<?php
// app/views/informes/ventas_producto.php

$titulo    = "Informe: Ventas por producto";
$css_extra = '<link rel="stylesheet" href="' . BASE_URL . 'public/css/informes.css">';

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$esAdmin = ($_SESSION['usuario_rol'] ?? '') === 'ADMIN';
if (!$esAdmin) { header('Location: ' . BASE_URL . 'app/views/dashboard/index.php'); exit; }

$db   = new Database();
$conn = $db->getConnection();

// Filtros con defaults
$desde     = $_GET['desde']     ?? date('Y-m-01');
$hasta     = $_GET['hasta']     ?? date('Y-m-d');
$categoria = (int)($_GET['categoria'] ?? 0);

// Validar fechas
$reFecha = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($reFecha, $desde)) $desde = date('Y-m-01');
if (!preg_match($reFecha, $hasta)) $hasta = date('Y-m-d');

// Categorías para el select
$categorias = $conn->query("SELECT id, nombre FROM categoria ORDER BY nombre ASC")->fetch_all(MYSQLI_ASSOC);

// Construir query
$params = [$desde, $hasta];
$types  = 'ss';
$where  = ["DATE(v.fecha_hora) BETWEEN ? AND ?", "v.estado_pago != 'ANULADA'"];

if ($categoria > 0) {
    $where[]  = "p.id_categoria = ?";
    $params[] = $categoria;
    $types   .= 'i';
}

$sqlWhere = 'WHERE ' . implode(' AND ', $where);

$sql = "
    SELECT p.nombre AS producto,
           pv.nombre_variante AS variante,
           c.nombre AS categoria,
           SUM(dv.cantidad)  AS cantidad_vendida,
           SUM(dv.subtotal)  AS monto_total
    FROM detalle_ventas dv
    INNER JOIN ventas v          ON v.id  = dv.id_venta
    INNER JOIN producto_variante pv ON pv.id = dv.id_variante
    INNER JOIN productos p       ON p.id  = pv.id_producto
    LEFT  JOIN categoria c       ON c.id  = p.id_categoria
    $sqlWhere
    GROUP BY p.id, pv.id, p.nombre, pv.nombre_variante, c.nombre
    ORDER BY cantidad_vendida DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Totales
$sumaCantidad = array_sum(array_column($rows, 'cantidad_vendida'));
$sumaMonto    = array_sum(array_column($rows, 'monto_total'));

// ── CSV export ──
if (($_GET['formato'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="informe_ventas_producto_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Producto', 'Variante', 'Categoría', 'Cantidad vendida', 'Monto total'], ';');
    foreach ($rows as $row) {
        $variante = strtolower($row['variante'] ?? '') === 'unica' ? '-' : ($row['variante'] ?? '-');
        fputcsv($out, [
            $row['producto'],
            $variante,
            $row['categoria'] ?? '-',
            $row['cantidad_vendida'],
            number_format((float)$row['monto_total'], 2, '.', ''),
        ], ';');
    }
    fclose($out);
    exit;
}

// URL CSV
$csvUrl = '?desde=' . urlencode($desde)
        . '&hasta=' . urlencode($hasta)
        . '&categoria=' . $categoria
        . '&formato=csv';

ob_start();
?>
<div class="inf-container">

    <div class="inf-header">
        <h1 class="inf-titulo">Ventas por producto</h1>
        <a href="<?= htmlspecialchars($csvUrl) ?>" class="btn-csv">Exportar CSV</a>
    </div>

    <!-- Filtros -->
    <div class="inf-card">
        <form method="get" class="inf-filtros">
            <div class="inf-field">
                <label>Desde</label>
                <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>">
            </div>
            <div class="inf-field">
                <label>Hasta</label>
                <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>">
            </div>
            <div class="inf-field">
                <label>Categoría</label>
                <select name="categoria">
                    <option value="0">Todas las categorías</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>" <?= $categoria === (int)$cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="inf-filtros-acciones">
                <button type="submit" class="btn-primary">Filtrar</button>
                <a href="<?= BASE_URL ?>app/views/informes/ventas_producto.php" class="btn-link">Limpiar</a>
            </div>
        </form>
        <?php if (!empty($rows)): ?>
        <div class="inf-buscador-wrapper">
            <input type="text" id="inf-buscador" placeholder="Buscar producto o variante..." autocomplete="off">
        </div>
        <?php endif; ?>
    </div>

    <!-- Tabla -->
    <div class="inf-tabla-wrapper">
        <?php if (empty($rows)): ?>
            <p class="inf-sin-datos">No hay datos para los filtros seleccionados.</p>
        <?php else: ?>
        <table class="inf-tabla">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>Variante</th>
                    <th>Categoría</th>
                    <th class="col-monto">Cantidad vendida</th>
                    <th class="col-monto">Monto total</th>
                </tr>
            </thead>
            <tbody id="inf-tabla-body">
                <?php foreach ($rows as $row): ?>
                <?php $varianteTxt = strtolower($row['variante'] ?? '') === 'unica' ? '-' : htmlspecialchars($row['variante'] ?? '-'); ?>
                <tr data-search="<?= htmlspecialchars(mb_strtolower($row['producto'] . ' ' . ($row['variante'] ?? ''), 'UTF-8')) ?>"
                    data-cant="<?= (int)$row['cantidad_vendida'] ?>"
                    data-monto="<?= number_format((float)$row['monto_total'], 2, '.', '') ?>">
                    <td><?= htmlspecialchars($row['producto']) ?></td>
                    <td><?= $varianteTxt ?></td>
                    <td><?= htmlspecialchars($row['categoria'] ?? '-') ?></td>
                    <td class="col-monto"><?= number_format((float)$row['cantidad_vendida'], 0, ',', '.') ?></td>
                    <td class="col-monto">$<?= number_format((float)$row['monto_total'], 2, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3"><strong>Totales (<span id="inf-count"><?= count($rows) ?></span> productos)</strong></td>
                    <td class="col-monto" id="inf-suma-cant"><?= number_format($sumaCantidad, 0, ',', '.') ?></td>
                    <td class="col-monto" id="inf-suma-monto">$<?= number_format($sumaMonto, 2, ',', '.') ?></td>
                </tr>
            </tfoot>
        </table>
        <?php endif; ?>
    </div>

    <p class="inf-totales" id="inf-totales-txt"><?= count($rows) ?> producto(s) encontrado(s)</p>

</div>

<?php if (!empty($rows)): ?>
<script>
(function () {
    const input   = document.getElementById('inf-buscador');
    const filas   = document.querySelectorAll('#inf-tabla-body tr');
    const cntEl   = document.getElementById('inf-count');
    const cantEl  = document.getElementById('inf-suma-cant');
    const montoEl = document.getElementById('inf-suma-monto');
    const totTxt  = document.getElementById('inf-totales-txt');

    input.addEventListener('input', () => {
        const palabras = input.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
        let count = 0, sumaCant = 0, sumaMonto = 0;

        filas.forEach(tr => {
            const texto = tr.dataset.search;
            const visible = palabras.length === 0 || palabras.every(p => texto.includes(p));
            tr.style.display = visible ? '' : 'none';
            if (visible) {
                count++;
                sumaCant  += parseInt(tr.dataset.cant)  || 0;
                sumaMonto += parseFloat(tr.dataset.monto) || 0;
            }
        });

        cntEl.textContent   = count;
        cantEl.textContent  = sumaCant.toLocaleString('es-AR');
        montoEl.textContent = '$' + sumaMonto.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        totTxt.textContent  = count + ' producto(s) encontrado(s)';
    });

    input.focus();
})();
</script>
<?php endif; ?>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';
