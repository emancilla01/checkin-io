<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
auth_require();
auth_require_role(['admin', 'editor']);

define('UPLOAD_DIR', __DIR__ . '/uploads/');

if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM expedientes WHERE id = ?");
$stmt->execute([$id]);
$exp = $stmt->fetch();

if (!$exp) {
    header('Location: index.php');
    exit;
}

// Count existing documentos
$doc_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM documentos WHERE expediente_id = ?");
$doc_count_stmt->execute([$id]);
$doc_count = (int)$doc_count_stmt->fetchColumn();

function sanitize_filename_ed(string $name): string {
    $name = mb_strtolower($name, 'UTF-8');
    $map  = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
              'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u'];
    $name = strtr($name, $map);
    $name = preg_replace('/[^a-z0-9.\-]/', '_', $name);
    $name = preg_replace('/_+/', '_', $name);
    return trim($name, '_');
}

function unique_path_ed(string $dir, string $filename): string {
    $path = $dir . $filename;
    if (!file_exists($path)) return $path;
    $info = pathinfo($filename);
    $base = $info['filename'];
    $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';
    $i    = 2;
    while (file_exists($dir . $base . '_' . $i . $ext)) $i++;
    return $dir . $base . '_' . $i . $ext;
}

$errors = [];
$old = [
    'nombre'        => $exp['nombre'],
    'apellido'      => $exp['apellido'],
    'fecha_llegada' => $exp['fecha_llegada'],
    'crs_no'        => $exp['crs_no'] ?? '',
    'habitacion'    => $exp['habitacion'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre        = trim($_POST['nombre']        ?? '');
    $apellido      = trim($_POST['apellido']      ?? '');
    $fecha_llegada = trim($_POST['fecha_llegada'] ?? '');
    $crs_no        = trim($_POST['crs_no']        ?? '');
    $habitacion    = trim($_POST['habitacion']    ?? '');

    $old = compact('nombre', 'apellido', 'fecha_llegada', 'crs_no', 'habitacion');

    if ($nombre === '')        $errors[] = 'El nombre es obligatorio.';
    if ($apellido === '')      $errors[] = 'El apellido es obligatorio.';
    if ($fecha_llegada === '') $errors[] = 'La fecha de llegada es obligatoria.';

    if (empty($errors)) {
        $pdo->prepare("
            UPDATE expedientes
            SET nombre = ?, apellido = ?, fecha_llegada = ?, crs_no = ?, habitacion = ?
            WHERE id = ?
        ")->execute([$nombre, $apellido, $fecha_llegada,
                     $crs_no !== '' ? $crs_no : null,
                     $habitacion !== '' ? $habitacion : null,
                     $id]);

        // New identificacion — adds a new documentos row (is_identificacion = 1)
        if (!empty($_FILES['identificacion']['name'])) {
            $file = $_FILES['identificacion'];
            if ($file['error'] === UPLOAD_ERR_OK) {
                $safe = sanitize_filename_ed(basename($file['name']));
                $dest = unique_path_ed(UPLOAD_DIR, $safe);
                move_uploaded_file($file['tmp_name'], $dest);
                $rel  = 'uploads/' . basename($dest);
                $pdo->prepare(
                    "INSERT INTO documentos (expediente_id, path, original_name, is_merged, is_identificacion) VALUES (?, ?, ?, 0, 1)"
                )->execute([$id, $rel, $safe]);
            }
        }

        // New documento PDF (adds, does not replace)
        if (!empty($_FILES['documento']['name'])) {
            $file = $_FILES['documento'];
            if ($file['error'] === UPLOAD_ERR_OK) {
                $safe = sanitize_filename_ed(basename($file['name']));
                $dest = unique_path_ed(UPLOAD_DIR, $safe);
                move_uploaded_file($file['tmp_name'], $dest);
                $rel  = 'uploads/' . basename($dest);
                $pdo->prepare("INSERT INTO documentos (expediente_id, path, original_name, is_merged) VALUES (?, ?, ?, 0)")
                    ->execute([$id, $rel, $safe]);
            }
        }

        auth_start_session();
        $_SESSION['flash'] = "Expediente de {$apellido}, {$nombre} actualizado correctamente.";
        header("Location: expediente.php?id=$id");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Editar expediente — IO</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>

<?php $active_nav = ''; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container-lg py-4">

  <div class="io-page-header mb-3">
    <div>
      <h1 class="mb-0">Editar expediente</h1>
      <p class="text-muted mb-0" style="font-size:0.9rem;">
        <?= htmlspecialchars($exp['apellido'] . ', ' . $exp['nombre']) ?>
      </p>
    </div>
  </div>

  <div class="io-card">

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <ul class="mb-0">
          <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="POST" action="expediente_editar.php" enctype="multipart/form-data">
      <input type="hidden" name="id" value="<?= $id ?>">

      <!-- Row 1: Apellido | Nombre -->
      <div class="row g-3 mb-3">
        <div class="col-12 col-md-6">
          <label for="apellido" class="form-label">Apellido <span class="text-danger">*</span></label>
          <input type="text" id="apellido" name="apellido" class="form-control"
                 maxlength="255" required
                 value="<?= htmlspecialchars($old['apellido']) ?>">
        </div>
        <div class="col-12 col-md-6">
          <label for="nombre" class="form-label">Nombre <span class="text-danger">*</span></label>
          <input type="text" id="nombre" name="nombre" class="form-control"
                 maxlength="255" required
                 value="<?= htmlspecialchars($old['nombre']) ?>">
        </div>
      </div>

      <!-- Row 2: Fecha de llegada | CRS No | Habitacion -->
      <div class="row g-3 mb-3">
        <div class="col-12 col-md-4">
          <label for="fecha_llegada" class="form-label">Fecha de llegada <span class="text-danger">*</span></label>
          <input type="date" id="fecha_llegada" name="fecha_llegada" class="form-control"
                 required value="<?= htmlspecialchars($old['fecha_llegada']) ?>">
        </div>
        <div class="col-12 col-md-4">
          <label for="crs_no" class="form-label">CRS No</label>
          <input type="text" id="crs_no" name="crs_no" class="form-control"
                 maxlength="50"
                 value="<?= htmlspecialchars($old['crs_no']) ?>">
        </div>
        <div class="col-12 col-md-4">
          <label for="habitacion" class="form-label">Habitacion</label>
          <input type="text" id="habitacion" name="habitacion" class="form-control"
                 maxlength="20"
                 value="<?= htmlspecialchars($old['habitacion']) ?>">
        </div>
      </div>

      <!-- Row 3: Documento PDF (full width) -->
      <div class="mb-3">
        <label class="form-label">Agregar documento PDF</label>
        <input type="file" id="documento" name="documento"
               accept="application/pdf" style="display:none;">
        <div class="io-upload-box"
             data-upload-box
             data-upload-input="#documento"
             tabindex="0" role="button" aria-label="Subir documento PDF">
          <span data-upload-filename>Arrastra el archivo aqui o haz clic para seleccionar</span>
        </div>
        <div class="form-text mt-1">
          <?php if ($doc_count === 0): ?>
            No hay documentos adjuntos aun. Subir uno lo agregara al expediente.
          <?php else: ?>
            Este expediente tiene <?= $doc_count ?> documento<?= $doc_count > 1 ? 's' : '' ?> adjunto<?= $doc_count > 1 ? 's' : '' ?>.
            Subir un archivo agrega uno adicional, no reemplaza los existentes.
          <?php endif; ?>
        </div>
      </div>

      <!-- Row 4: Identificacion (full width) -->
      <div class="mb-4">
        <label class="form-label">Agregar identificacion</label>
        <input type="file" id="identificacion" name="identificacion"
               accept="application/pdf,image/*" style="display:none;">
        <div class="io-upload-box"
             data-upload-box
             data-upload-input="#identificacion"
             tabindex="0" role="button" aria-label="Subir identificacion">
          <span data-upload-filename>Arrastra el archivo aqui o haz clic para seleccionar</span>
        </div>
        <div class="form-text mt-1">Opcional. PDF o imagen.</div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-io-blue">Guardar cambios</button>
        <a href="expediente.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancelar</a>
      </div>

    </form>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/upload-boxes.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
