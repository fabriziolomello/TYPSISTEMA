<?php
// app/views/stock/stock_movimientos/nuevo.php

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

$titulo   = "Nuevo movimiento de stock";
$css_extra = '<link rel="stylesheet" href="' . BASE_URL . 'public/css/stock_movimientos.css">';


$db   = new Database();
$conn = $db->getConnection();

$sql = "
    SELECT
        pv.id   AS id_variante,
        p.nombre AS nombre_producto,
        pv.nombre_variante,
        COALESCE(pv.codigo_barras, p.codigo_barras) AS codigo_barras,
        pv.stock_actual
    FROM producto_variante pv
    INNER JOIN productos p ON p.id = pv.id_producto
    WHERE pv.activo = 1 AND p.activo = 1
    ORDER BY p.nombre ASC, pv.nombre_variante ASC
";

$res      = $conn->query($sql);
$variantes = [];
while ($row = $res->fetch_assoc()) {
    $variantes[] = $row;
}

// Stock por depósito para cada variante
$sdRes = $conn->query("SELECT id_variante, id_deposito, stock_actual FROM stock_deposito");
$stockDeposito = [];
while ($row = $sdRes->fetch_assoc()) {
    $stockDeposito[(int)$row['id_variante']][(int)$row['id_deposito']] = (int)$row['stock_actual'];
}

$hoy       = date('Y-m-d');
$depositos = $conn->query("SELECT id, nombre FROM deposito ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
$depUsuario = (int)($_SESSION['usuario_deposito'] ?? 1);

ob_start();
?>

<div class="mov-container">

    <!-- CABECERA -->
    <div class="mov-card">
        <h1 class="mov-titulo">Nuevo movimiento de stock</h1>
        <div class="mov-cabecera">
            <div class="mov-field">
                <label>Fecha</label>
                <input type="date" id="mov-fecha" value="<?= $hoy ?>">
            </div>
            <div class="mov-field">
                <label>Depósito</label>
                <select id="mov-deposito">
                    <?php foreach ($depositos as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= (int)$d['id'] === $depUsuario ? 'selected' : '' ?>>
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
            <div class="mov-field">
                <label>Tipo</label>
                <select id="mov-tipo">
                    <option value="INGRESO">Ingreso</option>
                    <option value="EGRESO">Egreso</option>
                </select>
            </div>
            <div class="mov-field mov-field--cant">
                <label>Cantidad</label>
                <input type="number" id="mov-cantidad" min="1" value="1">
            </div>
            <button type="button" class="btn-primary mov-btn-add" id="mov-agregar">+</button>
        </div>
    </div>

    <!-- TABLA DE ITEMS -->
    <div class="mov-card">
        <table id="mov-tabla">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Producto</th>
                    <th>Variante</th>
                    <th>Stock actual</th>
                    <th>Tipo</th>
                    <th>Cantidad</th>
                    <th>Stock final</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="mov-tbody">
                <tr id="mov-fila-vacia">
                    <td colspan="8">No hay productos agregados.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- ACCIONES -->
    <div class="mov-acciones">
        <a href="<?= BASE_URL ?>app/views/stock/stock_movimientos/index.php" class="btn-link">Cancelar</a>
        <button type="button" class="btn-primary" id="mov-guardar" disabled>Guardar</button>
    </div>

</div>

<script>
const variantes    = <?= json_encode($variantes) ?>;
const stockDep     = <?= json_encode($stockDeposito) ?>;
let items = [];
let varianteSeleccionada = null;

const inputCodigo  = document.getElementById('mov-codigo');
const inputBuscar  = document.getElementById('mov-buscar');
const sugerencias  = document.getElementById('mov-sugerencias');
const selDeposito  = document.getElementById('mov-deposito');

function getStockActual(id_variante) {
    const dep = parseInt(selDeposito.value);
    return (stockDep[id_variante] && stockDep[id_variante][dep] !== undefined)
        ? stockDep[id_variante][dep]
        : 0;
}

function calcStockFinal(stockActual, tipo, cantidad) {
    if (tipo === 'INGRESO' || tipo === 'AJUSTE_POSITIVO') return stockActual + cantidad;
    if (tipo === 'EGRESO'  || tipo === 'AJUSTE_NEGATIVO') return stockActual - cantidad;
    return stockActual;
}

function esAjuste(tipo) {
    return tipo === 'AJUSTE_POSITIVO' || tipo === 'AJUSTE_NEGATIVO';
}

// =====================
// Buscador por nombre
// =====================
function mostrarSugerencias(lista) {
    sugerencias.innerHTML = '';
    if (lista.length === 0) { sugerencias.style.display = 'none'; return; }
    lista.slice(0, 8).forEach(v => {
        const div = document.createElement('div');
        div.className = 'mov-sugerencia-item';
        const nombre = v.nombre_variante === 'unica'
            ? v.nombre_producto
            : v.nombre_producto + ' - ' + v.nombre_variante;
        div.textContent = (v.codigo_barras ? '[' + v.codigo_barras + '] ' : '') + nombre;
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
    inputCodigo.value = v.codigo_barras ?? '';
    sugerencias.style.display = 'none';
}

inputBuscar.addEventListener('input', () => {
    varianteSeleccionada = null;
    const q = inputBuscar.value.toLowerCase().trim();
    if (!q) { sugerencias.style.display = 'none'; return; }
    const palabras = q.split(/\s+/);
    const texto = v => (v.nombre_producto + ' ' + v.nombre_variante).toLowerCase();
    mostrarSugerencias(variantes.filter(v => palabras.every(p => texto(v).includes(p))));
});

// =====================
// Buscador por código
// =====================
inputCodigo.addEventListener('input', () => {
    varianteSeleccionada = null;
    const q = inputCodigo.value.trim();
    if (!q) { sugerencias.style.display = 'none'; return; }
    const filtradas = variantes.filter(v => v.codigo_barras && v.codigo_barras.includes(q));
    if (filtradas.length === 1) seleccionarVariante(filtradas[0]);
    else mostrarSugerencias(filtradas);
});

document.addEventListener('click', e => {
    if (!sugerencias.contains(e.target) && e.target !== inputBuscar)
        sugerencias.style.display = 'none';
});

// =====================
// Cuando cambia depósito: actualizar stock_actual de todos los items
// =====================
selDeposito.addEventListener('change', () => {
    items.forEach(item => { item.stock_actual = getStockActual(item.id_variante); });
    renderTabla();
});

// =====================
// Agregar item
// =====================
document.getElementById('mov-agregar').addEventListener('click', () => {
    if (!varianteSeleccionada) { alert('Seleccioná un producto primero.'); return; }
    const cantidad = parseInt(document.getElementById('mov-cantidad').value) || 0;
    if (cantidad <= 0) { alert('La cantidad debe ser mayor a 0.'); return; }
    const tipo = document.getElementById('mov-tipo').value;

    const existe = items.find(i => i.id_variante === varianteSeleccionada.id_variante && i.tipo === tipo);
    if (existe) {
        existe.cantidad += cantidad;
    } else {
        items.push({
            id_variante:     varianteSeleccionada.id_variante,
            codigo:          varianteSeleccionada.codigo_barras ?? '',
            nombre_producto: varianteSeleccionada.nombre_producto,
            nombre_variante: varianteSeleccionada.nombre_variante,
            tipo,
            cantidad,
            stock_actual:    getStockActual(varianteSeleccionada.id_variante)
        });
    }
    renderTabla();

    varianteSeleccionada = null;
    inputBuscar.value    = '';
    inputCodigo.value    = '';
    document.getElementById('mov-cantidad').value = 1;
    inputBuscar.focus();
});

// =====================
// Render tabla
// =====================
function renderTabla() {
    const tbody      = document.getElementById('mov-tbody');
    const btnGuardar = document.getElementById('mov-guardar');
    tbody.innerHTML  = '';

    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8">No hay productos agregados.</td></tr>';
        btnGuardar.disabled = true;
        return;
    }

    btnGuardar.disabled = false;

    items.forEach((item, idx) => {
        const variante    = item.nombre_variante === 'unica' ? '-' : item.nombre_variante;
        const stockFinal  = calcStockFinal(item.stock_actual, item.tipo, item.cantidad);
        const sfEditable  = esAjuste(item.tipo);
        const sfClass     = stockFinal < 0 ? 'stock-negativo' : '';

        const tr = document.createElement('tr');
        tr.dataset.idx = idx;
        tr.innerHTML = `
            <td>${item.codigo}</td>
            <td>${item.nombre_producto}</td>
            <td>${variante}</td>
            <td class="col-centro"><strong>${item.stock_actual}</strong></td>
            <td>${item.tipo}</td>
            <td><input type="number" class="item-cantidad mov-input-num" value="${item.cantidad}" min="1" data-idx="${idx}"></td>
            <td>
                <input type="number" class="item-stock-final mov-input-num ${sfClass}" value="${stockFinal}"
                    data-idx="${idx}" ${sfEditable ? '' : 'readonly tabindex="-1"'}>
            </td>
            <td><button type="button" class="btn-eliminar" data-idx="${idx}">&times;</button></td>
        `;
        tbody.appendChild(tr);
    });
}

// =====================
// Editar cantidad / stock final en tabla
// =====================
document.getElementById('mov-tbody').addEventListener('input', e => {
    const idx = parseInt(e.target.dataset.idx);
    if (isNaN(idx)) return;
    const item = items[idx];

    if (e.target.classList.contains('item-cantidad')) {
        item.cantidad = Math.max(0, parseInt(e.target.value) || 0);
    } else if (e.target.classList.contains('item-stock-final') && esAjuste(item.tipo)) {
        const sf = parseInt(e.target.value) ?? item.stock_actual;
        if (item.tipo === 'AJUSTE_POSITIVO') item.cantidad = Math.max(0, sf - item.stock_actual);
        if (item.tipo === 'AJUSTE_NEGATIVO') item.cantidad = Math.max(0, item.stock_actual - sf);
    }

    // Re-render solo la fila afectada
    const stockFinal = calcStockFinal(item.stock_actual, item.tipo, item.cantidad);
    const tr = e.target.closest('tr');
    const inputCant = tr.querySelector('.item-cantidad');
    const inputSF   = tr.querySelector('.item-stock-final');
    if (e.target.classList.contains('item-stock-final')) {
        inputCant.value = item.cantidad;
    } else {
        inputSF.value = stockFinal;
    }
    inputSF.classList.toggle('stock-negativo', stockFinal < 0);
});

document.getElementById('mov-tbody').addEventListener('click', e => {
    if (e.target.classList.contains('btn-eliminar')) {
        items.splice(parseInt(e.target.dataset.idx), 1);
        renderTabla();
    }
});

// =====================
// Guardar
// =====================
document.getElementById('mov-guardar').addEventListener('click', () => {
    const fecha         = document.getElementById('mov-fecha').value;
    const observaciones = document.getElementById('mov-observaciones').value;

    if (!fecha)             { alert('Ingresá una fecha.'); return; }
    if (items.length === 0) { alert('Agregá al menos un producto.'); return; }

    const itemsConCantidad = items.filter(i => i.cantidad > 0);
    if (itemsConCantidad.length === 0) { alert('Al menos un item debe tener cantidad mayor a 0.'); return; }

    fetch('<?= BASE_URL ?>app/controllers/stock/movimientos/guardar.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            fecha,
            observaciones,
            items: itemsConCantidad,
            id_deposito: parseInt(selDeposito.value)
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.href = '<?= BASE_URL ?>app/views/stock/stock_movimientos/index.php?ok=1';
        } else {
            alert('Error: ' + data.error);
        }
    });
});
</script>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../../layouts/main.php';
