<?php
// app/views/stock/transferencia/index.php

$titulo    = "Transferencia de stock";
$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/stock_movimientos.css">';

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

$db   = new Database();
$conn = $db->getConnection();

$depositos = $conn->query("SELECT id, nombre FROM deposito ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
$depUsuario = (int)($_SESSION['usuario_deposito'] ?? 1);

$variantes = $conn->query("
    SELECT
        pv.id   AS id_variante,
        p.nombre AS nombre_producto,
        pv.nombre_variante,
        COALESCE(pv.codigo_barras, p.codigo_barras) AS codigo_barras
    FROM producto_variante pv
    INNER JOIN productos p ON p.id = pv.id_producto
    WHERE pv.activo = 1 AND p.activo = 1
    ORDER BY p.nombre ASC, pv.nombre_variante ASC
")->fetch_all(MYSQLI_ASSOC);

$hoy  = date('Y-m-d');
$BASE = "/TYPSISTEMA";

// Mapa id -> nombre de depósito
$depMap = array_column($depositos, 'nombre', 'id');

// Historial: una fila por movimiento_manual que tenga transferencias
// Traemos los ítems agrupados por id_movimiento_manual
$historial = $conn->query("
    SELECT
        mm.id,
        mm.fecha,
        mm.observaciones,
        u.nombre AS usuario,
        ms_e.id_deposito AS dep_origen,
        ms_i.id_deposito AS dep_destino,
        p.nombre         AS producto,
        pv.nombre_variante,
        ms_e.cantidad
    FROM movimiento_manual mm
    INNER JOIN usuarios u ON u.id = mm.id_usuario
    INNER JOIN movimiento_stock ms_e ON ms_e.id_movimiento_manual = mm.id AND ms_e.observaciones = 'Transferencia salida'
    INNER JOIN movimiento_stock ms_i ON ms_i.id_movimiento_manual = mm.id
        AND ms_i.id_variante = ms_e.id_variante
        AND ms_i.observaciones = 'Transferencia entrada'
    INNER JOIN producto_variante pv ON pv.id = ms_e.id_variante
    INNER JOIN productos p ON p.id = pv.id_producto
    ORDER BY mm.id DESC, p.nombre ASC
    LIMIT 500
")->fetch_all(MYSQLI_ASSOC);

// Agrupar por id de movimiento_manual
$grupos = [];
foreach ($historial as $row) {
    $id = $row['id'];
    if (!isset($grupos[$id])) {
        $grupos[$id] = [
            'id'          => $id,
            'fecha'       => $row['fecha'],
            'usuario'     => $row['usuario'],
            'observaciones'=> $row['observaciones'],
            'dep_origen'  => $depMap[$row['dep_origen']]  ?? $row['dep_origen'],
            'dep_destino' => $depMap[$row['dep_destino']] ?? $row['dep_destino'],
            'items'       => [],
        ];
    }
    $grupos[$id]['items'][] = [
        'producto'  => $row['producto'],
        'variante'  => $row['nombre_variante'],
        'cantidad'  => $row['cantidad'],
    ];
}

ob_start();
?>

<div class="mov-container">

    <div class="mov-card">
        <h1 class="mov-titulo">Transferencia de stock</h1>
        <div class="mov-cabecera">
            <div class="mov-field">
                <label>Fecha</label>
                <input type="date" id="mov-fecha" value="<?= $hoy ?>">
            </div>
            <div class="mov-field">
                <label>Origen</label>
                <select id="mov-origen">
                    <?php foreach ($depositos as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= (int)$d['id'] === $depUsuario ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mov-field">
                <label>Destino</label>
                <select id="mov-destino">
                    <?php foreach ($depositos as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= (int)$d['id'] !== $depUsuario ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mov-field mov-field--obs">
                <label>Observaciones</label>
                <textarea id="mov-observaciones" rows="2" placeholder="Opcional..."></textarea>
            </div>
        </div>
    </div>

    <!-- BUSCADOR + AGREGAR -->
    <div class="mov-card">
        <div class="mov-agregar-fila">
            <div class="mov-field">
                <label>Código</label>
                <input type="text" id="mov-codigo" placeholder="Código de barras..." autocomplete="off">
            </div>
            <div class="mov-field mov-field--nombre">
                <label>Producto / Variante</label>
                <div class="mov-buscar-wrapper">
                    <input type="text" id="mov-buscar" placeholder="Buscar por nombre..." autocomplete="off">
                    <div id="mov-sugerencias" class="mov-sugerencias"></div>
                </div>
            </div>
            <div class="mov-field mov-field--cant">
                <label>Cantidad</label>
                <input type="number" id="mov-cantidad" min="1" value="1">
            </div>
            <button type="button" class="btn-primary mov-btn-add" id="mov-agregar">+</button>
        </div>
    </div>

    <!-- TABLA -->
    <div class="mov-card">
        <table id="mov-tabla">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Producto</th>
                    <th>Variante</th>
                    <th>Cantidad</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="mov-tbody">
                <tr id="mov-fila-vacia"><td colspan="5">No hay productos agregados.</td></tr>
            </tbody>
        </table>
    </div>

    <div class="mov-acciones">
        <a href="<?= $BASE ?>/app/views/stock/stock_consultar/index.php" class="btn-link">Cancelar</a>
        <button type="button" class="btn-primary" id="mov-guardar" disabled>Transferir</button>
    </div>

</div>

<!-- HISTORIAL -->
<div class="mov-container" style="margin-top:24px;">
    <h2 class="mov-titulo" style="font-size:18px;">Historial de transferencias</h2>

    <?php if (empty($grupos)): ?>
        <p style="color:#888;font-size:14px;">No hay transferencias registradas.</p>
    <?php else: ?>
        <?php foreach ($grupos as $g): ?>
            <div class="mov-card" style="margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
                    <div style="display:flex;gap:16px;align-items:center;font-size:14px;">
                        <strong><?= date('d/m/Y', strtotime($g['fecha'])) ?></strong>
                        <span><?= htmlspecialchars($g['dep_origen']) ?> → <?= htmlspecialchars($g['dep_destino']) ?></span>
                        <span style="color:#888;">por <?= htmlspecialchars($g['usuario']) ?></span>
                        <?php if ($g['observaciones']): ?>
                            <span style="color:#888;">· <?= htmlspecialchars($g['observaciones']) ?></span>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn-link btn-toggle-detalle" style="padding:4px 10px;font-size:13px;">Ver detalle</button>
                </div>
                <table class="mov-tabla-hist" style="display:none;width:100%;border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="text-align:left;padding:6px 10px;background:#f4f4f4;border:1px solid #ddd;font-size:13px;">Producto</th>
                            <th style="text-align:left;padding:6px 10px;background:#f4f4f4;border:1px solid #ddd;font-size:13px;">Variante</th>
                            <th style="text-align:center;padding:6px 10px;background:#f4f4f4;border:1px solid #ddd;font-size:13px;">Cantidad</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($g['items'] as $it): ?>
                            <tr>
                                <td style="padding:6px 10px;border:1px solid #ddd;font-size:13px;"><?= htmlspecialchars($it['producto']) ?></td>
                                <td style="padding:6px 10px;border:1px solid #ddd;font-size:13px;"><?= strtolower($it['variante']) === 'unica' ? '-' : htmlspecialchars($it['variante']) ?></td>
                                <td style="padding:6px 10px;border:1px solid #ddd;font-size:13px;text-align:center;"><?= (int)$it['cantidad'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
// Toggle detalle historial
document.querySelectorAll('.btn-toggle-detalle').forEach(btn => {
    btn.addEventListener('click', () => {
        const tabla = btn.closest('.mov-card').querySelector('.mov-tabla-hist');
        const visible = tabla.style.display !== 'none';
        tabla.style.display = visible ? 'none' : 'table';
        btn.textContent = visible ? 'Ver detalle' : 'Ocultar';
    });
});

const variantes = <?= json_encode($variantes) ?>;
let items = [];
let varianteSeleccionada = null;

const inputCodigo = document.getElementById('mov-codigo');
const inputBuscar = document.getElementById('mov-buscar');
const sugerencias = document.getElementById('mov-sugerencias');

function mostrarSugerencias(lista) {
    sugerencias.innerHTML = '';
    if (!lista.length) { sugerencias.style.display = 'none'; return; }
    lista.slice(0, 8).forEach(v => {
        const div = document.createElement('div');
        div.className = 'mov-sugerencia';
        div.textContent = v.nombre_variante === 'unica'
            ? v.nombre_producto
            : v.nombre_producto + ' - ' + v.nombre_variante;
        div.addEventListener('click', () => seleccionarVariante(v));
        sugerencias.appendChild(div);
    });
    sugerencias.style.display = 'block';
}

function seleccionarVariante(v) {
    varianteSeleccionada = v;
    inputBuscar.value = v.nombre_variante === 'unica'
        ? v.nombre_producto
        : v.nombre_producto + ' - ' + v.nombre_variante;
    sugerencias.style.display = 'none';
    document.getElementById('mov-cantidad').focus();
}

inputBuscar.addEventListener('input', () => {
    const q = inputBuscar.value.trim().toLowerCase();
    varianteSeleccionada = null;
    if (!q) { sugerencias.style.display = 'none'; return; }
    const palabras = q.split(' ').filter(Boolean);
    const texto = v => (v.nombre_producto + ' ' + v.nombre_variante).toLowerCase();
    mostrarSugerencias(variantes.filter(v => palabras.every(p => texto(v).includes(p))));
});

inputCodigo.addEventListener('keydown', e => {
    if (e.key !== 'Enter') return;
    const q = inputCodigo.value.trim();
    if (!q) return;
    const encontrado = variantes.find(v => v.codigo_barras && v.codigo_barras === q);
    if (encontrado) { seleccionarVariante(encontrado); inputCodigo.value = ''; }
    else { alert('Código no encontrado.'); }
});

document.addEventListener('click', e => {
    if (!sugerencias.contains(e.target) && e.target !== inputBuscar) {
        sugerencias.style.display = 'none';
    }
});

function renderTabla() {
    const tbody = document.getElementById('mov-tbody');
    const filaVacia = document.getElementById('mov-fila-vacia');
    tbody.querySelectorAll('tr:not(#mov-fila-vacia)').forEach(r => r.remove());

    if (!items.length) { filaVacia.style.display = ''; return; }
    filaVacia.style.display = 'none';

    items.forEach((item, idx) => {
        const variante = item.nombre_variante === 'unica' ? '-' : item.nombre_variante;
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${item.codigo}</td>
            <td>${item.nombre_producto}</td>
            <td>${variante}</td>
            <td>${item.cantidad}</td>
            <td><button type="button" class="btn-accion btn-eliminar" data-idx="${idx}">&times;</button></td>
        `;
        tbody.appendChild(tr);
    });

    document.getElementById('mov-guardar').disabled = false;
}

document.getElementById('mov-agregar').addEventListener('click', () => {
    if (!varianteSeleccionada) { alert('Seleccioná un producto primero.'); return; }
    const cantidad = parseInt(document.getElementById('mov-cantidad').value) || 0;
    if (cantidad <= 0) { alert('La cantidad debe ser mayor a 0.'); return; }

    const existe = items.find(i => i.id_variante === varianteSeleccionada.id_variante);
    if (existe) { existe.cantidad += cantidad; }
    else {
        items.push({
            id_variante:     varianteSeleccionada.id_variante,
            codigo:          varianteSeleccionada.codigo_barras ?? '',
            nombre_producto: varianteSeleccionada.nombre_producto,
            nombre_variante: varianteSeleccionada.nombre_variante,
            cantidad
        });
    }

    inputBuscar.value = '';
    inputCodigo.value = '';
    varianteSeleccionada = null;
    document.getElementById('mov-cantidad').value = 1;
    renderTabla();
});

document.getElementById('mov-tbody').addEventListener('click', e => {
    if (e.target.classList.contains('btn-eliminar')) {
        items.splice(parseInt(e.target.dataset.idx), 1);
        if (!items.length) document.getElementById('mov-guardar').disabled = true;
        renderTabla();
    }
});

document.getElementById('mov-guardar').addEventListener('click', () => {
    const fecha         = document.getElementById('mov-fecha').value;
    const observaciones = document.getElementById('mov-observaciones').value.trim();
    const idOrigen      = parseInt(document.getElementById('mov-origen').value);
    const idDestino     = parseInt(document.getElementById('mov-destino').value);

    if (!fecha) { alert('Ingresá una fecha.'); return; }
    if (idOrigen === idDestino) { alert('El origen y el destino no pueden ser el mismo depósito.'); return; }
    if (!items.length) { alert('No hay productos agregados.'); return; }

    const btn = document.getElementById('mov-guardar');
    btn.disabled = true;
    btn.textContent = 'Guardando...';

    fetch('/TYPSISTEMA/app/controllers/stock/transferencia/guardar.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ fecha, observaciones, id_origen: idOrigen, id_destino: idDestino, items })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert('Error: ' + data.error);
            btn.disabled = false;
            btn.textContent = 'Transferir';
        }
    });
});
</script>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../../layouts/main.php';
