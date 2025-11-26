<?php
session_start();

require_once __DIR__ . '/../../config/database.php';

$mensaje = "";

// lógica igual que antes...
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre   = trim($_POST['nombre'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($nombre === '' || $password === '') {
        $mensaje = "Completa todos los campos.";
    } else {
        $db   = new Database();
        $conn = $db->getConnection();

        $sql = "SELECT * FROM usuarios WHERE nombre = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $nombre);
        $stmt->execute();
        $result = $stmt->get_result();
        $user   = $result->fetch_assoc();

        if (!$user) {
            $mensaje = "No se encontró un usuario con ese nombre.";
        } elseif (!password_verify($password, $user['password_hash'])) {
            $mensaje = "La contraseña es incorrecta.";
        } else {
            $_SESSION['usuario_id']     = $user['id'];
            $_SESSION['usuario_nombre'] = $user['nombre'];
            $_SESSION['usuario_rol']    = $user['rol'];

            header("Location: ../dashboard/index.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login</title>

    <!-- Ajustá la ruta según cómo entres al proyecto en el navegador -->
    <link rel="stylesheet" href="/TYPSISTEMA/public/css/login.css">
</head>
<body>

<div class="login-container">
    <h2>Iniciar sesión</h2>
    <p>Ingresá tus datos para acceder al sistema.</p>

    <?php if ($mensaje): ?>
        <div class="error-message"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label for="nombre">Nombre</label>
            <input type="text" name="nombre" id="nombre" required>
        </div>

        <div class="form-group">
            <label for="password">Contraseña</label>
            <input type="password" name="password" id="password" required>
        </div>

        <button type="submit">Ingresar</button>
    </form>
</div>

</body>
</html>