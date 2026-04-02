<?php
// app/views/base_datos/lista_precios.php

$titulo   = "Lista de precios";
$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/lista_precios.css">';

require_once __DIR__ . '/../../config/seguridad.php';
require_once __DIR__ . '/../../config/database.php';

$db   = new Database();
$conn = $db->getConnection();

$categorias  = $conn->query("SELECT id, nombre FROM categoria ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
$proveedores = $conn->query("SELECT id, nombre FROM proveedor ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

// Filtros
$q      = trim($_GET['q'] ?? '');
$idCat  = (int)($_GET['categoria'] ?? 0);
$idProv = (int)($_GET['proveedor'] ?? 0);

$conds  = ['p.activo = 1'];
$params = [];
$types  = '';

if ($idCat > 0) {
    $conds[] = "p.id_categoria = ?";
    $params[] = $idCat;
    $types .= 'i';
}

if ($idProv > 0) {
    $conds[] = "p.id_proveedor = ?";
    $params[] = $idProv;
    $types .= 'i';
}

if ($q !== '') {
    $palabras = array_filter(explode(' ', $q));
    foreach ($palabras as $palabra) {
        $conds[] = "p.nombre LIKE CONCAT('%',?,'%')";
        $params[] = $palabra;
        $types .= 's';
    }
}

$where = 'WHERE ' . implode(' AND ', $conds);

$sql = "
    SELECT
        p.id,
        p.nombre,
        MAX(CASE WHEN lp.tipo_lista = 'MINORISTA' THEN lp.id   END) AS id_lp_minorista,
        MAX(CASE WHEN lp.tipo_lista = 'MINORISTA' THEN lp.precio END) AS precio_minorista,
        MAX(CASE WHEN lp.tipo_lista = 'MAYORISTA' THEN lp.id   END) AS id_lp_mayorista,
        MAX(CASE WHEN lp.tipo_lista = 'MAYORISTA' THEN lp.precio END) AS precio_mayorista
    FROM productos p
    LEFT JOIN lista_precio lp ON lp.id_producto = p.id
    $where
    GROUP BY p.id, p.nombre
    ORDER BY p.nombre ASC
";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$productos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

ob_start();
?>

<div class="lp-container">

    <div class="lp-header">
        <h1 class="lp-titulo">Lista de precios</h1>
    </div>

    <!-- Filtros -->
    <form method="get" class="lp-filtros">
        <div class="lp-field">
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar por nombre...">
        </div>
        <div class="lp-field">
            <select name="categoria">
                <option value="0">Todas las categorías</option>
                <?php foreach ($categorias as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $idCat === (int)$cat['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="lp-field">
            <select name="proveedor">
                <option value="0">Todos los proveedores</option>
                <?php foreach ($proveedores as $prov): ?>
                    <option value="<?= $prov['id'] ?>" <?= $idProv === (int)$prov['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($prov['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-primary">Filtrar</button>
        <a href="/TYPSISTEMA/app/views/base_datos/lista_precios.php" class="btn-link">Limpiar</a>
    </form>

    <!-- Aumento masivo -->
    <div class="lp-card">
        <p class="lp-card-titulo">Aumento masivo</p>
        <div class="lp-aumento-form">
            <div class="lp-field">
                <label>Aplicar a</label>
                <select id="aum-lista">
                    <option value="ambos">Ambas listas</option>
                    <option value="MINORISTA">Solo minorista</option>
                    <option value="MAYORISTA">Solo mayorista</option>
                </select>
            </div>
            <div class="lp-field">
                <label>Porcentaje de aumento</label>
                <div class="lp-pct-wrapper">
                    <input type="number" id="aum-pct" min="0" step="0.1" placeholder="Ej: 15">
                    <span>%</span>
                </div>
            </div>
            <button type="button" class="btn-primary" id="btn-aumento">Aplicar aumento</button>
        </div>
        <p class="lp-hint">Se aplica sobre los productos actualmente visibles en la tabla (respeta los filtros).</p>
    </div>

    <!-- Tabla -->
    <div class="lp-tabla-wrapper">
        <table class="lp-tabla" id="lp-tabla">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>Precio minorista</th>
                    <th>Precio mayorista</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($productos)): ?>
                    <tr><td colspan="4">No se encontraron productos.</td></tr>
                <?php else: ?>
                    <?php foreach ($productos as $p): ?>
                        <tr
                            data-id="<?= $p['id'] ?>"
                            data-id-min="<?= (int)$p['id_lp_minorista'] ?>"
                            data-id-may="<?= (int)$p['id_lp_mayorista'] ?>"
                        >
                            <td><?= htmlspecialchars($p['nombre']) ?></td>
                            <td>
                                <input
                                    type="number"
                                    class="lp-input lp-input-min"
                                    min="0"
                                    step="0.01"
                                    value="<?= number_format($p['precio_minorista'] ?? 0, 2, '.', '') ?>"
                                >
                            </td>
                            <td>
                                <input
                                    type="number"
                                    class="lp-input lp-input-may"
                                    min="0"
                                    step="0.01"
                                    value="<?= number_format($p['precio_mayorista'] ?? 0, 2, '.', '') ?>"
                                >
                            </td>
                            <td>
                                <button type="button" class="btn-accion btn-editar btn-guardar-precio">Guardar</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<script>
// =====================
// Guardar precio individual
// =====================
document.querySelectorAll('.btn-guardar-precio').forEach(btn => {
    btn.addEventListener('click', () => {
        const fila      = btn.closest('tr');
        const idProd    = fila.dataset.id;
        const idMin     = fila.dataset.idMin;
        const idMay     = fila.dataset.idMay;
        const minorista = parseFloat(fila.querySelector('.lp-input-min').value) || 0;
        const mayorista = parseFloat(fila.querySelector('.lp-input-may').value) || 0;

        btn.textContent = '...';
        btn.disabled    = true;

        fetch('/TYPSISTEMA/app/controllers/base_datos/lista_precios/guardar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_producto: idProd, id_min: idMin, id_may: idMay, minorista, mayorista })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                btn.textContent = '✓';
                setTimeout(() => { btn.textContent = 'Guardar'; btn.disabled = false; }, 1200);
            } else {
                alert('Error: ' + data.error);
                btn.textContent = 'Guardar';
                btn.disabled    = false;
            }
        });
    });
});

// =====================
// Aumento masivo
// =====================
document.getElementById('btn-aumento').addEventListener('click', () => {
    const pct   = parseFloat(document.getElementById('aum-pct').value) || 0;
    const lista = document.getElementById('aum-lista').value;

    if (pct <= 0) { alert('Ingresá un porcentaje mayor a 0.'); return; }
    if (!confirm(`¿Aplicar un aumento del ${pct}% a ${lista === 'ambos' ? 'ambas listas' : 'lista ' + lista}?`)) return;

    const factor = 1 + pct / 100;

    document.querySelectorAll('#lp-tabla tbody tr[data-id]').forEach(fila => {
        const inputMin = fila.querySelector('.lp-input-min');
        const inputMay = fila.querySelector('.lp-input-may');

        if (lista === 'MINORISTA' || lista === 'ambos') {
            inputMin.value = (parseFloat(inputMin.value) * factor).toFixed(2);
        }
        if (lista === 'MAYORISTA' || lista === 'ambos') {
            inputMay.value = (parseFloat(inputMay.value) * factor).toFixed(2);
        }
    });

    // Guardar todos
    const promesas = [];
    document.querySelectorAll('#lp-tabla tbody tr[data-id]').forEach(fila => {
        const idProd    = fila.dataset.id;
        const idMin     = fila.dataset.idMin;
        const idMay     = fila.dataset.idMay;
        const minorista = parseFloat(fila.querySelector('.lp-input-min').value) || 0;
        const mayorista = parseFloat(fila.querySelector('.lp-input-may').value) || 0;

        promesas.push(
            fetch('/TYPSISTEMA/app/controllers/base_datos/lista_precios/guardar.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_producto: idProd, id_min: idMin, id_may: idMay, minorista, mayorista })
            }).then(r => r.json())
        );
    });

    Promise.all(promesas).then(resultados => {
        const errores = resultados.filter(r => !r.success);
        if (errores.length === 0) {
            alert('Aumento aplicado correctamente.');
        } else {
            alert(`Se aplicó el aumento pero ${errores.length} producto(s) tuvieron error.`);
        }
    });
});
</script>

<?php
$contenido = ob_get_clean();
require __DIR__ . '/../layouts/main.php';
