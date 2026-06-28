<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

auth_start_session();

if (auth_check()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (auth_attempt($pdo, $username, $password)) {
        header('Location: index.php');
        exit;
    }

    $error = 'Usuario o contraseña incorrectos.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Iniciar sesión — IO</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/css/app.css">
  <style>
    .login-card { max-width: 380px; margin: 100px auto; }
    .login-card-header {
      background-color: var(--io-navy);
      color: #ffffff;
      border-radius: 6px 6px 0 0;
      padding: 1rem 1.25rem;
      font-weight: 600;
      font-size: 1.1rem;
    }
  </style>
</head>
<body>
  <div class="login-card">
    <div class="login-card-header">IO — Hotel Check-In</div>
    <div class="card shadow-sm border-top-0 rounded-top-0 p-4">

      <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" action="login.php">
        <div class="mb-3">
          <label for="username" class="form-label">Usuario</label>
          <input type="text" id="username" name="username" class="form-control"
                 autocomplete="username" required autofocus>
        </div>
        <div class="mb-3">
          <label for="password" class="form-label">Contraseña</label>
          <input type="password" id="password" name="password" class="form-control"
                 autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-io-blue w-100">
          Iniciar sesion
        </button>
      </form>

    </div>
  </div>
</body>
</html>
