<?php
// app/views/caja/movimiento.php

$titulo   = "Ingreso / Egreso de caja";
$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/caja.css">';

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$db   = new Database();
$conn = $db->getConnection();

// ¿Hay caja abierta?
$resAbierta = $conn->query("SELECT id FROM caja WHERE estado = 'ABIERTA' ORDER BY id DESC LIMIT 1");
$cajaAbierta = $resAbierta->num_rows > 0;
$idCaja = $cajaAbierta ? (int)$resAbierta->fetch_assoc()['id'] : null;

// Todos los movimientos manuales
$movimientos = $conn->query("
    SELECT mc.fecha_hora, mc.tipo, mc.medio_pago, mc.monto, mc.referencia, u.nombre AS usuario
    FROM movimiento_caja mc
    INNER JOIN usuarios u ON u.id = mc.id_usuario
    WHERE mc.tipo IN ('INGRESO', 'EGRESO')
    ORDER BY mc.fecha_hora DESC
")->fetch_all(MYSQLI_ASSOC);

$BASE = "/TYPSISTEMA";

ob_start();
?>

<div class="caja-container">
    <h1 class="caja-titulo">Ingreso / Egreso de caja</h1>

    <?php if (!$cajaAbierta): ?>

        <div class="caja-aviso caja-aviso--alerta">
            No hay ninguna caja abierta. Abrí una caja antes de registrar movimientos.
        </div>
        <div class="caja-acciones">
            <a href="<?= $BASE ?>/app/views/caja/apertura.php" class="btn-primary">Ir a apertura</a>
        </div>

    <?php else: ?>

        <div class="caja-card">
            <div class="mov-caja-form">

                <div class="caja-field">
                    <label>Tipo</label>
                    <select id="mc-tipo">
                        <option value="INGRESO">Ingreso</option>
                        <option value="EGRESO">Egreso</option>
                    </select>
                </div>

                <div class="caja-field">
                    <label>Medio de pago</label>
                    <select id="mc-medio">
                        <option value="EFECTIVO">Efectivo</option>
                        <option value="TARJETA">Tarjeta</option>
                        <option value="TRANSFERENCIA">Transferencia</option>
                        <option value="QR">QR</option>
                    </select>
                </div>

                <div class="caja-field">
                    <label>Monto</label>
                    <input type="text" id="mc-monto" placeholder="0,00" class="caja-input-real">
                </div>

                <div class="caja-field caja-field--ref">
                    <label>Referencia / Descripción</label>
                    <input type="text" id="mc-referencia" placeholder="Ej: pago a proveedor, retiro de efectivo...">
                </div>

            </div>
        </div>

        <div class="caja-acciones">
            <a href="<?= $BASE ?>/app/views/dashboard/index.php" class="btn-link">Cancelar</a>
            <button type="button" class="btn-primary" id="mc-guardar">Guardar</button>
        </div>

        <script>
        const idCaja = <?= $idCaja ?>;

        // Formato monto
        function parsearMonto(valor) {
            return parseFloat(valor.replace(/\./g, '').replace(',', '.')) || 0;
        }

        function formatearMonto(numero) {
            return numero.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        const inputMonto = document.getElementById('mc-monto');

        inputMonto.addEventListener('focus', () => {
            const num = parsearMonto(inputMonto.value);
            inputMonto.value = num === 0 ? '' : String(num).replace('.', ',');
        });

        inputMonto.addEventListener('blur', () => {
            const num = parsearMonto(inputMonto.value);
            inputMonto.value = num === 0 ? '' : formatearMonto(num);
        });

        // Guardar
        document.getElementById('mc-guardar').addEventListener('click', () => {
            const tipo       = document.getElementById('mc-tipo').value;
            const medio      = document.getElementById('mc-medio').value;
            const monto      = parsearMonto(inputMonto.value);
            const referencia = document.getElementById('mc-referencia').value.trim();

            if (monto <= 0) {
                alert('Ingresá un monto mayor a 0.');
                return;
            }

            fetch('/TYPSISTEMA/app/controllers/caja/movimiento.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_caja: idCaja, tipo, medio_pago: medio, monto, referencia })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.href = '/TYPSISTEMA/app/views/caja/movimiento.php?ok=1';
                } else {
                    alert('Error: ' + data.error);
                }
            });
        });
        </script>

    <?php endif; ?>

    <?php if (isset($_GET['ok'])): ?>
        <div class="caja-aviso caja-aviso--ok">Movimiento registrado correctamente.</div>
    <?php endif; ?>

    <!-- Historial -->
    <div class="caja-card">
        <p class="caja-subtitulo">Historial de movimientos</p>
        <table class="caja-tabla">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th>Medio</th>
                    <th>Monto</th>
                    <th>Referencia</th>
                    <th>Usuario</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($movimientos)): ?>
                    <tr><td colspan="6">Sin movimientos registrados.</td></tr>
                <?php else: ?>
                    <?php foreach ($movimientos as $m): ?>
                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($m['fecha_hora'])) ?></td>
                            <td class="<?= $m['tipo'] === 'INGRESO' ? 'diferencia-positiva' : 'diferencia-negativa' ?>">
                                <?= $m['tipo'] ?>
                            </td>
                            <td><?= ucfirst(strtolower($m['medio_pago'])) ?></td>
                            <td style="text-align:right">$<?= number_format($m['monto'], 2, ',', '.') ?></td>
                            <td><?= htmlspecialchars($m['referencia'] ?? '') ?></td>
                            <td><?= htmlspecialchars($m['usuario']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';
