<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
auth_require();

// TODO: move uploads outside the public web root in production.
// For now files are stored under uploads/ in the project root.
define('UPLOAD_DIR', __DIR__ . '/uploads/');

if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// --- Filename sanitizer ---
// Lowercase, strip accents, replace non-alphanumeric with underscore, collapse runs.
function sanitize_filename(string $name): string {
    $name = mb_strtolower($name, 'UTF-8');
    $map  = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
              'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u'];
    $name = strtr($name, $map);
    $name = preg_replace('/[^a-z0-9.\-]/', '_', $name);
    $name = preg_replace('/_+/', '_', $name);
    return trim($name, '_');
}

// Auto-number to avoid collisions: file.pdf → file_2.pdf, file_3.pdf …
function unique_path(string $dir, string $filename): string {
    $path = $dir . $filename;
    if (!file_exists($path)) return $path;

    $info  = pathinfo($filename);
    $base  = $info['filename'];
    $ext   = isset($info['extension']) ? '.' . $info['extension'] : '';
    $i     = 2;
    while (file_exists($dir . $base . '_' . $i . $ext)) $i++;
    return $dir . $base . '_' . $i . $ext;
}

$errors = [];
$old    = ['nombre' => '', 'apellido' => '', 'fecha_llegada' => date('Y-m-d')];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre       = trim($_POST['nombre']       ?? '');
    $apellido     = trim($_POST['apellido']     ?? '');
    $fecha_llegada = trim($_POST['fecha_llegada'] ?? '');

    $old = ['nombre' => $nombre, 'apellido' => $apellido, 'fecha_llegada' => $fecha_llegada];

    if ($nombre === '')        $errors[] = 'El nombre es obligatorio.';
    if ($apellido === '')      $errors[] = 'El apellido es obligatorio.';
    if ($fecha_llegada === '') $errors[] = 'La fecha de llegada es obligatoria.';

    if (empty($errors)) {
        // Insert expediente
        $stmt = $pdo->prepare(
            "INSERT INTO expedientes (nombre, apellido, fecha_llegada) VALUES (?, ?, ?)"
        );
        $stmt->execute([$nombre, $apellido, $fecha_llegada]);
        $expediente_id = (int)$pdo->lastInsertId();

        // Handle identificacion upload
        if (!empty($_FILES['identificacion']['name'])) {
            $file = $_FILES['identificacion'];
            if ($file['error'] === UPLOAD_ERR_OK) {
                $safe_name = sanitize_filename(basename($file['name']));
                $dest      = unique_path(UPLOAD_DIR, $safe_name);
                move_uploaded_file($file['tmp_name'], $dest);
                $rel_path = 'uploads/' . basename($dest);

                $pdo->prepare("UPDATE expedientes SET identificacion_path = ? WHERE id = ?")
                    ->execute([$rel_path, $expediente_id]);
            }
        }

        // Handle document PDF uploads (is_merged = 0)
        if (!empty($_FILES['documentos']['name'][0])) {
            $files = $_FILES['documentos'];
            $count = count($files['name']);
            $ins   = $pdo->prepare(
                "INSERT INTO documentos (expediente_id, path, original_name, is_merged) VALUES (?, ?, ?, 0)"
            );
            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
                $safe_name = sanitize_filename(basename($files['name'][$i]));
                $dest      = unique_path(UPLOAD_DIR, $safe_name);
                move_uploaded_file($files['tmp_name'][$i], $dest);
                $rel_path  = 'uploads/' . basename($dest);
                $ins->execute([$expediente_id, $rel_path, $safe_name]);
            }
        }

        auth_start_session();
        $_SESSION['flash'] = "Registro de {$nombre} {$apellido} creado correctamente.";
        header('Location: index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Agregar registro — IO</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>

<?php $active_nav = 'registro-nuevo'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container-lg py-4">

  <div class="io-page-header">
    <h1>Agregar registro</h1>
  </div>

  <div class="io-card" style="max-width:640px;">

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <ul class="mb-0">
          <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="POST" action="registro_nuevo.php" enctype="multipart/form-data">

      <div class="mb-3">
        <label for="nombre" class="form-label">Nombre <span class="text-danger">*</span></label>
        <input type="text" id="nombre" name="nombre" class="form-control"
               maxlength="255" required
               value="<?= htmlspecialchars($old['nombre']) ?>">
      </div>

      <div class="mb-3">
        <label for="apellido" class="form-label">Apellido <span class="text-danger">*</span></label>
        <input type="text" id="apellido" name="apellido" class="form-control"
               maxlength="255" required
               value="<?= htmlspecialchars($old['apellido']) ?>">
      </div>

      <div class="mb-3">
        <label for="fecha_llegada" class="form-label">Fecha de llegada <span class="text-danger">*</span></label>
        <input type="date" id="fecha_llegada" name="fecha_llegada" class="form-control"
               required value="<?= htmlspecialchars($old['fecha_llegada']) ?>">
      </div>

      <div class="mb-3">
        <label for="documentos" class="form-label">Documento(s) PDF</label>
        <input type="file" id="documentos" name="documentos[]" class="form-control"
               accept="application/pdf" multiple>
        <div class="form-text">Opcional. Puede subir uno o varios PDFs.</div>
      </div>

      <div class="mb-4">
        <label for="identificacion" class="form-label">Identificacion</label>
        <input type="file" id="identificacion" name="identificacion" class="form-control"
               accept="application/pdf,image/*">
        <div class="form-text">Opcional. PDF o imagen.</div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-io-blue">Guardar</button>
        <a href="index.php" class="btn btn-outline-secondary">Cancelar</a>
      </div>

    </form>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
