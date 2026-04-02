<?php
// app/views/caja/historico.php

$titulo   = "Histórico de caja";
$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/caja.css">';

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$db   = new Database();
$conn = $db->getConnection();

// Filtros
$desde = trim($_GET['desde'] ?? '');
$hasta = trim($_GET['hasta'] ?? '');
$reFecha = '/^\d{4}-\d{2}-\d{2}$/';
if ($desde && !preg_match($reFecha, $desde)) $desde = '';
if ($hasta && !preg_match($reFecha, $hasta)) $hasta = '';

// Cajas con usuarios
$params = [];
$types  = '';
$where  = [];

if ($desde) { $where[] = "c.fecha >= ?"; $params[] = $desde; $types .= 's'; }
if ($hasta) { $where[] = "c.fecha <= ?"; $params[] = $hasta; $types .= 's'; }

$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT
        c.id,
        c.fecha,
        c.estado,
        c.saldo_inicial,
        c.saldo_final,
        c.observaciones,
        ua.nombre AS usuario_apertura,
        uc.nombre AS usuario_cierre
    FROM caja c
    INNER JOIN usuarios ua ON ua.id = c.id_usuario_apertura
    LEFT  JOIN usuarios uc ON uc.id = c.id_usuario_cierre
    $sqlWhere
    ORDER BY c.fecha DESC, c.id DESC
";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$cajas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Detalle de cierre por caja
$detalles = [];
if (!empty($cajas)) {
    $ids = implode(',', array_column($cajas, 'id'));
    $resDet = $conn->query("
        SELECT id_caja, medio_pago, total_esperado, total_real, diferencia
        FROM caja_cierre_detalle
        WHERE id_caja IN ($ids)
        ORDER BY id_caja, medio_pago
    ");
    while ($row = $resDet->fetch_assoc()) {
        $detalles[$row['id_caja']][] = $row;
    }
}

$BASE = "/TYPSISTEMA";

function fmt($n) {
    return '$' . number_format((float)$n, 2, ',', '.');
}

ob_start();
?>

<div class="caja-container" style="max-width:960px">
    <h1 class="caja-titulo">Histórico de caja</h1>

    <!-- Filtros -->
    <form method="get" class="caja-filtros">
        <div class="caja-field">
            <label>Desde</label>
            <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>">
        </div>
        <div class="caja-field">
            <label>Hasta</label>
            <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>">
        </div>
        <div class="caja-filtros-acciones">
            <button type="submit" class="btn-primary">Filtrar</button>
            <a href="<?= $BASE ?>/app/views/caja/historico.php" class="btn-link">Limpiar</a>
        </div>
    </form>

    <?php if (empty($cajas)): ?>
        <div class="caja-aviso caja-aviso--alerta">No hay cajas para los filtros seleccionados.</div>
    <?php else: ?>

        <?php foreach ($cajas as $caja): ?>
            <?php
                $idCaja  = (int)$caja['id'];
                $abierta = $caja['estado'] === 'ABIERTA';
                $det     = $detalles[$idCaja] ?? [];
            ?>
            <div class="hist-caja">

                <!-- Cabecera clickeable -->
                <div class="hist-caja-header" onclick="toggleDetalle(<?= $idCaja ?>)">
                    <div class="hist-caja-info">
                        <span class="hist-fecha"><?= date('d/m/Y', strtotime($caja['fecha'])) ?></span>
                        <span class="hist-badge hist-badge--<?= strtolower($caja['estado']) ?>">
                            <?= $caja['estado'] ?>
                        </span>
                        <span class="hist-usuario">Apertura: <?= htmlspecialchars($caja['usuario_apertura']) ?></span>
                        <?php if ($caja['usuario_cierre']): ?>
                            <span class="hist-usuario">Cierre: <?= htmlspecialchars($caja['usuario_cierre']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="hist-caja-montos">
                        <span>Saldo inicial: <strong><?= fmt($caja['saldo_inicial']) ?></strong></span>
                        <?php if (!$abierta): ?>
                            <span>Saldo final: <strong><?= fmt($caja['saldo_final']) ?></strong></span>
                        <?php endif; ?>
                        <span class="hist-toggle-icon" id="icon-<?= $idCaja ?>">▼</span>
                    </div>
                </div>

                <!-- Detalle (colapsable) -->
                <div class="hist-caja-detalle" id="det-<?= $idCaja ?>" style="display:none">
                    <?php if ($abierta): ?>
                        <p style="padding:12px;color:#666;">Caja abierta — sin cierre registrado.</p>
                    <?php elseif (empty($det)): ?>
                        <p style="padding:12px;color:#666;">Sin detalle de cierre registrado.</p>
                    <?php else: ?>
                        <table class="caja-tabla caja-tabla--cierre">
                            <thead>
                                <tr>
                                    <th>Medio</th>
                                    <th>Total esperado</th>
                                    <th>Total real</th>
                                    <th>Diferencia</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($det as $d): ?>
                                    <?php
                                        $dif = (float)$d['diferencia'];
                                        $difClass = $dif > 0 ? 'diferencia-positiva' : ($dif < 0 ? 'diferencia-negativa' : 'diferencia-cero');
                                    ?>
                                    <tr>
                                        <td><?= ucfirst(strtolower($d['medio_pago'])) ?></td>
                                        <td><?= fmt($d['total_esperado']) ?></td>
                                        <td><?= fmt($d['total_real']) ?></td>
                                        <td class="<?= $difClass ?>"><?= fmt($dif) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if ($caja['observaciones']): ?>
                            <p class="hist-obs">Observaciones: <?= htmlspecialchars($caja['observaciones']) ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

            </div>
        <?php endforeach; ?>

    <?php endif; ?>
</div>

<script>
function toggleDetalle(id) {
    const det  = document.getElementById('det-'  + id);
    const icon = document.getElementById('icon-' + id);
    const visible = det.style.display !== 'none';
    det.style.display  = visible ? 'none'  : 'block';
    icon.textContent   = visible ? '▼' : '▲';
}
</script>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';
