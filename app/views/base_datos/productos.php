<?php
// app/views/base_datos/productos.php

$titulo   = "Productos";
$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/productos.css">';

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$db   = new Database();
$conn = $db->getConnection();

// Categorías y proveedores para filtros y modal
$categorias = $conn->query("SELECT id, nombre FROM categoria ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
$proveedores = $conn->query("SELECT id, nombre FROM proveedor ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

// Filtros
$q          = trim($_GET['q'] ?? '');
$idCat      = (int)($_GET['categoria'] ?? 0);
$idProv     = (int)($_GET['proveedor'] ?? 0);
$estado     = $_GET['estado'] ?? 'activos';
if (!in_array($estado, ['activos', 'inactivos', 'todos'], true)) $estado = 'activos';

$conds  = [];
$params = [];
$types  = '';

if ($estado === 'activos')   $conds[] = "p.activo = 1";
if ($estado === 'inactivos') $conds[] = "p.activo = 0";

if ($idCat > 0)  { $conds[] = "p.id_categoria = ?"; $params[] = $idCat;  $types .= 'i'; }
if ($idProv > 0) { $conds[] = "p.id_proveedor = ?"; $params[] = $idProv; $types .= 'i'; }

if ($q !== '') {
    $palabras = array_filter(explode(' ', $q));
    foreach ($palabras as $palabra) {
        $conds[] = "(p.nombre LIKE CONCAT('%',?,'%') OR p.codigo_barras LIKE CONCAT('%',?,'%'))";
        $params[] = $palabra; $params[] = $palabra;
        $types .= 'ss';
    }
}

$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$sql = "
    SELECT
        p.id, p.nombre, p.codigo_barras, p.precio_costo, p.activo,
        p.id_categoria, p.id_proveedor,
        c.nombre AS categoria,
        pr.nombre AS proveedor,
        COALESCE(SUM(pv.stock_actual), 0) AS stock_total,
        MAX(CASE WHEN lp.tipo_lista = 'MINORISTA' THEN lp.precio END) AS precio_minorista,
        MAX(CASE WHEN lp.tipo_lista = 'MAYORISTA' THEN lp.precio END) AS precio_mayorista
    FROM productos p
    LEFT JOIN categoria c ON c.id = p.id_categoria
    LEFT JOIN proveedor pr ON pr.id = p.id_proveedor
    LEFT JOIN producto_variante pv ON pv.id_producto = p.id
    LEFT JOIN lista_precio lp ON lp.id_producto = p.id
    $where
    GROUP BY p.id, p.nombre, p.codigo_barras, p.precio_costo, p.activo, c.nombre, pr.nombre
    ORDER BY p.nombre ASC
";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$productos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$esAdmin = ($_SESSION['usuario_rol'] ?? '') === 'ADMIN';

ob_start();
?>

<div class="prod-container">

    <!-- Cabecera -->
    <div class="prod-header">
        <h1 class="prod-titulo">Productos</h1>
        <div class="prod-header-acciones">
            <a href="/TYPSISTEMA/app/controllers/base_datos/productos/exportar.php" class="btn-link">Exportar CSV</a>
            <button type="button" class="btn-link" id="btn-importar">Importar CSV</button>
            <button type="button" class="btn-primary" id="btn-nuevo-producto">+ Nuevo producto</button>
        </div>
    </div>

    <!-- Filtros -->
    <form method="get" class="prod-filtros">
        <div class="prod-field">
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar por nombre o código...">
        </div>
        <div class="prod-field">
            <select name="categoria">
                <option value="0">Todas las categorías</option>
                <?php foreach ($categorias as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $idCat === (int)$cat['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="prod-field">
            <select name="proveedor">
                <option value="0">Todos los proveedores</option>
                <?php foreach ($proveedores as $prov): ?>
                    <option value="<?= $prov['id'] ?>" <?= $idProv === (int)$prov['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($prov['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="prod-field">
            <select name="estado">
                <option value="activos"   <?= $estado === 'activos'   ? 'selected' : '' ?>>Activos</option>
                <option value="inactivos" <?= $estado === 'inactivos' ? 'selected' : '' ?>>Inactivos</option>
                <option value="todos"     <?= $estado === 'todos'     ? 'selected' : '' ?>>Todos</option>
            </select>
        </div>
        <button type="submit" class="btn-primary">Filtrar</button>
        <a href="/TYPSISTEMA/app/views/base_datos/productos.php" class="btn-link">Limpiar</a>
    </form>

    <!-- Tabla -->
    <div class="prod-tabla-wrapper">
        <table class="prod-tabla">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Código</th>
                    <th>Categoría</th>
                    <th>Proveedor</th>
                    <?php if ($esAdmin): ?><th>Costo</th><?php endif; ?>
                    <th>Minorista</th>
                    <th>Mayorista</th>
                    <th>Stock</th>
                    <?php if ($esAdmin): ?><th>Estado</th><?php endif; ?>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($productos)): ?>
                    <tr><td colspan="10">No se encontraron productos.</td></tr>
                <?php else: ?>
                    <?php foreach ($productos as $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($p['nombre']) ?></td>
                            <td><?= htmlspecialchars($p['codigo_barras'] ?? '') ?></td>
                            <td><?= htmlspecialchars($p['categoria'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($p['proveedor'] ?? '-') ?></td>
                            <?php if ($esAdmin): ?>
                            <td class="col-monto">$<?= number_format($p['precio_costo'] ?? 0, 2, ',', '.') ?></td>
                            <?php endif; ?>
                            <td class="col-monto">$<?= number_format($p['precio_minorista'] ?? 0, 2, ',', '.') ?></td>
                            <td class="col-monto">$<?= number_format($p['precio_mayorista'] ?? 0, 2, ',', '.') ?></td>
                            <td class="col-centro"><?= (int)$p['stock_total'] ?></td>
                            <?php if ($esAdmin): ?>
                            <td class="col-centro">
                                <span class="prod-badge prod-badge--<?= $p['activo'] ? 'activo' : 'inactivo' ?>">
                                    <?= $p['activo'] ? 'Activo' : 'Inactivo' ?>
                                </span>
                            </td>
                            <?php endif; ?>
                            <td class="col-acciones">
                                <button
                                    type="button"
                                    class="btn-accion btn-editar"
                                    data-id="<?= $p['id'] ?>"
                                    data-nombre="<?= htmlspecialchars($p['nombre'], ENT_QUOTES) ?>"
                                    data-codigo="<?= htmlspecialchars($p['codigo_barras'] ?? '', ENT_QUOTES) ?>"
                                    data-categoria="<?= (int)($p['id_categoria'] ?? 0) ?>"
                                    data-proveedor="<?= (int)($p['id_proveedor'] ?? 0) ?>"
                                    data-costo="<?= number_format($p['precio_costo'] ?? 0, 2, '.', '') ?>"
                                    data-minorista="<?= number_format($p['precio_minorista'] ?? 0, 2, '.', '') ?>"
                                    data-mayorista="<?= number_format($p['precio_mayorista'] ?? 0, 2, '.', '') ?>"
                                    data-activo="<?= (int)$p['activo'] ?>"
                                >Editar</button>
                                <button
                                    type="button"
                                    class="btn-accion btn-eliminar"
                                    data-id="<?= $p['id'] ?>"
                                    data-nombre="<?= htmlspecialchars($p['nombre'], ENT_QUOTES) ?>"
                                    data-activo="<?= (int)$p['activo'] ?>"
                                ><?= $p['activo'] ? 'Desactivar' : 'Activar' ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- =====================
     MODAL IMPORTAR CSV
     ===================== -->
<div class="modal-overlay" id="modal-importar" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-header">
            <h2>Importar productos (CSV)</h2>
            <button type="button" class="modal-cerrar" id="modal-importar-cerrar">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:13px;color:#555;margin-bottom:12px">
                El archivo debe tener el mismo formato que la exportación:<br>
                <strong>Nombre ; Código producto ; Categoría ; Proveedor ; Costo ; Minorista ; Mayorista ; Variante ; Código variante ; Stock ; Estado</strong><br><br>
                Los productos existentes (mismo nombre) no se modifican. Las categorías y proveedores nuevos se crean automáticamente.<br><br>
                <a href="/TYPSISTEMA/app/controllers/base_datos/productos/ejemplo_importar.php" style="color:var(--azul)">Descargar archivo de ejemplo</a>
            </p>
            <div class="modal-field">
                <label>Archivo CSV</label>
                <input type="file" id="imp-archivo" accept=".csv">
            </div>
            <div id="imp-resultado" style="margin-top:12px;font-size:13px"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-link" id="modal-importar-cancelar">Cancelar</button>
            <button type="button" class="btn-primary" id="imp-guardar">Importar</button>
        </div>
    </div>
</div>

<!-- =====================
     MODAL EDITAR PRODUCTO
     ===================== -->
<div class="modal-overlay" id="modal-editar" aria-hidden="true">
    <div class="modal-dialog modal-dialog--lg">
        <div class="modal-header">
            <h2>Editar producto</h2>
            <button type="button" class="modal-cerrar" id="modal-editar-cerrar">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="ep-id">
            <input type="hidden" id="ep-activo">

            <fieldset class="modal-section">
                <legend>Datos generales</legend>
                <div class="modal-grid">
                    <div class="modal-field modal-field--wide">
                        <label>Nombre *</label>
                        <input type="text" id="ep-nombre">
                    </div>
                    <div class="modal-field">
                        <label>Código de barras</label>
                        <input type="text" id="ep-codigo">
                    </div>
                    <div class="modal-field">
                        <label>Categoría</label>
                        <select id="ep-categoria">
                            <option value="">Sin categoría</option>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="modal-field">
                        <label>Proveedor</label>
                        <select id="ep-proveedor">
                            <option value="">Sin proveedor</option>
                            <?php foreach ($proveedores as $prov): ?>
                                <option value="<?= $prov['id'] ?>"><?= htmlspecialchars($prov['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </fieldset>

            <fieldset class="modal-section">
                <legend>Precios</legend>
                <div class="modal-grid">
                    <div class="modal-field">
                        <label>Precio costo</label>
                        <input type="number" id="ep-costo" min="0" step="0.01">
                    </div>
                    <div class="modal-field">
                        <label>Precio minorista</label>
                        <input type="number" id="ep-minorista" min="0" step="0.01">
                    </div>
                    <div class="modal-field">
                        <label>Precio mayorista</label>
                        <input type="number" id="ep-mayorista" min="0" step="0.01">
                    </div>
                </div>
            </fieldset>
            <fieldset class="modal-section">
                <legend>Variantes</legend>
                <div id="ep-variantes-lista"></div>
                <button type="button" class="btn-link btn-agregar-var" id="ep-agregar-variante">+ Agregar variante</button>
            </fieldset>

        </div>
        <div class="modal-footer">
            <button type="button" class="btn-link" id="modal-editar-cancelar">Cancelar</button>
            <button type="button" class="btn-primary" id="ep-guardar">Guardar cambios</button>
        </div>
    </div>
</div>

<!-- =====================
     MODAL NUEVO PRODUCTO
     ===================== -->
<div class="modal-overlay" id="modal-producto" aria-hidden="true">
    <div class="modal-dialog modal-dialog--lg">
        <div class="modal-header">
            <h2>Nuevo producto</h2>
            <button type="button" class="modal-cerrar" id="modal-cerrar">&times;</button>
        </div>
        <div class="modal-body">

            <!-- Datos generales -->
            <fieldset class="modal-section">
                <legend>Datos generales</legend>
                <div class="modal-grid">
                    <div class="modal-field modal-field--wide">
                        <label>Nombre *</label>
                        <input type="text" id="np-nombre" placeholder="Nombre del producto">
                    </div>
                    <div class="modal-field">
                        <label>Código de barras</label>
                        <input type="text" id="np-codigo" placeholder="Opcional">
                    </div>
                    <div class="modal-field">
                        <label>Categoría</label>
                        <select id="np-categoria">
                            <option value="">Sin categoría</option>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="modal-field">
                        <label>Proveedor</label>
                        <select id="np-proveedor">
                            <option value="">Sin proveedor</option>
                            <?php foreach ($proveedores as $prov): ?>
                                <option value="<?= $prov['id'] ?>"><?= htmlspecialchars($prov['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </fieldset>

            <!-- Precios -->
            <fieldset class="modal-section">
                <legend>Precios</legend>
                <div class="modal-grid">
                    <div class="modal-field">
                        <label>Precio costo</label>
                        <input type="number" id="np-costo" min="0" step="0.01" placeholder="0,00">
                    </div>
                    <div class="modal-field">
                        <label>Precio minorista</label>
                        <input type="number" id="np-minorista" min="0" step="0.01" placeholder="0,00">
                    </div>
                    <div class="modal-field">
                        <label>Precio mayorista</label>
                        <input type="number" id="np-mayorista" min="0" step="0.01" placeholder="0,00">
                    </div>
                </div>
            </fieldset>

            <!-- Variantes -->
            <fieldset class="modal-section">
                <legend>Variantes</legend>
                <p class="modal-hint">Si el producto no tiene variantes dejá Color y Talle vacíos. Podés usar solo uno de los dos.</p>
                <div id="np-variantes-lista">
                    <!-- fila inicial -->
                    <div class="variante-fila">
                        <input type="text"   class="var-color"  placeholder="Color (opcional)">
                        <input type="text"   class="var-talle"  placeholder="Talle (opcional)">
                        <input type="text"   class="var-codigo" placeholder="Código de barras (opcional)">
                        <input type="number" class="var-stock"  placeholder="Stock inicial" min="0" value="0">
                        <button type="button" class="btn-eliminar-var" title="Eliminar">&times;</button>
                    </div>
                </div>
                <button type="button" class="btn-link btn-agregar-var" id="np-agregar-variante">+ Agregar variante</button>
            </fieldset>

        </div>
        <div class="modal-footer">
            <button type="button" class="btn-link" id="modal-cancelar">Cancelar</button>
            <button type="button" class="btn-primary" id="np-guardar">Guardar producto</button>
        </div>
    </div>
</div>

<script>
// =====================
// Modal
// =====================
const modalEl   = document.getElementById('modal-producto');
const btnNuevo  = document.getElementById('btn-nuevo-producto');
const btnCerrar = document.getElementById('modal-cerrar');
const btnCancel = document.getElementById('modal-cancelar');

function abrirModal() {
    modalEl.classList.add('modal-overlay--visible');
    document.body.classList.add('modal-abierto');
}

function cerrarModal() {
    modalEl.classList.remove('modal-overlay--visible');
    document.body.classList.remove('modal-abierto');
}

btnNuevo.addEventListener('click', abrirModal);
btnCerrar.addEventListener('click', cerrarModal);
btnCancel.addEventListener('click', cerrarModal);
modalEl.addEventListener('click', e => { if (e.target === modalEl) cerrarModal(); });

// =====================
// Variantes
// =====================
function crearFilaVariante(color = '', talle = '', codigo = '', stock = 0) {
    const div = document.createElement('div');
    div.className = 'variante-fila';
    div.innerHTML = `
        <input type="text"   class="var-color"  placeholder="Color (opcional)" value="${color}">
        <input type="text"   class="var-talle"  placeholder="Talle (opcional)" value="${talle}">
        <input type="text"   class="var-codigo" placeholder="Código de barras (opcional)" value="${codigo}">
        <input type="number" class="var-stock"  placeholder="Stock inicial" min="0" value="${stock}">
        <button type="button" class="btn-eliminar-var" title="Eliminar">&times;</button>
    `;
    return div;
}

document.getElementById('np-agregar-variante').addEventListener('click', () => {
    document.getElementById('np-variantes-lista').appendChild(crearFilaVariante());
});

document.getElementById('np-variantes-lista').addEventListener('click', e => {
    if (e.target.classList.contains('btn-eliminar-var')) {
        const filas = document.querySelectorAll('.variante-fila');
        if (filas.length === 1) { alert('Tiene que haber al menos una variante.'); return; }
        e.target.closest('.variante-fila').remove();
    }
});

// =====================
// Guardar
// =====================
document.getElementById('np-guardar').addEventListener('click', () => {
    const nombre    = document.getElementById('np-nombre').value.trim();
    const codigo    = document.getElementById('np-codigo').value.trim();
    const categoria = document.getElementById('np-categoria').value || null;
    const proveedor = document.getElementById('np-proveedor').value || null;
    const costo     = parseFloat(document.getElementById('np-costo').value) || 0;
    const minorista = parseFloat(document.getElementById('np-minorista').value) || 0;
    const mayorista = parseFloat(document.getElementById('np-mayorista').value) || 0;

    if (!nombre) { alert('El nombre es obligatorio.'); return; }

    const variantes = [];
    document.querySelectorAll('#np-variantes-lista .variante-fila').forEach(fila => {
        const color  = fila.querySelector('.var-color').value.trim();
        const talle  = fila.querySelector('.var-talle').value.trim();
        const codigo = fila.querySelector('.var-codigo').value.trim();
        const stock  = parseInt(fila.querySelector('.var-stock').value) || 0;
        variantes.push({ color, talle, codigo, stock });
    });

    if (variantes.length === 0) { alert('Agregá al menos una variante.'); return; }

    fetch('/TYPSISTEMA/app/controllers/base_datos/productos/guardar.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ nombre, codigo, categoria, proveedor, costo, minorista, mayorista, variantes })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
});
// =====================
// Importar CSV
// =====================
const modalImportar = document.getElementById('modal-importar');
document.getElementById('btn-importar').addEventListener('click', () => {
    modalImportar.classList.add('modal-overlay--visible');
    document.body.classList.add('modal-abierto');
});
document.getElementById('modal-importar-cerrar').addEventListener('click', () => {
    modalImportar.classList.remove('modal-overlay--visible');
    document.body.classList.remove('modal-abierto');
});
document.getElementById('modal-importar-cancelar').addEventListener('click', () => {
    modalImportar.classList.remove('modal-overlay--visible');
    document.body.classList.remove('modal-abierto');
});
modalImportar.addEventListener('click', e => { if (e.target === modalImportar) { modalImportar.classList.remove('modal-overlay--visible'); document.body.classList.remove('modal-abierto'); }});

document.getElementById('imp-guardar').addEventListener('click', () => {
    const archivo = document.getElementById('imp-archivo').files[0];
    if (!archivo) { alert('Seleccioná un archivo CSV.'); return; }

    const formData = new FormData();
    formData.append('archivo', archivo);

    const resultado = document.getElementById('imp-resultado');
    resultado.textContent = 'Importando...';

    fetch('/TYPSISTEMA/app/controllers/base_datos/productos/importar.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            let msg = `✓ ${data.insertados} producto(s) importado(s).`;
            if (data.errores && data.errores.length > 0) {
                msg += '<br><span style="color:#c00">Advertencias:<br>' + data.errores.join('<br>') + '</span>';
            }
            resultado.innerHTML = msg;
            if (data.insertados > 0) setTimeout(() => window.location.reload(), 1500);
        } else {
            resultado.innerHTML = '<span style="color:#c00">Error: ' + data.error + '</span>';
        }
    });
});

// =====================
// Editar producto
// =====================
const modalEditar   = document.getElementById('modal-editar');
const btnCerrarEdit = document.getElementById('modal-editar-cerrar');
const btnCancelEdit = document.getElementById('modal-editar-cancelar');

function crearFilaVarianteEditar(id = '', color = '', talle = '', codigo = '', stock = 0) {
    const div = document.createElement('div');
    div.className = 'variante-fila';
    div.innerHTML = `
        <input type="hidden" class="var-id" value="${id}">
        <input type="text"   class="var-color"  placeholder="Color (opcional)"  value="${color}">
        <input type="text"   class="var-talle"  placeholder="Talle (opcional)"  value="${talle}">
        <input type="text"   class="var-codigo" placeholder="Código de barras (opcional)" value="${codigo}">
        <input type="number" class="var-stock"  placeholder="Stock" min="0" value="${stock}" ${id ? 'disabled title="El stock se modifica desde Ingreso/Egreso"' : ''}>
        <button type="button" class="btn-eliminar-var" title="Eliminar">&times;</button>
    `;
    return div;
}

function abrirModalEditar(btn) {
    document.getElementById('ep-id').value        = btn.dataset.id;
    document.getElementById('ep-nombre').value    = btn.dataset.nombre;
    document.getElementById('ep-codigo').value    = btn.dataset.codigo;
    document.getElementById('ep-costo').value     = btn.dataset.costo;
    document.getElementById('ep-minorista').value = btn.dataset.minorista;
    document.getElementById('ep-mayorista').value = btn.dataset.mayorista;
    document.getElementById('ep-categoria').value = btn.dataset.categoria;
    document.getElementById('ep-proveedor').value = btn.dataset.proveedor;
    document.getElementById('ep-activo').value    = btn.dataset.activo;

    // Cargar variantes existentes
    const lista = document.getElementById('ep-variantes-lista');
    lista.innerHTML = '<p style="color:#888;font-size:13px">Cargando variantes...</p>';

    fetch('/TYPSISTEMA/app/controllers/base_datos/productos/variantes.php?id=' + btn.dataset.id)
        .then(r => r.json())
        .then(data => {
            lista.innerHTML = '';
            if (data.variantes && data.variantes.length > 0) {
                data.variantes.forEach(v => {
                    lista.appendChild(crearFilaVarianteEditar(v.id, v.color ?? '', v.talle ?? '', v.codigo_barras ?? '', v.stock_actual));
                });
            } else {
                lista.appendChild(crearFilaVarianteEditar());
            }
        });

    modalEditar.classList.add('modal-overlay--visible');
    document.body.classList.add('modal-abierto');
}

function cerrarModalEditar() {
    modalEditar.classList.remove('modal-overlay--visible');
    document.body.classList.remove('modal-abierto');
}

btnCerrarEdit.addEventListener('click', cerrarModalEditar);
btnCancelEdit.addEventListener('click', cerrarModalEditar);
modalEditar.addEventListener('click', e => { if (e.target === modalEditar) cerrarModalEditar(); });

document.querySelectorAll('.btn-editar').forEach(btn => {
    btn.addEventListener('click', () => abrirModalEditar(btn));
});

document.getElementById('ep-agregar-variante').addEventListener('click', () => {
    document.getElementById('ep-variantes-lista').appendChild(crearFilaVarianteEditar());
});

document.getElementById('ep-variantes-lista').addEventListener('click', e => {
    if (e.target.classList.contains('btn-eliminar-var')) {
        const filas = document.getElementById('ep-variantes-lista').querySelectorAll('.variante-fila');
        if (filas.length === 1) { alert('Tiene que haber al menos una variante.'); return; }
        e.target.closest('.variante-fila').remove();
    }
});

document.getElementById('ep-guardar').addEventListener('click', () => {
    const id        = document.getElementById('ep-id').value;
    const nombre    = document.getElementById('ep-nombre').value.trim();
    const codigo    = document.getElementById('ep-codigo').value.trim();
    const categoria = document.getElementById('ep-categoria').value || null;
    const proveedor = document.getElementById('ep-proveedor').value || null;
    const costo     = parseFloat(document.getElementById('ep-costo').value) || 0;
    const minorista = parseFloat(document.getElementById('ep-minorista').value) || 0;
    const mayorista = parseFloat(document.getElementById('ep-mayorista').value) || 0;

    if (!nombre) { alert('El nombre es obligatorio.'); return; }

    const variantes = [];
    document.querySelectorAll('#ep-variantes-lista .variante-fila').forEach(fila => {
        const vid    = fila.querySelector('.var-id').value;
        const vcolor  = fila.querySelector('.var-color').value.trim();
        const vtalle  = fila.querySelector('.var-talle').value.trim();
        const vcodigo = fila.querySelector('.var-codigo').value.trim();
        const vstock  = parseInt(fila.querySelector('.var-stock').value) || 0;
        variantes.push({ id: vid, color: vcolor, talle: vtalle, codigo: vcodigo, stock: vstock });
    });

    if (variantes.length === 0) { alert('Agregá al menos una variante.'); return; }

    fetch('/TYPSISTEMA/app/controllers/base_datos/productos/editar.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, nombre, codigo, categoria, proveedor, costo, minorista, mayorista, variantes })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { window.location.reload(); }
        else { alert('Error: ' + data.error); }
    });
});

// =====================
// Desactivar / Activar
// =====================
document.querySelectorAll('.btn-eliminar').forEach(btn => {
    btn.addEventListener('click', () => {
        const activo = parseInt(btn.dataset.activo);
        const accion = activo ? 'desactivar' : 'activar';
        if (!confirm(`¿Seguro que querés ${accion} "${btn.dataset.nombre}"?`)) return;

        fetch('/TYPSISTEMA/app/controllers/base_datos/productos/toggleActivo.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: btn.dataset.id, activo: activo ? 0 : 1 })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) { window.location.reload(); }
            else { alert('Error: ' + data.error); }
        });
    });
});
</script>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';
