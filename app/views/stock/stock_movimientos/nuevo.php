<?php
// app/views/stock/stock_movimientos/nuevo.php

$titulo = "Nuevo movimiento de stock";
$css_extra = '<link rel="stylesheet" href="/TYPSISTEMA/public/css/stock_movimientos.css">';

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

$db   = new Database();
$conn = $db->getConnection();

$sql = "
    SELECT
        pv.id,
        p.nombre AS producto,
        pv.nombre_variante AS variante
    FROM producto_variante pv
    INNER JOIN productos p ON p.id = pv.id_producto
    WHERE pv.activo = 1
    ORDER BY p.nombre ASC, pv.nombre_variante ASC
";

$res = $conn->query($sql);
if (!$res) {
    die("Error cargando variantes: " . $conn->error);
}

$BASE = "/TYPSISTEMA";

function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

$hoy = date('Y-m-d');

// ---------------------------
// Render con layout
// ---------------------------
ob_start();
?>

<h1>Nuevo movimiento de stock</h1>

<p>
  <a href="<?= $BASE ?>/app/views/stock/stock_movimientos/index.php">Volver</a>
</p>

<form method="post" action="<?= $BASE ?>/app/controllers/stock/movimientos/guardar.php">
  <label>Tipo:</label>
  <select name="tipo" required>
    <option value="INGRESO">Ingreso</option>
    <option value="AJUSTE_POSITIVO">Ajuste +</option>
    <option value="AJUSTE_NEGATIVO">Ajuste -</option>
  </select>

  <br><br>

  <label>Fecha:</label>
  <input type="date" name="fecha" value="<?= h($hoy) ?>">

  <br><br>

 <label>Buscar producto / variante:</label>
<input type="text" id="buscarVariante" placeholder="Escribí para filtrar..." autocomplete="off">

<br><br>

<label>Producto / Variante:</label>
<select name="id_variante" id="selectVariante" required>
  <option value="">-- Seleccionar --</option>
  <?php while ($row = $res->fetch_assoc()): ?>
    <option value="<?= (int)$row['id'] ?>">
      <?= h($row['producto']) ?> — <?= h($row['variante']) ?>
    </option>
  <?php endwhile; ?>
</select>

  <br><br>

  <label>Cantidad:</label>
  <input type="number" name="cantidad" min="1" step="1" required>

  <br><br>

  <label>Observaciones (opcional):</label><br>
  <textarea name="observaciones" rows="4" cols="50" placeholder="Ej: devolución a proveedor / uso interno / corrección..."></textarea>

  <br><br>

  <button type="submit">Guardar</button>
</form>

<script>
  (function () {
    const input = document.getElementById('buscarVariante');
    const select = document.getElementById('selectVariante');

    // Guardamos todas las opciones originales (menos la primera "-- Seleccionar --")
    const allOptions = Array.from(select.options).slice(1).map(opt => ({
      value: opt.value,
      text: opt.text
    }));

    function renderOptions(list) {
      // Mantener la opción por defecto
      select.innerHTML = '<option value="">-- Seleccionar --</option>';
      for (const item of list) {
        const opt = document.createElement('option');
        opt.value = item.value;
        opt.textContent = item.text;
        select.appendChild(opt);
      }
    }

    input.addEventListener('input', function () {
      const q = input.value.trim().toLowerCase();

      if (q === '') {
        renderOptions(allOptions);
        return;
      }

      const filtered = allOptions.filter(item =>
        item.text.toLowerCase().includes(q)
      );

      renderOptions(filtered);
    });
  })();
</script>
<?php
$contenido = ob_get_clean();
require __DIR__ . '/../../layouts/main.php';