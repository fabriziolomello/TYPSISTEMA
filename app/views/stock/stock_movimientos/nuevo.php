<?php
// app/views/stock/stock_movimientos/nuevo.php

$titulo   = "Nuevo movimiento de stock";
$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/stock_movimientos.css">';

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

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

$hoy       = date('Y-m-d');
$BASE      = "/TYPSISTEMA";
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
                    <option value="AJUSTE_POSITIVO">Ajuste +</option>
                    <option value="AJUSTE_NEGATIVO">Ajuste -</option>
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
                    <th>Tipo</th>
                    <th>Cantidad</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="mov-tbody">
                <tr id="mov-fila-vacia">
                    <td colspan="6">No hay productos agregados.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- ACCIONES -->
    <div class="mov-acciones">
        <a href="<?= $BASE ?>/app/views/stock/stock_movimientos/index.php" class="btn-link">Cancelar</a>
        <button type="button" class="btn-primary" id="mov-guardar" disabled>Guardar</button>
    </div>

</div>

<script>
const variantes = <?= json_encode($variantes) ?>;
let items = [];
let varianteSeleccionada = null;

const inputCodigo  = document.getElementById('mov-codigo');
const inputBuscar  = document.getElementById('mov-buscar');
const sugerencias  = document.getElementById('mov-sugerencias');

// =====================
// Buscador por nombre
// =====================
function mostrarSugerencias(lista) {
    sugerencias.innerHTML = '';
    if (lista.length === 0) {
        sugerencias.style.display = 'none';
        return;
    }
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
    const filtradas = variantes.filter(v => palabras.every(p => texto(v).includes(p)));
    mostrarSugerencias(filtradas);
});

// =====================
// Buscador por código
// =====================
inputCodigo.addEventListener('input', () => {
    varianteSeleccionada = null;
    const q = inputCodigo.value.trim();
    if (!q) { sugerencias.style.display = 'none'; return; }
    const filtradas = variantes.filter(v => v.codigo_barras && v.codigo_barras.includes(q));
    if (filtradas.length === 1) {
        seleccionarVariante(filtradas[0]);
    } else {
        mostrarSugerencias(filtradas);
    }
});

document.addEventListener('click', e => {
    if (!sugerencias.contains(e.target) && e.target !== inputBuscar) {
        sugerencias.style.display = 'none';
    }
});

// =====================
// Agregar item
// =====================
document.getElementById('mov-agregar').addEventListener('click', () => {
    if (!varianteSeleccionada) {
        alert('Seleccioná un producto primero.');
        return;
    }
    const cantidad = parseInt(document.getElementById('mov-cantidad').value) || 0;
    if (cantidad <= 0) {
        alert('La cantidad debe ser mayor a 0.');
        return;
    }
    const tipo = document.getElementById('mov-tipo').value;

    // Si ya existe el mismo variante + tipo, suma cantidad
    const existe = items.find(i => i.id_variante === varianteSeleccionada.id_variante && i.tipo === tipo);
    if (existe) {
        existe.cantidad += cantidad;
        renderTabla();
    } else {
        items.push({
            id_variante:     varianteSeleccionada.id_variante,
            codigo:          varianteSeleccionada.codigo_barras ?? '',
            nombre_producto: varianteSeleccionada.nombre_producto,
            nombre_variante: varianteSeleccionada.nombre_variante,
            tipo,
            cantidad
        });
        renderTabla();
    }

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

    tbody.innerHTML = '';

    if (items.length === 0) {
        const tr = document.createElement('tr');
        tr.id = 'mov-fila-vacia';
        tr.innerHTML = '<td colspan="6">No hay productos agregados.</td>';
        tbody.appendChild(tr);
        btnGuardar.disabled = true;
        return;
    }

    btnGuardar.disabled = false;

    items.forEach((item, idx) => {
        const variante = item.nombre_variante === 'unica' ? '-' : item.nombre_variante;
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${item.codigo}</td>
            <td>${item.nombre_producto}</td>
            <td>${variante}</td>
            <td>${item.tipo}</td>
            <td>${item.cantidad}</td>
            <td><button type="button" class="btn-eliminar" data-idx="${idx}">&times;</button></td>
        `;
        tbody.appendChild(tr);
    });
}

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
    const fecha        = document.getElementById('mov-fecha').value;
    const observaciones = document.getElementById('mov-observaciones').value;

    if (!fecha)          { alert('Ingresá una fecha.'); return; }
    if (items.length === 0) { alert('Agregá al menos un producto.'); return; }

    fetch('/TYPSISTEMA/app/controllers/stock/movimientos/guardar.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ fecha, observaciones, items, id_deposito: parseInt(document.getElementById('mov-deposito').value) })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.href = '/TYPSISTEMA/app/views/stock/stock_movimientos/index.php?ok=1';
        } else {
            alert('Error: ' + data.error);
        }
    });
});
</script>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../../layouts/main.php';
