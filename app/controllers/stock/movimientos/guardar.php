<?php
// app/controllers/stock/movimientos/guardar.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

// Ajustá si tu raíz cambia
$BASE = "/TYPSISTEMA";

function redirect($url) {
    header("Location: " . $url);
    exit;
}

function fail($msg) {
    global $BASE;
    $msg = urlencode($msg);
    redirect($BASE . "/app/views/stock/stock_movimientos/index.php?err={$msg}");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail("Método inválido.");
}

$tipo         = isset($_POST['tipo']) ? trim($_POST['tipo']) : '';
$id_variante  = isset($_POST['id_variante']) ? (int)$_POST['id_variante'] : 0;
<?php
// app/controllers/stock/movimientos/guardar.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../../config/seguridad.php';
require_once __DIR__ . '/../../../config/database.php';

// Ajustá si tu raíz cambia
$BASE = "/TYPSISTEMA";

function redirect_to($url) {
    header("Location: " . $url);
    exit;
}

function fail($msg) {
    global $BASE;
    redirect_to($BASE . "/app/views/stock/stock_movimientos/index.php?err=" . urlencode($msg));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail("Método inválido.");
}

// --------------------
// Leer POST
// --------------------
$tipo          = isset($_POST['tipo']) ? trim($_POST['tipo']) : '';
$id_variante   = isset($_POST['id_variante']) ? (int)$_POST['id_variante'] : 0;
$cantidad      = isset($_POST['cantidad']) ? (int)$_POST['cantidad'] : 0;
$observaciones = isset($_POST['observaciones']) ? trim($_POST['observaciones']) : '';
$fecha         = isset($_POST['fecha']) ? trim($_POST['fecha']) : '';

// --------------------
// Validaciones
// --------------------
$tiposPermitidos = ['INGRESO', 'AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO'];

if (!in_array($tipo, $tiposPermitidos, true)) {
    fail("Tipo inválido.");
}

if ($id_variante <= 0) {
    fail("Variante inválida.");
}

if ($cantidad <= 0) {
    fail("Cantidad inválida.");
}

// Fecha YYYY-MM-DD (opcional)
if ($fecha !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    fail("Fecha inválida.");
}

// Usar fecha del formulario + hora actual, o ahora si no vino fecha
$fecha_hora = ($fecha !== '')
    ? ($fecha . ' ' . date('H:i:s'))
    : date('Y-m-d H:i:s');

// --------------------
// DB
// --------------------
$db   = new Database();
$conn = $db->getConnection();

try {
    $conn->begin_transaction();

    // 1) Lock del stock actual
    $stmtSel = $conn->prepare("SELECT stock_actual FROM producto_variante WHERE id = ? FOR UPDATE");
    if (!$stmtSel) {
        throw new Exception("Error preparando SELECT: " . $conn->error);
    }

    $stmtSel->bind_param('i', $id_variante);
    $stmtSel->execute();
    $resSel = $stmtSel->get_result();

    if ($resSel->num_rows === 0) {
        throw new Exception("La variante no existe.");
    }

    $stockActual = (int)$resSel->fetch_assoc()['stock_actual'];

    // 2) Delta
    $delta = ($tipo === 'AJUSTE_NEGATIVO') ? -$cantidad : $cantidad;

    if ($delta < 0 && ($stockActual + $delta) < 0) {
        throw new Exception("Stock insuficiente. Stock actual: {$stockActual}.");
    }

    // 3) Insert movimiento (id_venta NULL)
    $stmtIns = $conn->prepare("
        INSERT INTO movimiento_stock (fecha_hora, id_variante, tipo, cantidad, id_venta, observaciones)
        VALUES (?, ?, ?, ?, NULL, ?)
    ");
    if (!$stmtIns) {
        throw new Exception("Error preparando INSERT: " . $conn->error);
    }

    // s i s i s
    $stmtIns->bind_param('sisis', $fecha_hora, $id_variante, $tipo, $cantidad, $observaciones);
    $stmtIns->execute();

    // 4) Update stock
    $stmtUp = $conn->prepare("UPDATE producto_variante SET stock_actual = stock_actual + ? WHERE id = ?");
    if (!$stmtUp) {
        throw new Exception("Error preparando UPDATE: " . $conn->error);
    }

    $stmtUp->bind_param('ii', $delta, $id_variante);
    $stmtUp->execute();

    $conn->commit();

    redirect_to($BASE . "/app/views/stock/stock_movimientos/index.php?ok=1");
} catch (Exception $e) {
    $conn->rollback();
    fail($e->getMessage());
}