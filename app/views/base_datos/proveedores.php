<?php
// app/views/base_datos/proveedores.php

$titulo    = "Proveedores";
$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/proveedores.css">';

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$db   = new Database();
$conn = $db->getConnection();

$q = trim($_GET['q'] ?? '');

$conds  = [];
$params = [];
$types  = '';

if ($q !== '') {
    $palabras = array_filter(explode(' ', $q));
    foreach ($palabras as $palabra) {
        $conds[]  = "p.nombre LIKE CONCAT('%',?,'%')";
        $params[] = $palabra;
        $types   .= 's';
    }
}

$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$stmt = $conn->prepare("
    SELECT p.id, p.nombre, COUNT(pr.id) AS total_productos
    FROM proveedor p
    LEFT JOIN productos pr ON pr.id_proveedor = p.id
    $where
    GROUP BY p.id, p.nombre
    ORDER BY p.nombre ASC
");
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$proveedores = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

ob_start();
?>

<div class="prov-container">

    <div class="prov-header">
        <h1 class="prov-titulo">Proveedores</h1>
        <button type="button" class="btn-primary" id="btn-nuevo-proveedor">+ Nuevo proveedor</button>
    </div>

    <!-- Filtros -->
    <form method="get" class="prov-filtros">
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar por nombre...">
        <button type="submit" class="btn-primary">Filtrar</button>
        <a href="/TYPSISTEMA/app/views/base_datos/proveedores.php" class="btn-link">Limpiar</a>
    </form>

    <!-- Tabla -->
    <div class="prov-tabla-wrapper">
        <table class="prov-tabla">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Productos</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($proveedores)): ?>
                    <tr><td colspan="3">No se encontraron proveedores.</td></tr>
                <?php else: ?>
                    <?php foreach ($proveedores as $p): ?>
                        <tr>
                            <td>
                                <a href="#" class="prov-link btn-ver-productos"
                                    data-id="<?= $p['id'] ?>"
                                    data-nombre="<?= htmlspecialchars($p['nombre'], ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($p['nombre']) ?>
                                </a>
                            </td>
                            <td><?= (int)$p['total_productos'] ?></td>
                            <td class="col-acciones">
                                <button type="button" class="btn-accion btn-editar btn-editar-proveedor"
                                    data-id="<?= $p['id'] ?>"
                                    data-nombre="<?= htmlspecialchars($p['nombre'], ENT_QUOTES) ?>">
                                    Editar
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- =====================
     MODAL NUEVO / EDITAR PROVEEDOR
     ===================== -->
<div class="modal-overlay" id="modal-proveedor">
    <div class="modal-dialog">
        <div class="modal-header">
            <h2 id="modal-prov-titulo">Nuevo proveedor</h2>
            <button type="button" class="modal-cerrar" id="modal-prov-cerrar">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="prov-id">
            <div class="modal-field">
                <label>Nombre *</label>
                <input type="text" id="prov-nombre">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-link" id="modal-prov-cancelar">Cancelar</button>
            <button type="button" class="btn-primary" id="prov-guardar">Guardar</button>
        </div>
    </div>
</div>

<!-- =====================
     MODAL PRODUCTOS DEL PROVEEDOR
     ===================== -->
<div class="modal-overlay" id="modal-productos-prov">
    <div class="modal-dialog modal-dialog--lg">
        <div class="modal-header">
            <h2 id="modal-prod-prov-titulo">Productos del proveedor</h2>
            <button type="button" class="modal-cerrar" id="modal-prod-prov-cerrar">&times;</button>
        </div>
        <div class="modal-body" id="modal-prod-prov-body">
            <p style="color:#888">Cargando...</p>
        </div>
    </div>
</div>

<script>
function formatoPrecio(n) {
    return '$' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// =====================
// Modal proveedor (crear/editar)
// =====================
const modalProv = document.getElementById('modal-proveedor');

function abrirModalProv(titulo, id = '', nombre = '') {
    document.getElementById('modal-prov-titulo').textContent = titulo;
    document.getElementById('prov-id').value     = id;
    document.getElementById('prov-nombre').value = nombre;
    modalProv.classList.add('modal-overlay--visible');
    document.body.classList.add('modal-abierto');
    setTimeout(() => document.getElementById('prov-nombre').focus(), 50);
}

function cerrarModalProv() {
    modalProv.classList.remove('modal-overlay--visible');
    document.body.classList.remove('modal-abierto');
}

document.getElementById('btn-nuevo-proveedor').addEventListener('click', () => abrirModalProv('Nuevo proveedor'));
document.getElementById('modal-prov-cerrar').addEventListener('click', cerrarModalProv);
document.getElementById('modal-prov-cancelar').addEventListener('click', cerrarModalProv);
modalProv.addEventListener('click', e => { if (e.target === modalProv) cerrarModalProv(); });

document.querySelectorAll('.btn-editar-proveedor').forEach(btn => {
    btn.addEventListener('click', () => abrirModalProv('Editar proveedor', btn.dataset.id, btn.dataset.nombre));
});

document.getElementById('prov-guardar').addEventListener('click', () => {
    const id     = document.getElementById('prov-id').value;
    const nombre = document.getElementById('prov-nombre').value.trim();

    if (!nombre) { alert('El nombre es obligatorio.'); return; }

    fetch('/TYPSISTEMA/app/controllers/base_datos/proveedores/guardar.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, nombre })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { window.location.reload(); }
        else { alert('Error: ' + data.error); }
    });
});

// =====================
// Modal productos del proveedor
// =====================
const modalProdProv = document.getElementById('modal-productos-prov');

function cerrarModalProdProv() {
    modalProdProv.classList.remove('modal-overlay--visible');
    document.body.classList.remove('modal-abierto');
}

document.getElementById('modal-prod-prov-cerrar').addEventListener('click', cerrarModalProdProv);
modalProdProv.addEventListener('click', e => { if (e.target === modalProdProv) cerrarModalProdProv(); });

document.querySelectorAll('.btn-ver-productos').forEach(btn => {
    btn.addEventListener('click', e => {
        e.preventDefault();
        document.getElementById('modal-prod-prov-titulo').textContent = btn.dataset.nombre;
        const body = document.getElementById('modal-prod-prov-body');
        body.innerHTML = '<p style="color:#888">Cargando...</p>';
        modalProdProv.classList.add('modal-overlay--visible');
        document.body.classList.add('modal-abierto');

        fetch('/TYPSISTEMA/app/controllers/base_datos/proveedores/productos.php?id=' + btn.dataset.id)
            .then(r => r.json())
            .then(data => {
                if (!data.success) { body.innerHTML = '<p style="color:red">Error: ' + data.error + '</p>'; return; }
                if (!data.productos || data.productos.length === 0) {
                    body.innerHTML = '<p style="color:#888">Este proveedor no tiene productos.</p>';
                    return;
                }

                let html = `<table class="prov-tabla">
                    <thead><tr>
                        <th>Producto</th>
                        <th>Categoría</th>
                        <th>Stock</th>
                        <th>Costo</th>
                        <th>Minorista</th>
                        <th>Mayorista</th>
                        <th>Estado</th>
                    </tr></thead><tbody>`;

                data.productos.forEach(p => {
                    html += `<tr class="${p.activo == 0 ? 'fila-inactiva' : ''}">
                        <td>${p.nombre}</td>
                        <td>${p.categoria ?? '-'}</td>
                        <td style="text-align:center">${p.stock_actual}</td>
                        <td class="col-monto">${p.precio_costo > 0 ? formatoPrecio(p.precio_costo) : '-'}</td>
                        <td class="col-monto">${p.precio_minorista ? formatoPrecio(p.precio_minorista) : '-'}</td>
                        <td class="col-monto">${p.precio_mayorista ? formatoPrecio(p.precio_mayorista) : '-'}</td>
                        <td style="text-align:center"><span class="prov-badge prov-badge--${p.activo == 1 ? 'activo' : 'inactivo'}">${p.activo == 1 ? 'Activo' : 'Inactivo'}</span></td>
                    </tr>`;
                });

                html += '</tbody></table>';
                body.innerHTML = html;
            });
    });
});
</script>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';
