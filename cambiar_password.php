<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
auth_require();
auth_start_session();

$errors  = [];
$success = false;

// Consume flash (from redirect-to-self after success)
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current  = $_POST['current_password']  ?? '';
    $nueva    = $_POST['nueva_password']    ?? '';
    $confirmar = $_POST['confirmar_password'] ?? '';

    if ($current === '')   $errors[] = 'La contrasena actual es obligatoria.';
    if ($nueva === '')     $errors[] = 'La nueva contrasena es obligatoria.';
    if ($nueva !== $confirmar) $errors[] = 'La nueva contrasena y la confirmacion no coinciden.';

    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($current, $user['password_hash'])) {
            $errors[] = 'La contrasena actual es incorrecta.';
        } else {
            $hash = password_hash($nueva, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                ->execute([$hash, (int)$_SESSION['user_id']]);

            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Contrasena actualizada correctamente.'];
            header('Location: cambiar_password.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cambiar contrasena — IO</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>

<?php $active_nav = 'cambiar-password'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container-lg py-4">

  <?php if ($flash):
    $flash_type  = is_array($flash) ? ($flash['type']    ?? 'success') : 'success';
    $flash_msg   = is_array($flash) ? ($flash['message'] ?? '')        : $flash;
    $alert_class = $flash_type === 'warning' ? 'alert-warning' : 'alert-success';
  ?>
    <div class="alert <?= $alert_class ?> alert-dismissible fade show" role="alert">
      <?= htmlspecialchars($flash_msg) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="io-page-header">
    <div>
      <h1>Cambiar contrasena</h1>
      <p class="text-muted mb-0" style="font-size:0.9rem;">
        Actualiza la contrasena de tu cuenta (<?= htmlspecialchars($_SESSION['username'] ?? '') ?>)
      </p>
    </div>
  </div>

  <div class="io-card" style="max-width:480px;">

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <ul class="mb-0">
          <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="POST" action="cambiar_password.php" id="formCambiar">

      <div class="mb-3">
        <label for="current_password" class="form-label">
          Contrasena actual <span class="text-danger">*</span>
        </label>
        <input type="password" id="current_password" name="current_password"
               class="form-control" required autocomplete="current-password">
      </div>

      <div class="mb-3">
        <label for="nueva_password" class="form-label">
          Nueva contrasena <span class="text-danger">*</span>
        </label>
        <input type="password" id="nueva_password" name="nueva_password"
               class="form-control" required autocomplete="new-password">
      </div>

      <div class="mb-4">
        <label for="confirmar_password" class="form-label">
          Confirmar nueva contrasena <span class="text-danger">*</span>
        </label>
        <input type="password" id="confirmar_password" name="confirmar_password"
               class="form-control" required autocomplete="new-password">
        <div id="mismatch_msg" class="invalid-feedback">Las contrasenas no coinciden.</div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-io-blue">Actualizar contrasena</button>
        <a href="index.php" class="btn btn-outline-secondary">Cancelar</a>
      </div>

    </form>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var form      = document.getElementById('formCambiar');
    var nueva     = document.getElementById('nueva_password');
    var confirmar = document.getElementById('confirmar_password');
    var msg       = document.getElementById('mismatch_msg');

    confirmar.addEventListener('input', function () {
        confirmar.classList.remove('is-invalid');
    });

    form.addEventListener('submit', function (e) {
        if (nueva.value !== confirmar.value) {
            e.preventDefault();
            confirmar.classList.add('is-invalid');
            confirmar.focus();
        }
    });
}());
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
