<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
auth_require();
auth_start_session();

if (auth_role() !== 'admin') {
    $_SESSION['flash'] = 'Acceso restringido.';
    header('Location: index.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$users = $pdo->query("SELECT id, nombre, username, role FROM users ORDER BY nombre ASC")->fetchAll();

$me = (int)$_SESSION['user_id'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Usuarios — IO</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>

<?php $active_nav = 'usuarios'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container-lg py-4">

  <?php if ($flash): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
      <?= htmlspecialchars($flash) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="io-page-header">
    <div>
      <h1>Usuarios</h1>
      <p class="text-muted mb-0" style="font-size:0.9rem;">Administra las cuentas de acceso al sistema</p>
    </div>
    <button type="button" class="btn btn-io-blue"
            data-bs-toggle="modal" data-bs-target="#modalCrear">
      Agregar usuario
    </button>
  </div>

  <div class="io-card p-0">
    <?php if (empty($users)): ?>
      <p class="p-4 mb-0 text-muted">No hay usuarios registrados.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Nombre</th>
            <th>Username</th>
            <th>Rol</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr>
            <td><?= htmlspecialchars($u['nombre']) ?></td>
            <td class="text-muted"><?= htmlspecialchars($u['username']) ?></td>
            <td>
              <?php if ($u['role'] === 'admin'): ?>
                <span class="badge" style="background:var(--io-navy);">Admin</span>
              <?php elseif ($u['role'] === 'editor'): ?>
                <span class="badge" style="background:var(--io-blue);">Editor</span>
              <?php else: ?>
                <span class="badge bg-secondary">Viewer</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="dropdown">
                <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button"
                        data-bs-toggle="dropdown" aria-expanded="false">Acciones</button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <li>
                    <button class="dropdown-item"
                            data-bs-toggle="modal" data-bs-target="#modalRol"
                            data-uid="<?= (int)$u['id'] ?>"
                            data-nombre="<?= htmlspecialchars($u['nombre']) ?>"
                            data-role="<?= htmlspecialchars($u['role']) ?>">
                      Editar rol
                    </button>
                  </li>
                  <li>
                    <button class="dropdown-item"
                            data-bs-toggle="modal" data-bs-target="#modalPassword"
                            data-uid="<?= (int)$u['id'] ?>"
                            data-nombre="<?= htmlspecialchars($u['nombre']) ?>">
                      Restablecer contrasena
                    </button>
                  </li>
                  <li><hr class="dropdown-divider"></li>
                  <li>
                    <?php if ((int)$u['id'] === $me): ?>
                      <span class="dropdown-item text-muted"
                            style="cursor:default;"
                            title="No puedes eliminar tu propia cuenta">Eliminar</span>
                    <?php else: ?>
                      <button class="dropdown-item text-danger"
                              data-bs-toggle="modal" data-bs-target="#modalEliminar"
                              data-uid="<?= (int)$u['id'] ?>"
                              data-nombre="<?= htmlspecialchars($u['nombre']) ?>">
                        Eliminar
                      </button>
                    <?php endif; ?>
                  </li>
                </ul>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- ================================================ -->
<!-- Modal: Crear usuario -->
<!-- ================================================ -->
<div class="modal fade" id="modalCrear" tabindex="-1" aria-labelledby="modalCrearLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--io-navy); color:#fff;">
        <h5 class="modal-title" id="modalCrearLabel">Agregar usuario</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="usuario_crear.php" id="formCrear">
        <div class="modal-body">
          <div class="mb-3">
            <label for="crear_nombre" class="form-label">Nombre <span class="text-danger">*</span></label>
            <input type="text" id="crear_nombre" name="nombre" class="form-control"
                   maxlength="255" required>
          </div>
          <div class="mb-3">
            <label for="crear_username" class="form-label">Username <span class="text-danger">*</span></label>
            <input type="text" id="crear_username" name="username" class="form-control"
                   maxlength="100" required autocomplete="off">
          </div>
          <div class="mb-3">
            <label for="crear_password" class="form-label">Contrasena <span class="text-danger">*</span></label>
            <input type="password" id="crear_password" name="password" class="form-control"
                   required autocomplete="new-password">
          </div>
          <div class="mb-3">
            <label for="crear_password2" class="form-label">Confirmar contrasena <span class="text-danger">*</span></label>
            <input type="password" id="crear_password2" name="password2" class="form-control"
                   required autocomplete="new-password">
            <div id="crear_mismatch" class="invalid-feedback">Las contrasenas no coinciden.</div>
          </div>
          <div class="mb-1">
            <label for="crear_role" class="form-label">Rol</label>
            <select id="crear_role" name="role" class="form-select">
              <option value="viewer" selected>Viewer</option>
              <option value="editor">Editor</option>
              <option value="admin">Admin</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-io-blue">Agregar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ================================================ -->
<!-- Modal: Editar rol -->
<!-- ================================================ -->
<div class="modal fade" id="modalRol" tabindex="-1" aria-labelledby="modalRolLabel" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--io-navy); color:#fff;">
        <h5 class="modal-title" id="modalRolLabel">Editar rol</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="usuario_rol.php">
        <input type="hidden" name="id" id="rol_uid">
        <div class="modal-body">
          <p class="mb-3" style="font-size:0.9rem;">
            Usuario: <strong id="rol_nombre"></strong>
          </p>
          <label for="rol_select" class="form-label">Nuevo rol</label>
          <select id="rol_select" name="role" class="form-select">
            <option value="viewer">Viewer</option>
            <option value="editor">Editor</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-io-blue">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ================================================ -->
<!-- Modal: Restablecer contrasena -->
<!-- ================================================ -->
<div class="modal fade" id="modalPassword" tabindex="-1" aria-labelledby="modalPasswordLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--io-navy); color:#fff;">
        <h5 class="modal-title" id="modalPasswordLabel">Restablecer contrasena</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="usuario_password.php" id="formPassword">
        <input type="hidden" name="id" id="pw_uid">
        <div class="modal-body">
          <p class="mb-3" style="font-size:0.9rem;">
            Usuario: <strong id="pw_nombre"></strong>
          </p>
          <div class="mb-3">
            <label for="pw_password" class="form-label">Nueva contrasena <span class="text-danger">*</span></label>
            <input type="password" id="pw_password" name="password" class="form-control"
                   required autocomplete="new-password">
          </div>
          <div class="mb-1">
            <label for="pw_password2" class="form-label">Confirmar contrasena <span class="text-danger">*</span></label>
            <input type="password" id="pw_password2" name="password2" class="form-control"
                   required autocomplete="new-password">
            <div id="pw_mismatch" class="invalid-feedback">Las contrasenas no coinciden.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-io-blue">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ================================================ -->
<!-- Modal: Confirmar eliminacion -->
<!-- ================================================ -->
<div class="modal fade" id="modalEliminar" tabindex="-1" aria-labelledby="modalEliminarLabel" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="modalEliminarLabel">Eliminar usuario</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="usuario_delete.php">
        <input type="hidden" name="id" id="del_uid">
        <div class="modal-body">
          <p class="mb-0">
            ¿Estas seguro de eliminar a <strong id="del_nombre"></strong>?
            Esta accion no se puede deshacer.
          </p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-danger">Eliminar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Wire Editar rol modal
document.getElementById('modalRol').addEventListener('show.bs.modal', function (e) {
    var btn = e.relatedTarget;
    document.getElementById('rol_uid').value    = btn.dataset.uid;
    document.getElementById('rol_nombre').textContent = btn.dataset.nombre;
    var sel = document.getElementById('rol_select');
    sel.value = btn.dataset.role;
});

// Wire Restablecer contrasena modal
document.getElementById('modalPassword').addEventListener('show.bs.modal', function (e) {
    var btn = e.relatedTarget;
    document.getElementById('pw_uid').value              = btn.dataset.uid;
    document.getElementById('pw_nombre').textContent     = btn.dataset.nombre;
    document.getElementById('pw_password').value         = '';
    document.getElementById('pw_password2').value        = '';
    document.getElementById('pw_password').classList.remove('is-invalid');
    document.getElementById('pw_password2').classList.remove('is-invalid');
});

// Wire Eliminar modal
document.getElementById('modalEliminar').addEventListener('show.bs.modal', function (e) {
    var btn = e.relatedTarget;
    document.getElementById('del_uid').value          = btn.dataset.uid;
    document.getElementById('del_nombre').textContent = btn.dataset.nombre;
});

// Client-side password-match validation — Crear
document.getElementById('formCrear').addEventListener('submit', function (e) {
    var p1 = document.getElementById('crear_password');
    var p2 = document.getElementById('crear_password2');
    if (p1.value !== p2.value) {
        e.preventDefault();
        p2.classList.add('is-invalid');
    } else {
        p2.classList.remove('is-invalid');
    }
});
document.getElementById('crear_password2').addEventListener('input', function () {
    this.classList.remove('is-invalid');
});

// Client-side password-match validation — Restablecer
document.getElementById('formPassword').addEventListener('submit', function (e) {
    var p1 = document.getElementById('pw_password');
    var p2 = document.getElementById('pw_password2');
    if (p1.value !== p2.value) {
        e.preventDefault();
        p2.classList.add('is-invalid');
    } else {
        p2.classList.remove('is-invalid');
    }
});
document.getElementById('pw_password2').addEventListener('input', function () {
    this.classList.remove('is-invalid');
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
