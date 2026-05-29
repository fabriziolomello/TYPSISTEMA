<?php
require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

$titulo    = "Nuevo ajuste de stock";
$css_extra = '<link rel="stylesheet" href="' . BASE_URL . 'public/css/stock_movimientos.css">';

$db   = new Database();
$conn = $db->getConnection();

$hoy          = date('Y-m-d');
$categorias   = $conn->query("SELECT id, nombre FROM categoria ORDER BY nombre ASC")->fetch_all(MYSQLI_ASSOC);
$subcategorias = $conn->query("SELECT id, nombre FROM subcategoria ORDER BY nombre ASC")->fetch_all(MYSQLI_ASSOC);

ob_start();
?>

<style>
.aj-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}
.aj-modal-overlay.activo { display: flex; }
.aj-modal {
    background: #fff;
    border-radius: 10px;
    width: 92%;
    max-width: 900px;
    max-height: 85vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 8px 32px rgba(0,0,0,0.2);
}
.aj-modal-header {
    padding: 14px 18px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.aj-modal-header h3 { margin: 0; font-size: 15px; white-space: nowrap; }
.aj-modal-filtros {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
    flex: 1;
}
.aj-modal-filtros input,
.aj-modal-filtros select {
    padding: 6px 10px;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 13px;
}
.aj-modal-filtros input { flex: 1; min-width: 150px; }
.aj-modal-body { overflow-y: auto; flex: 1; }
.aj-modal-body table { width: 100%; border-collapse: collapse; font-size: 13px; }
.aj-modal-body th {
    position: sticky;
    top: 0;
    background: #f8fafc;
    padding: 8px 12px;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
    font-weight: 600;
}
.aj-modal-body td { padding: 7px 12px; border-bottom: 1px solid #f1f5f9; }
.aj-modal-body tr.seleccionable:hover td { background: #eff6ff; cursor: pointer; }
.aj-modal-footer {
    padding: 10px 18px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
    color: #666;
}
.aj-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 18px 20px;
    margin-bottom: 14px;
}
.aj-buscar-row { display: flex; gap: 10px; align-items: center; }
.aj-buscar-row input {
    flex: 1;
    padding: 9px 12px;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 14px;
}
.aj-tabla { width: 100%; border-collapse: collapse; font-size: 14px; }
.aj-tabla th {
    background: #f8fafc;
    padding: 9px 12px;
    text-align: left;
    border-bottom: 2px solid #e2e8f0;
    font-weight: 600;
}
.aj-tabla td { padding: 7px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.aj-cant-input {
    width: 90px;
    padding: 5px 8px;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 14px;
    text-align: center;
}
.aj-cant-input:focus { border-color: #3b82f6; outline: none; box-shadow: 0 0 0 2px #bfdbfe; }
.btn-eliminar { background: none; border: none; color: #ef4444; font-size: 18px; cursor: pointer; padding: 2px 6px; line-height: 1; }
.aj-delta-pos { color: #16a34a; font-size: 12px; margin-left: 4px; }
.aj-delta-neg { color: #dc2626; font-size: 12px; margin-left: 4px; }
.aj-acciones { display: flex; justify-content: flex-end; gap: 12px; margin-top: 4px; }
</style>

<h1 style="margin-bottom:18px;">Nuevo ajuste de stock</h1>

<!-- CABECERA -->
<div class="aj-card">
    <div style="display:flex;gap:20px;flex-wrap:wrap;">
        <div>
            <label style="display:block;font-size:13px;margin-bottom:4px;">Fecha</label>
            <input type="date" id="aj-fecha" value="<?= $hoy ?>"
                style="padding:7px 10px;border:1px solid #ccc;border-radius:4px;font-size:14px;">
        </div>
        <div style="flex:1;min-width:220px;">
            <label style="display:block;font-size:13px;margin-bottom:4px;">Observaciones</label>
            <textarea id="aj-observaciones" rows="2" placeholder="Opcional..."
                style="width:100%;padding:7px 10px;border:1px solid #ccc;border-radius:4px;font-size:14px;resize:vertical;box-sizing:border-box;"></textarea>
        </div>
    </div>
</div>

<!-- BUSCADOR -->
<div class="aj-card">
    <label style="display:block;font-size:13px;color:#555;margin-bottom:8px;">
        Buscá por nombre o código de barras y presioná <strong>Enter</strong> para abrir el buscador
    </label>
    <div class="aj-buscar-row">
        <input type="text" id="aj-buscar" placeholder="Nombre o código de barras..." autocomplete="off">
        <button type="button" class="btn-primary" id="aj-btn-buscar">Buscar</button>
    </div>
</div>

<!-- TABLA DE ITEMS -->
<div class="aj-card">
    <table class="aj-tabla">
        <thead>
            <tr>
                <th>Código</th>
                <th>Producto</th>
                <th>Variante</th>
                <th style="text-align:right;">Stock actual</th>
                <th style="text-align:center;">Cantidad real</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="aj-tbody">
            <tr id="aj-fila-vacia">
                <td colspan="6" style="color:#999;text-align:center;padding:24px;">No hay productos agregados.</td>
            </tr>
        </tbody>
    </table>
</div>

<div class="aj-acciones">
    <a href="<?= BASE_URL ?>app/views/stock/ajuste/index.php" class="btn-link">Cancelar</a>
    <button type="button" class="btn-primary" id="aj-guardar" disabled>Guardar ajuste</button>
</div>

<!-- MODAL -->
<div class="aj-modal-overlay" id="aj-modal">
    <div class="aj-modal">
        <div class="aj-modal-header">
            <h3>Buscar producto</h3>
            <div class="aj-modal-filtros">
                <input type="text" id="modal-q" placeholder="Nombre o código..." autocomplete="off">
                <select id="modal-categoria">
                    <option value="">Todas las categorías</option>
                    <?php foreach ($categorias as $cat): ?>
                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="modal-subcategoria">
                    <option value="">Todas las subcategorías</option>
                    <?php foreach ($subcategorias as $sub): ?>
                    <option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn-primary" id="modal-btn-buscar" style="white-space:nowrap;">Buscar</button>
            </div>
            <button type="button" id="modal-cerrar"
                style="background:none;border:none;font-size:24px;cursor:pointer;color:#666;padding:0 4px;line-height:1;">&times;</button>
        </div>
        <div class="aj-modal-body">
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Producto</th>
                        <th>Variante</th>
                        <th style="text-align:right;">Stock actual</th>
                    </tr>
                </thead>
                <tbody id="modal-tbody">
                    <tr><td colspan="4" style="text-align:center;color:#999;padding:24px;">Buscá un producto para ver resultados.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="aj-modal-footer">
            <span id="modal-count"></span>
            <span style="font-size:12px;">Hacé clic en una fila para agregar el producto</span>
        </div>
    </div>
</div>

<script>
const BASE_URL_AJ = '<?= BASE_URL ?>';
let items = [];

const tbody      = document.getElementById('aj-tbody');
const btnGuardar = document.getElementById('aj-guardar');
const modal      = document.getElementById('aj-modal');

// ── Abrir / cerrar modal ──────────────────────────────────────

function abrirModal(q) {
    document.getElementById('modal-q').value = q ?? '';
    modal.classList.add('activo');
    document.getElementById('modal-q').focus();
    if (q) buscarEnModal();
}

function cerrarModal() {
    modal.classList.remove('activo');
    document.getElementById('aj-buscar').value = '';
    document.getElementById('aj-buscar').focus();
}

document.getElementById('aj-buscar').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); abrirModal(e.target.value.trim()); }
});
document.getElementById('aj-btn-buscar').addEventListener('click', () => {
    abrirModal(document.getElementById('aj-buscar').value.trim());
});
document.getElementById('modal-cerrar').addEventListener('click', cerrarModal);
modal.addEventListener('click', e => { if (e.target === modal) cerrarModal(); });

// ── Búsqueda en modal ─────────────────────────────────────────

document.getElementById('modal-btn-buscar').addEventListener('click', buscarEnModal);
document.getElementById('modal-q').addEventListener('keydown', e => {
    if (e.key === 'Enter') buscarEnModal();
});

function buscarEnModal() {
    const q   = document.getElementById('modal-q').value.trim();
    const cat = document.getElementById('modal-categoria').value;
    const sub = document.getElementById('modal-subcategoria').value;

    const url = new URL(BASE_URL_AJ + 'app/controllers/stock/ajuste/buscar_productos.php', window.location.origin);
    if (q)   url.searchParams.set('q', q);
    if (cat) url.searchParams.set('id_categoria', cat);
    if (sub) url.searchParams.set('id_subcategoria', sub);

    const modalTbody = document.getElementById('modal-tbody');
    modalTbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#999;padding:24px;">Buscando...</td></tr>';

    fetch(url.toString())
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                modalTbody.innerHTML = '<tr><td colspan="4" style="color:red;padding:16px;">Error al buscar.</td></tr>';
                return;
            }
            renderModalResultados(data.productos);
        });
}

function renderModalResultados(productos) {
    const modalTbody = document.getElementById('modal-tbody');
    document.getElementById('modal-count').textContent = productos.length + ' resultado(s)';

    if (productos.length === 0) {
        modalTbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#999;padding:24px;">Sin resultados.</td></tr>';
        return;
    }

    // Una sola coincidencia → agregar directo
    if (productos.length === 1) {
        agregarItem(productos[0]);
        cerrarModal();
        return;
    }

    modalTbody.innerHTML = '';
    productos.forEach(p => {
        const variante = p.nombre_variante === 'unica' ? '' : p.nombre_variante;
        const tr = document.createElement('tr');
        tr.className = 'seleccionable';
        tr.innerHTML = `
            <td>${p.codigo_barras ?? ''}</td>
            <td>${p.nombre_producto}</td>
            <td>${variante}</td>
            <td style="text-align:right;">${p.stock_actual}</td>
        `;
        tr.addEventListener('click', () => { agregarItem(p); cerrarModal(); });
        modalTbody.appendChild(tr);
    });
}

// ── Agregar item ──────────────────────────────────────────────

function agregarItem(p) {
    const idVar = parseInt(p.id_variante);

    // Si ya existe enfocar su input
    if (items.find(i => i.id_variante === idVar)) {
        const input = tbody.querySelector(`input[data-id="${idVar}"]`);
        if (input) { input.focus(); input.select(); }
        return;
    }

    const stockActual = parseInt(p.stock_actual) || 0;
    items.push({
        id_variante:     idVar,
        codigo:          p.codigo_barras ?? '',
        nombre_producto: p.nombre_producto,
        nombre_variante: p.nombre_variante,
        stock_actual:    stockActual,
        cantidad_nueva:  stockActual
    });

    renderTabla();

    // Enfocar el input recién agregado
    setTimeout(() => {
        const input = tbody.querySelector(`input[data-id="${idVar}"]`);
        if (input) { input.focus(); input.select(); }
    }, 40);
}

// ── Render tabla principal ────────────────────────────────────

function renderTabla() {
    tbody.innerHTML = '';

    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="color:#999;text-align:center;padding:24px;">No hay productos agregados.</td></tr>';
        btnGuardar.disabled = true;
        return;
    }

    btnGuardar.disabled = false;

    items.forEach((item, idx) => {
        const variante  = item.nombre_variante === 'unica' ? '' : item.nombre_variante;
        const delta     = item.cantidad_nueva - item.stock_actual;
        const deltaHtml = delta > 0 ? `<span class="aj-delta-pos">+${delta}</span>`
                        : delta < 0 ? `<span class="aj-delta-neg">${delta}</span>`
                        : '';

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${item.codigo}</td>
            <td>${item.nombre_producto}</td>
            <td>${variante}</td>
            <td style="text-align:right;">${item.stock_actual}${deltaHtml}</td>
            <td style="text-align:center;">
                <input type="number" min="0" class="aj-cant-input"
                    value="${item.cantidad_nueva}"
                    data-id="${item.id_variante}" data-idx="${idx}">
            </td>
            <td><button type="button" class="btn-eliminar" data-idx="${idx}">&times;</button></td>
        `;
        tbody.appendChild(tr);
    });
}

// Editar cantidad en tabla
tbody.addEventListener('input', e => {
    const input = e.target.closest('input.aj-cant-input');
    if (!input) return;
    const idx = parseInt(input.dataset.idx);
    const val = parseInt(input.value);
    if (isNaN(val) || val < 0) return;

    items[idx].cantidad_nueva = val;

    const delta     = val - items[idx].stock_actual;
    const deltaHtml = delta > 0 ? `<span class="aj-delta-pos">+${delta}</span>`
                    : delta < 0 ? `<span class="aj-delta-neg">${delta}</span>`
                    : '';
    const td = input.closest('tr').querySelectorAll('td')[3];
    td.innerHTML = `${items[idx].stock_actual}${deltaHtml}`;
});

// Eliminar item
tbody.addEventListener('click', e => {
    const btn = e.target.closest('.btn-eliminar');
    if (!btn) return;
    items.splice(parseInt(btn.dataset.idx), 1);
    renderTabla();
});

// ── Guardar ───────────────────────────────────────────────────

document.getElementById('aj-guardar').addEventListener('click', () => {
    const fecha         = document.getElementById('aj-fecha').value;
    const observaciones = document.getElementById('aj-observaciones').value;

    if (!fecha)             { alert('Ingresá una fecha.'); return; }
    if (items.length === 0) { alert('Agregá al menos un producto.'); return; }

    btnGuardar.disabled    = true;
    btnGuardar.textContent = 'Guardando...';

    fetch(BASE_URL_AJ + 'app/controllers/stock/ajuste/guardar.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ fecha, observaciones, items })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.href = BASE_URL_AJ + 'app/views/stock/ajuste/index.php?ok=1';
        } else {
            alert('Error: ' + data.error);
            btnGuardar.disabled    = false;
            btnGuardar.textContent = 'Guardar ajuste';
        }
    });
});
</script>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../../layouts/main.php';
