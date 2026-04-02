<?php
// app/views/config/usuarios.php

$titulo    = "Usuarios y permisos";
$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/usuarios.css">';

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

// Solo admin
if (($_SESSION['usuario_rol'] ?? '') !== 'ADMIN') {
    header('Location: /TYPSISTEMA/app/views/dashboard.php');
    exit;
}

$db   = new Database();
$conn = $db->getConnection();

$usuarios  = $conn->query("
    SELECT u.id, u.nombre, u.rol, u.created_at, d.nombre AS deposito
    FROM usuarios u
    LEFT JOIN deposito d ON d.id = u.id_deposito
    ORDER BY u.nombre ASC
")->fetch_all(MYSQLI_ASSOC);

$depositos = $conn->query("SELECT id, nombre FROM deposito ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

ob_start();
?>

<div class="usr-container">

    <div class="usr-header">
        <h1 class="usr-titulo">Usuarios y permisos</h1>
        <button type="button" class="btn-primary" id="btn-nuevo-usuario">+ Nuevo usuario</button>
    </div>

    <div class="usr-tabla-wrapper">
        <table class="usr-tabla">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Rol</th>
                    <th>Depósito</th>
                    <th>Creado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($usuarios as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars($u['nombre']) ?></td>
                        <td><span class="usr-badge usr-badge--<?= strtolower($u['rol']) ?>"><?= $u['rol'] ?></span></td>
                        <td><?= htmlspecialchars($u['deposito'] ?? '-') ?></td>
                        <td><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
                        <td class="col-acciones">
                            <button type="button" class="btn-accion btn-editar btn-editar-usuario"
                                data-id="<?= $u['id'] ?>"
                                data-nombre="<?= htmlspecialchars($u['nombre'], ENT_QUOTES) ?>"
                                data-rol="<?= $u['rol'] ?>"
                                data-deposito="<?= $u['deposito'] ?? '' ?>">
                                Editar
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL NUEVO / EDITAR USUARIO -->
<div class="modal-overlay" id="modal-usuario">
    <div class="modal-dialog">
        <div class="modal-header">
            <h2 id="modal-usr-titulo">Nuevo usuario</h2>
            <button type="button" class="modal-cerrar" id="modal-usr-cerrar">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="usr-id">
            <div class="modal-grid">
                <div class="modal-field modal-field--wide">
                    <label>Nombre *</label>
                    <input type="text" id="usr-nombre">
                </div>
                <div class="modal-field modal-field--wide">
                    <label>Contraseña <span id="usr-pass-hint">(dejar vacío para no cambiar)</span></label>
                    <input type="password" id="usr-password" placeholder="••••••••">
                </div>
                <div class="modal-field">
                    <label>Rol *</label>
                    <select id="usr-rol">
                        <option value="VENDEDOR">Vendedor</option>
                        <option value="ADMIN">Admin</option>
                    </select>
                </div>
                <div class="modal-field">
                    <label>Depósito *</label>
                    <select id="usr-deposito">
                        <?php foreach ($depositos as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-link" id="modal-usr-cancelar">Cancelar</button>
            <button type="button" class="btn-primary" id="usr-guardar">Guardar</button>
        </div>
    </div>
</div>

<script>
const depositos = <?= json_encode(array_column($depositos, 'id', 'nombre')) ?>;
const modalUsr  = document.getElementById('modal-usuario');

function abrirModalUsr(titulo, id = '', nombre = '', rol = 'VENDEDOR', deposito = '') {
    document.getElementById('modal-usr-titulo').textContent = titulo;
    document.getElementById('usr-id').value       = id;
    document.getElementById('usr-nombre').value   = nombre;
    document.getElementById('usr-password').value = '';
    document.getElementById('usr-rol').value      = rol;

    // hint contraseña
    document.getElementById('usr-pass-hint').style.display = id ? 'inline' : 'none';
    if (!id) document.getElementById('usr-password').placeholder = '••••••••';

    // depósito
    const selDep = document.getElementById('usr-deposito');
    if (deposito) {
        const idDep = depositos[deposito];
        if (idDep) selDep.value = idDep;
    }

    modalUsr.classList.add('modal-overlay--visible');
    document.body.classList.add('modal-abierto');
    setTimeout(() => document.getElementById('usr-nombre').focus(), 50);
}

function cerrarModalUsr() {
    modalUsr.classList.remove('modal-overlay--visible');
    document.body.classList.remove('modal-abierto');
}

document.getElementById('btn-nuevo-usuario').addEventListener('click', () => abrirModalUsr('Nuevo usuario'));
document.getElementById('modal-usr-cerrar').addEventListener('click', cerrarModalUsr);
document.getElementById('modal-usr-cancelar').addEventListener('click', cerrarModalUsr);
modalUsr.addEventListener('click', e => { if (e.target === modalUsr) cerrarModalUsr(); });

document.querySelectorAll('.btn-editar-usuario').forEach(btn => {
    btn.addEventListener('click', () => abrirModalUsr(
        'Editar usuario', btn.dataset.id, btn.dataset.nombre, btn.dataset.rol, btn.dataset.deposito
    ));
});

document.getElementById('usr-guardar').addEventListener('click', () => {
    const id         = document.getElementById('usr-id').value;
    const nombre     = document.getElementById('usr-nombre').value.trim();
    const password   = document.getElementById('usr-password').value;
    const rol        = document.getElementById('usr-rol').value;
    const id_deposito = document.getElementById('usr-deposito').value;

    if (!nombre) { alert('El nombre es obligatorio.'); return; }
    if (!id && !password) { alert('La contraseña es obligatoria para nuevos usuarios.'); return; }

    fetch('/TYPSISTEMA/app/controllers/config/usuarios/guardar.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, nombre, password, rol, id_deposito })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { window.location.reload(); }
        else { alert('Error: ' + data.error); }
    });
});
</script>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';
