<?php
// app/views/caja/cierre.php

$titulo   = "Cierre de caja";
$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/caja.css">';

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$db   = new Database();
$conn = $db->getConnection();

// ¿Hay caja abierta?
$resAbierta = $conn->query("SELECT id, fecha, saldo_inicial FROM caja WHERE estado = 'ABIERTA' ORDER BY id DESC LIMIT 1");
$cajaAbierta = $resAbierta->num_rows > 0;
$caja = $cajaAbierta ? $resAbierta->fetch_assoc() : null;

$medios = ['EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'QR'];
$totales = [];

if ($cajaAbierta) {
    $idCaja = (int)$caja['id'];

    // Totales por medio y tipo desde movimiento_caja
    $sqlMov = "
        SELECT
            medio_pago,
            tipo,
            COALESCE(SUM(monto), 0) AS total
        FROM movimiento_caja
        WHERE id_caja = ?
        GROUP BY medio_pago, tipo
    ";
    $stmt = $conn->prepare($sqlMov);
    $stmt->bind_param('i', $idCaja);
    $stmt->execute();
    $res = $stmt->get_result();

    // Organizar en array [medio][tipo] = total
    $movs = [];
    while ($row = $res->fetch_assoc()) {
        $movs[$row['medio_pago']][$row['tipo']] = (float)$row['total'];
    }
    $stmt->close();

    // Calcular total esperado por medio
    foreach ($medios as $medio) {
        $ventas   = $movs[$medio]['VENTA']    ?? 0;
        $ingresos = $movs[$medio]['INGRESO']  ?? 0;
        $egresos  = $movs[$medio]['EGRESO']   ?? 0;

        $saldoInicial = ($medio === 'EFECTIVO') ? (float)$caja['saldo_inicial'] : 0;

        $totales[$medio] = [
            'saldo_inicial' => $saldoInicial,
            'ventas'        => $ventas,
            'ingresos'      => $ingresos,
            'egresos'       => $egresos,
            'esperado'      => $saldoInicial + $ventas + $ingresos - $egresos,
        ];
    }
}

$BASE = "/TYPSISTEMA";

ob_start();
?>

<div class="caja-container" style="max-width:860px">
    <h1 class="caja-titulo">Cierre de caja</h1>

    <?php if (!$cajaAbierta): ?>

        <div class="caja-aviso caja-aviso--alerta">
            No hay ninguna caja abierta.
        </div>
        <div class="caja-acciones">
            <a href="<?= $BASE ?>/app/views/caja/apertura.php" class="btn-primary">Ir a apertura</a>
        </div>

    <?php else: ?>

        <div class="caja-card">
            <p class="caja-subtitulo">
                Caja del <?= date('d/m/Y', strtotime($caja['fecha'])) ?>
            </p>

            <table class="caja-tabla caja-tabla--cierre">
                <thead>
                    <tr>
                        <th>Medio</th>
                        <th>Saldo inicial</th>
                        <th>Ventas</th>
                        <th>Ingresos</th>
                        <th>Egresos</th>
                        <th>Total esperado</th>
                        <th>Total real</th>
                        <th>Diferencia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($medios as $medio): ?>
                        <?php $t = $totales[$medio]; ?>
                        <tr data-medio="<?= $medio ?>" data-esperado="<?= $t['esperado'] ?>">
                            <td><?= ucfirst(strtolower($medio)) ?></td>
                            <td>$<?= number_format($t['saldo_inicial'], 2, ',', '.') ?></td>
                            <td>$<?= number_format($t['ventas'],        2, ',', '.') ?></td>
                            <td>$<?= number_format($t['ingresos'],      2, ',', '.') ?></td>
                            <td>$<?= number_format($t['egresos'],       2, ',', '.') ?></td>
                            <td>$<?= number_format($t['esperado'],      2, ',', '.') ?></td>
                            <td>
                                <input
                                    type="text"
                                    class="caja-input-real"
                                    value="<?= number_format($t['esperado'], 2, ',', '') ?>"
                                    data-medio="<?= $medio ?>"
                                >
                            </td>
                            <td class="celda-diferencia" id="dif-<?= $medio ?>">$0,00</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="caja-field">
                <label>Observaciones (opcional)</label>
                <textarea id="caja-obs" rows="2" placeholder="Ej: cierre normal..."></textarea>
            </div>
        </div>

        <div class="caja-acciones">
            <a href="<?= $BASE ?>/app/views/dashboard/index.php" class="btn-link">Cancelar</a>
            <button type="button" class="btn-primary" id="btn-cerrar">Confirmar cierre</button>
        </div>

        <script>
        const idCaja = <?= $idCaja ?>;

        function formatoPrecio(val) {
            return '$' + Number(val).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function parsearMonto(valor) {
            // Elimina puntos de miles y reemplaza coma decimal por punto
            return parseFloat(valor.replace(/\./g, '').replace(',', '.')) || 0;
        }

        function formatearMonto(numero) {
            return numero.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function actualizarDiferencia(input) {
            const medio    = input.dataset.medio;
            const esperado = parseFloat(input.closest('tr').dataset.esperado) || 0;
            const real     = parsearMonto(input.value);
            const dif      = real - esperado;

            const celda = document.getElementById('dif-' + medio);
            celda.textContent = formatoPrecio(dif);
            celda.className = 'celda-diferencia ' + (dif > 0 ? 'diferencia-positiva' : dif < 0 ? 'diferencia-negativa' : 'diferencia-cero');
        }

        // Inicializar diferencias y eventos de formato
        document.querySelectorAll('.caja-input-real').forEach(input => {
            actualizarDiferencia(input);

            // Al hacer foco: mostrar solo el número sin formato para editar fácil
            input.addEventListener('focus', () => {
                const num = parsearMonto(input.value);
                input.value = num === 0 ? '' : String(num).replace('.', ',');
            });

            // Al salir: formatear con miles y decimales
            input.addEventListener('blur', () => {
                const num = parsearMonto(input.value);
                input.value = formatearMonto(num);
                actualizarDiferencia(input);
            });

            input.addEventListener('input', () => actualizarDiferencia(input));
        });

        // Confirmar cierre
        document.getElementById('btn-cerrar').addEventListener('click', () => {
            if (!confirm('¿Confirmar cierre de caja?')) return;

            const detalle = [];
            document.querySelectorAll('.caja-input-real').forEach(input => {
                const fila     = input.closest('tr');
                const esperado = parseFloat(fila.dataset.esperado) || 0;
                const real     = parsearMonto(input.value);
                detalle.push({
                    medio_pago:     input.dataset.medio,
                    total_esperado: esperado,
                    total_real:     real,
                    diferencia:     real - esperado
                });
            });

            fetch('/TYPSISTEMA/app/controllers/caja/cerrar.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id_caja:       idCaja,
                    observaciones: document.getElementById('caja-obs').value,
                    detalle
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.href = '/TYPSISTEMA/app/views/caja/historico.php';
                } else {
                    alert('Error: ' + data.error);
                }
            });
        });
        </script>

    <?php endif; ?>
</div>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';
