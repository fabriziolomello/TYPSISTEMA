<?php
// app/views/base_datos/clientes.php

$titulo   = "Clientes";
$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/clientes.css">';

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$db   = new Database();
$conn = $db->getConnection();

// Filtros
$q = trim($_GET['q'] ?? '');

$conds  = [];
$params = [];
$types  = '';

if ($q !== '') {
    $palabras = array_filter(explode(' ', $q));
    foreach ($palabras as $palabra) {
        $conds[] = "(c.nombre LIKE CONCAT('%',?,'%') OR c.cuit LIKE CONCAT('%',?,'%'))";
        $params[] = $palabra; $params[] = $palabra;
        $types .= 'ss';
    }
}

$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$stmt = $conn->prepare("
    SELECT id, nombre, cuit, telefono, email, saldo_pendiente
    FROM clientes c
    $where
    ORDER BY nombre ASC
");
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$clientes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

ob_start();
?>

<div class="cli-container">

    <div class="cli-header">
        <h1 class="cli-titulo">Clientes</h1>
        <button type="button" class="btn-primary" id="btn-nuevo-cliente">+ Nuevo cliente</button>
    </div>

    <!-- Filtros -->
    <form method="get" class="cli-filtros">
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar por nombre o CUIT...">
        <button type="submit" class="btn-primary">Filtrar</button>
        <a href="/TYPSISTEMA/app/views/base_datos/clientes.php" class="btn-link">Limpiar</a>
    </form>

    <!-- Tabla -->
    <div class="cli-tabla-wrapper">
        <table class="cli-tabla">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>CUIT</th>
                    <th>Teléfono</th>
                    <th>Email</th>
                    <th>Saldo pendiente</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clientes)): ?>
                    <tr><td colspan="6">No se encontraron clientes.</td></tr>
                <?php else: ?>
                    <?php foreach ($clientes as $c): ?>
                        <tr>
                            <td>
                                <a href="#" class="cli-link btn-ver-compras"
                                    data-id="<?= $c['id'] ?>"
                                    data-nombre="<?= htmlspecialchars($c['nombre'], ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($c['nombre']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($c['cuit'] ?? '') ?></td>
                            <td><?= htmlspecialchars($c['telefono'] ?? '') ?></td>
                            <td><?= htmlspecialchars($c['email'] ?? '') ?></td>
                            <td class="col-monto <?= $c['saldo_pendiente'] > 0 ? 'saldo-pendiente' : '' ?>">
                                $<?= number_format($c['saldo_pendiente'], 2, ',', '.') ?>
                            </td>
                            <td class="col-acciones">
                                <button type="button" class="btn-accion btn-editar btn-editar-cliente"
                                    data-id="<?= $c['id'] ?>"
                                    data-nombre="<?= htmlspecialchars($c['nombre'], ENT_QUOTES) ?>"
                                    data-cuit="<?= htmlspecialchars($c['cuit'] ?? '', ENT_QUOTES) ?>"
                                    data-telefono="<?= htmlspecialchars($c['telefono'] ?? '', ENT_QUOTES) ?>"
                                    data-email="<?= htmlspecialchars($c['email'] ?? '', ENT_QUOTES) ?>"
                                >Editar</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- =====================
     MODAL NUEVO / EDITAR CLIENTE
     ===================== -->
<div class="modal-overlay" id="modal-cliente">
    <div class="modal-dialog">
        <div class="modal-header">
            <h2 id="modal-cliente-titulo">Nuevo cliente</h2>
            <button type="button" class="modal-cerrar" id="modal-cliente-cerrar">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="cli-id">
            <div class="modal-grid">
                <div class="modal-field modal-field--wide">
                    <label>Nombre *</label>
                    <input type="text" id="cli-nombre">
                </div>
                <div class="modal-field">
                    <label>CUIT</label>
                    <input type="text" id="cli-cuit" placeholder="Opcional">
                </div>
                <div class="modal-field">
                    <label>Teléfono</label>
                    <input type="text" id="cli-telefono" placeholder="Opcional">
                </div>
                <div class="modal-field modal-field--wide">
                    <label>Email</label>
                    <input type="email" id="cli-email" placeholder="Opcional">
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-link" id="modal-cliente-cancelar">Cancelar</button>
            <button type="button" class="btn-primary" id="cli-guardar">Guardar</button>
        </div>
    </div>
</div>

<!-- =====================
     MODAL COMPRAS DEL CLIENTE
     ===================== -->
<div class="modal-overlay" id="modal-compras">
    <div class="modal-dialog modal-dialog--lg">
        <div class="modal-header">
            <h2 id="modal-compras-titulo">Compras del cliente</h2>
            <button type="button" class="modal-cerrar" id="modal-compras-cerrar">&times;</button>
        </div>
        <div class="modal-body" id="modal-compras-body">
            <p style="color:#888">Cargando...</p>
        </div>
    </div>
</div>

<!-- =====================
     MODAL DETALLE DE VENTA
     ===================== -->
<div class="modal-overlay" id="modal-detalle-venta">
    <div class="modal-dialog modal-dialog--lg">
        <div class="modal-header">
            <h2 id="modal-detalle-titulo">Detalle de venta</h2>
            <button type="button" class="modal-cerrar" id="modal-detalle-cerrar">&times;</button>
        </div>
        <div class="modal-body" id="modal-detalle-body">
            <p style="color:#888">Cargando...</p>
        </div>
    </div>
</div>

<!-- =====================
     MODAL REGISTRAR PAGO
     ===================== -->
<div class="modal-overlay" id="modal-pago">
    <div class="modal-dialog">
        <div class="modal-header">
            <h2>Registrar pago</h2>
            <button type="button" class="modal-cerrar" id="modal-pago-cerrar">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="pago-id-venta">
            <input type="hidden" id="pago-id-cliente">
            <div class="modal-grid">
                <div class="modal-field modal-field--wide">
                    <label>Saldo pendiente</label>
                    <input type="text" id="pago-saldo" disabled>
                </div>
                <div class="modal-field modal-field--wide">
                    <label>Monto a pagar *</label>
                    <input type="number" id="pago-monto" min="0.01" step="0.01" placeholder="0,00">
                </div>
                <div class="modal-field modal-field--wide">
                    <label>Medio de pago *</label>
                    <select id="pago-medio">
                        <option value="EFECTIVO">Efectivo</option>
                        <option value="TARJETA">Tarjeta</option>
                        <option value="TRANSFERENCIA">Transferencia</option>
                        <option value="QR">QR</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-link" id="modal-pago-cancelar">Cancelar</button>
            <button type="button" class="btn-primary" id="pago-guardar">Confirmar pago</button>
        </div>
    </div>
</div>

<script>
function formatoPrecio(n) {
    return '$' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// =====================
// Modal cliente (crear/editar)
// =====================
const modalCliente = document.getElementById('modal-cliente');

function abrirModalCliente(titulo, id = '', nombre = '', cuit = '', telefono = '', email = '') {
    document.getElementById('modal-cliente-titulo').textContent = titulo;
    document.getElementById('cli-id').value       = id;
    document.getElementById('cli-nombre').value   = nombre;
    document.getElementById('cli-cuit').value     = cuit;
    document.getElementById('cli-telefono').value = telefono;
    document.getElementById('cli-email').value    = email;
    modalCliente.classList.add('modal-overlay--visible');
    document.body.classList.add('modal-abierto');
}

function cerrarModalCliente() {
    modalCliente.classList.remove('modal-overlay--visible');
    document.body.classList.remove('modal-abierto');
}

document.getElementById('btn-nuevo-cliente').addEventListener('click', () => abrirModalCliente('Nuevo cliente'));
document.getElementById('modal-cliente-cerrar').addEventListener('click', cerrarModalCliente);
document.getElementById('modal-cliente-cancelar').addEventListener('click', cerrarModalCliente);
modalCliente.addEventListener('click', e => { if (e.target === modalCliente) cerrarModalCliente(); });

document.querySelectorAll('.btn-editar-cliente').forEach(btn => {
    btn.addEventListener('click', () => abrirModalCliente(
        'Editar cliente',
        btn.dataset.id, btn.dataset.nombre, btn.dataset.cuit,
        btn.dataset.telefono, btn.dataset.email
    ));
});

document.getElementById('cli-guardar').addEventListener('click', () => {
    const id       = document.getElementById('cli-id').value;
    const nombre   = document.getElementById('cli-nombre').value.trim();
    const cuit     = document.getElementById('cli-cuit').value.trim();
    const telefono = document.getElementById('cli-telefono').value.trim();
    const email    = document.getElementById('cli-email').value.trim();

    if (!nombre) { alert('El nombre es obligatorio.'); return; }

    fetch('/TYPSISTEMA/app/controllers/base_datos/clientes/guardar.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, nombre, cuit, telefono, email })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { window.location.reload(); }
        else { alert('Error: ' + data.error); }
    });
});

// =====================
// Modal compras del cliente
// =====================
const modalCompras = document.getElementById('modal-compras');

function cerrarModalCompras() {
    modalCompras.classList.remove('modal-overlay--visible');
    document.body.classList.remove('modal-abierto');
}

document.getElementById('modal-compras-cerrar').addEventListener('click', cerrarModalCompras);
modalCompras.addEventListener('click', e => { if (e.target === modalCompras) cerrarModalCompras(); });

document.querySelectorAll('.btn-ver-compras').forEach(btn => {
    btn.addEventListener('click', e => {
        e.preventDefault();
        const idCliente = btn.dataset.id;
        const nombre    = btn.dataset.nombre;

        document.getElementById('modal-compras-titulo').textContent = 'Compras — ' + nombre;
        const body = document.getElementById('modal-compras-body');
        body.innerHTML = '<p style="color:#888">Cargando...</p>';

        modalCompras.classList.add('modal-overlay--visible');
        document.body.classList.add('modal-abierto');

        cargarCompras(idCliente);
    });
});

function cargarCompras(idCliente) {
    fetch('/TYPSISTEMA/app/controllers/base_datos/clientes/compras.php?id=' + idCliente)
        .then(r => r.json())
        .then(data => {
            const body = document.getElementById('modal-compras-body');
            if (!data.success) { body.innerHTML = '<p style="color:red">Error: ' + data.error + '</p>'; return; }
            renderCompras(body, data.ventas, idCliente);
        });
}

function renderCompras(body, ventas, idCliente) {
    if (!ventas || ventas.length === 0) {
        body.innerHTML = '<p style="color:#888">Este cliente no tiene compras registradas.</p>';
        return;
    }

    let html = '<table class="cli-tabla"><thead><tr><th>Fecha</th><th>Total</th><th>Cobrado</th><th>Saldo</th><th>Estado</th><th></th></tr></thead><tbody>';

    ventas.forEach(v => {
        const esPendiente = v.estado_pago === 'PENDIENTE' || v.estado_pago === 'PARCIAL';
        const saldo = parseFloat(v.total) - parseFloat(v.total_cobrado);
        const btnPago = esPendiente
            ? `<button type="button" class="btn-accion btn-editar btn-registrar-pago" data-id="${v.id}" data-saldo="${saldo.toFixed(2)}" data-cliente="${idCliente}">Registrar pago</button>`
            : '';
        html += `<tr class="${esPendiente ? 'fila-pendiente' : ''}">
            <td>${v.fecha_hora}</td>
            <td class="col-monto">${formatoPrecio(v.total)}</td>
            <td class="col-monto">${formatoPrecio(v.total_cobrado)}</td>
            <td class="col-monto">${formatoPrecio(Math.max(saldo, 0))}</td>
            <td><span class="cli-badge cli-badge--${v.estado_pago.toLowerCase()}">${v.estado_pago}</span></td>
            <td class="col-acciones-doble">
                <button type="button" class="btn-accion btn-ver-detalle"
                    data-id="${v.id}"
                    data-cliente="${idCliente}"
                    data-total="${v.total}"
                    data-cobrado="${v.total_cobrado}"
                    data-estado="${v.estado_pago}">Ver</button>
                ${btnPago}
            </td>
        </tr>`;
    });

    html += '</tbody></table>';
    body.innerHTML = html;

    body.querySelectorAll('.btn-registrar-pago').forEach(btn => {
        btn.addEventListener('click', () => abrirModalPago(btn.dataset.id, btn.dataset.saldo, btn.dataset.cliente));
    });

    body.querySelectorAll('.btn-ver-detalle').forEach(btn => {
        btn.addEventListener('click', () => abrirModalDetalle(
            btn.dataset.id, btn.dataset.cliente,
            btn.dataset.total, btn.dataset.cobrado, btn.dataset.estado
        ));
    });
}

// =====================
// Modal registrar pago
// =====================
const modalPago = document.getElementById('modal-pago');

function abrirModalPago(idVenta, saldo, idCliente) {
    document.getElementById('pago-id-venta').value   = idVenta;
    document.getElementById('pago-id-cliente').value = idCliente;
    document.getElementById('pago-saldo').value      = formatoPrecio(saldo);
    document.getElementById('pago-monto').value      = '';
    document.getElementById('pago-monto').max        = saldo;
    modalPago.classList.add('modal-overlay--visible');
}

function cerrarModalPago() {
    modalPago.classList.remove('modal-overlay--visible');
}

document.getElementById('modal-pago-cerrar').addEventListener('click', cerrarModalPago);
document.getElementById('modal-pago-cancelar').addEventListener('click', cerrarModalPago);
modalPago.addEventListener('click', e => { if (e.target === modalPago) cerrarModalPago(); });

// =====================
// Modal detalle de venta
// =====================
const modalDetalle = document.getElementById('modal-detalle-venta');

function abrirModalDetalle(idVenta, idCliente, total, cobrado, estado) {
    document.getElementById('modal-detalle-titulo').textContent = 'Detalle venta #' + idVenta;
    const body = document.getElementById('modal-detalle-body');
    body.innerHTML = '<p style="color:#888">Cargando...</p>';
    modalDetalle.classList.add('modal-overlay--visible');

    fetch(`/TYPSISTEMA/app/controllers/base_datos/clientes/detalle_venta.php?id_venta=${idVenta}&id_cliente=${idCliente}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) { body.innerHTML = '<p style="color:red">Error: ' + data.error + '</p>'; return; }
            if (!data.items || data.items.length === 0) {
                body.innerHTML = '<p style="color:#888">Sin ítems registrados.</p>';
                return;
            }

            const totalNum   = parseFloat(total)   || 0;
            const cobradoNum = parseFloat(cobrado)  || 0;
            const saldoNum   = Math.max(totalNum - cobradoNum, 0);

            let html = '<table class="cli-tabla"><thead><tr><th>Producto</th><th>Variante</th><th>Cant.</th><th>Precio unit.</th><th>Desc. %</th><th>Subtotal</th></tr></thead><tbody>';
            data.items.forEach(it => {
                html += `<tr>
                    <td>${it.producto}</td>
                    <td>${it.variante}</td>
                    <td style="text-align:center">${it.cantidad}</td>
                    <td class="col-monto">${formatoPrecio(it.precio_unitario)}</td>
                    <td style="text-align:center">${parseFloat(it.descuento) > 0 ? parseFloat(it.descuento).toFixed(1) + '%' : '-'}</td>
                    <td class="col-monto">${formatoPrecio(it.subtotal)}</td>
                </tr>`;
            });
            html += '</tbody></table>';

            html += `<div class="detalle-resumen">
                <div class="detalle-resumen-row"><span>Total</span><span>${formatoPrecio(totalNum)}</span></div>
                <div class="detalle-resumen-row"><span>Abonado</span><span>${formatoPrecio(cobradoNum)}</span></div>
                <div class="detalle-resumen-row detalle-resumen-saldo ${saldoNum > 0 ? 'saldo-pendiente' : ''}">
                    <span>Saldo pendiente</span><span>${formatoPrecio(saldoNum)}</span>
                </div>
            </div>`;

            body.innerHTML = html;
        });
}

function cerrarModalDetalle() {
    modalDetalle.classList.remove('modal-overlay--visible');
}

document.getElementById('modal-detalle-cerrar').addEventListener('click', cerrarModalDetalle);
modalDetalle.addEventListener('click', e => { if (e.target === modalDetalle) cerrarModalDetalle(); });

document.getElementById('pago-guardar').addEventListener('click', () => {
    const idVenta   = document.getElementById('pago-id-venta').value;
    const idCliente = document.getElementById('pago-id-cliente').value;
    const monto     = parseFloat(document.getElementById('pago-monto').value) || 0;
    const medio     = document.getElementById('pago-medio').value;
    const maxSaldo  = parseFloat(document.getElementById('pago-monto').max);

    if (monto <= 0)        { alert('Ingresá un monto mayor a 0.'); return; }
    if (monto > maxSaldo)  { alert('El monto no puede superar el saldo pendiente.'); return; }

    fetch('/TYPSISTEMA/app/controllers/base_datos/clientes/pago.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id_venta: idVenta, monto, medio_pago: medio, id_cliente: idCliente })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            cerrarModalPago();
            cargarCompras(idCliente);
            // Actualizar saldo en la tabla principal
            window.location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
});
</script>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';
