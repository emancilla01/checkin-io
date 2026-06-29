<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ocr/PdfFirstPageImageConverter.php';
require_once __DIR__ . '/includes/ocr/TesseractOcrService.php';
require_once __DIR__ . '/includes/ocr/RegisterCardTextParser.php';
auth_require();

// TODO: move uploads outside the public web root in production.
define('UPLOAD_DIR',   __DIR__ . '/uploads/');
define('OCR_TEMP_DIR', __DIR__ . '/private/uploads-temp/');

foreach ([UPLOAD_DIR, OCR_TEMP_DIR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

// --- Filename sanitizer ---
function sanitize_filename(string $name): string {
    $name = mb_strtolower($name, 'UTF-8');
    $map  = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
              'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u'];
    $name = strtr($name, $map);
    $name = preg_replace('/[^a-z0-9.\-]/', '_', $name);
    $name = preg_replace('/_+/', '_', $name);
    return trim($name, '_');
}

function unique_path(string $dir, string $filename): string {
    $path = $dir . $filename;
    if (!file_exists($path)) return $path;
    $info = pathinfo($filename);
    $base = $info['filename'];
    $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';
    $i = 2;
    while (file_exists($dir . $base . '_' . $i . $ext)) $i++;
    return $dir . $base . '_' . $i . $ext;
}

auth_start_session();

$ocr_error  = '';
$ocr_done   = false;

// Prefill values — start from session OCR result if present, else blank/today
$prefill = $_SESSION['ocr_prefill'] ?? null;
$old = [
    'nombre'        => $prefill['nombre']        ?? '',
    'apellido'      => $prefill['apellido']       ?? '',
    'fecha_llegada' => $prefill['fecha_llegada']  ?? date('Y-m-d'),
    'crs_no'        => $prefill['crs_no']         ?? '',
];
$ocr_temp_path = $_SESSION['ocr_temp_path'] ?? '';   // temp PDF from OCR step

$errors = [];

// =========================================================
// ACTION: OCR prefill
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'ocr') {

    if (empty($_FILES['reg_card']['name']) || $_FILES['reg_card']['error'] !== UPLOAD_ERR_OK) {
        $ocr_error = 'Por favor selecciona un archivo PDF para continuar.';
    } else {
        $file = $_FILES['reg_card'];

        // Save to temp dir with a unique name
        $safe_name = sanitize_filename(basename($file['name']));
        $temp_dest = unique_path(OCR_TEMP_DIR, $safe_name);
        move_uploaded_file($file['tmp_name'], $temp_dest);

        try {
            // Convert first page to image
            $imgDir   = OCR_TEMP_DIR . 'img_' . uniqid() . DIRECTORY_SEPARATOR;
            $converter = new PdfFirstPageImageConverter($imgDir);
            $imgPath   = $converter->convert($temp_dest);

            // Run Tesseract OCR
            $tesseract = new TesseractOcrService();
            $rawText   = $tesseract->recognize($imgPath);

            // Clean up image
            if (file_exists($imgPath)) unlink($imgPath);
            if (is_dir($imgDir)) rmdir($imgDir);

            // Parse fields
            $parser  = new RegisterCardTextParser();
            $parsed  = $parser->parse($rawText);

            // Store in session for the main form
            $_SESSION['ocr_prefill'] = $parsed;
            $_SESSION['ocr_temp_path'] = $temp_dest;

        } catch (Exception $e) {
            // OCR failed — keep temp file for attachment but show blank form
            error_log('[IO OCR] ' . get_class($e) . ': ' . $e->getMessage());
            $_SESSION['ocr_prefill']   = [];
            $_SESSION['ocr_temp_path'] = $temp_dest;
            $ocr_error = 'OCR no pudo extraer datos del archivo. Puedes llenar el formulario manualmente.';
        }
    }

    // PRG — redirect back to GET to avoid re-submission
    $qs = $ocr_error ? '?ocr_error=' . urlencode($ocr_error) : '';
    header('Location: registro_nuevo.php' . $qs);
    exit;
}

// Pick up OCR error from redirect if any
if (isset($_GET['ocr_error'])) {
    $ocr_error = htmlspecialchars($_GET['ocr_error']);
}

// Reload prefill after redirect
$prefill = $_SESSION['ocr_prefill'] ?? null;
if ($prefill !== null) {
    $old = [
        'nombre'        => $prefill['nombre']        ?? '',
        'apellido'      => $prefill['apellido']       ?? '',
        'fecha_llegada' => ($prefill['fecha_llegada'] ?? '') ?: date('Y-m-d'),
        'crs_no'        => $prefill['crs_no']         ?? '',
    ];
    $ocr_done = true;
}
$ocr_temp_path = $_SESSION['ocr_temp_path'] ?? '';

// =========================================================
// ACTION: Save expediente
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save') {

    $nombre        = trim($_POST['nombre']        ?? '');
    $apellido      = trim($_POST['apellido']      ?? '');
    $fecha_llegada = trim($_POST['fecha_llegada'] ?? '');
    $crs_no        = trim($_POST['crs_no']        ?? '');

    $old = compact('nombre', 'apellido', 'fecha_llegada', 'crs_no');
    $old['habitacion'] = '';

    if ($nombre === '')        $errors[] = 'El nombre es obligatorio.';
    if ($apellido === '')      $errors[] = 'El apellido es obligatorio.';
    if ($fecha_llegada === '') $errors[] = 'La fecha de llegada es obligatoria.';

    if (empty($errors)) {
        // Insert expediente
        $stmt = $pdo->prepare(
            "INSERT INTO expedientes (nombre, apellido, fecha_llegada, crs_no) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$nombre, $apellido, $fecha_llegada, $crs_no !== '' ? $crs_no : null]);
        $expediente_id = (int)$pdo->lastInsertId();

        // Attach OCR temp PDF as the first documento (moves from temp to uploads)
        $ocr_temp = $_POST['ocr_temp_path'] ?? '';
        if ($ocr_temp !== '' && file_exists($ocr_temp)) {
            $safe_name = sanitize_filename(basename($ocr_temp));
            $dest      = unique_path(UPLOAD_DIR, $safe_name);
            rename($ocr_temp, $dest);
            $rel_path  = 'uploads/' . basename($dest);
            $pdo->prepare(
                "INSERT INTO documentos (expediente_id, path, original_name, is_merged) VALUES (?, ?, ?, 0)"
            )->execute([$expediente_id, $rel_path, $safe_name]);
        }

        // Additional documento PDFs uploaded on the main form
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

        // Identificacion
        if (!empty($_FILES['identificacion']['name'])) {
            $file = $_FILES['identificacion'];
            if ($file['error'] === UPLOAD_ERR_OK) {
                $safe_name = sanitize_filename(basename($file['name']));
                $dest      = unique_path(UPLOAD_DIR, $safe_name);
                move_uploaded_file($file['tmp_name'], $dest);
                $rel_path  = 'uploads/' . basename($dest);
                $pdo->prepare("UPDATE expedientes SET identificacion_path = ? WHERE id = ?")
                    ->execute([$rel_path, $expediente_id]);
            }
        }

        // Clear OCR session state
        unset($_SESSION['ocr_prefill'], $_SESSION['ocr_temp_path']);

        $_SESSION['flash'] = "Registro de {$apellido}, {$nombre} creado correctamente.";
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

    <!-- ================================================ -->
    <!-- STEP 1: OCR upload -->
    <!-- ================================================ -->
    <div class="mb-4 pb-4 border-bottom">
      <h6 class="fw-semibold mb-1" style="color:var(--io-navy);">Tarjeta de registro (OCR)</h6>
      <p class="text-muted mb-3" style="font-size:0.875rem;">
        Sube el PDF de la tarjeta de registro para pre-llenar los campos automaticamente.
        <?php if ($ocr_done): ?>
          <span class="text-success fw-semibold">Datos extraidos — revisa y corrige antes de guardar.</span>
        <?php endif; ?>
      </p>

      <?php if ($ocr_error !== ''): ?>
        <div class="alert alert-warning py-2" style="font-size:0.875rem;"><?= $ocr_error ?></div>
      <?php endif; ?>

      <form method="POST" action="registro_nuevo.php" enctype="multipart/form-data">
        <input type="hidden" name="_action" value="ocr">

        <div class="io-upload-box mb-2"
             data-upload-box
             data-upload-input="#reg_card_input"
             tabindex="0" role="button" aria-label="Subir tarjeta de registro PDF">
          <span data-upload-filename>Arrastra el archivo aqui o haz clic para seleccionar</span>
          <input type="file" id="reg_card_input" name="reg_card"
                 accept="application/pdf" required style="display:none;">
        </div>

        <button type="submit" class="btn btn-outline-secondary btn-sm">Continuar</button>
      </form>

      <?php if ($ocr_temp_path !== ''): ?>
        <p class="text-muted mt-2 mb-0" style="font-size:0.8rem;">
          Archivo cargado: <strong><?= htmlspecialchars(basename($ocr_temp_path)) ?></strong>
          — se adjuntara al expediente al guardar.
        </p>
      <?php endif; ?>
    </div>

    <!-- ================================================ -->
    <!-- STEP 2: Main form -->
    <!-- ================================================ -->
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
      <input type="hidden" name="_action" value="save">
      <!-- Pass temp PDF path through the form so Guardar knows what to attach -->
      <input type="hidden" name="ocr_temp_path" value="<?= htmlspecialchars($ocr_temp_path) ?>">

      <div class="mb-3">
        <label for="apellido" class="form-label">Apellido <span class="text-danger">*</span></label>
        <input type="text" id="apellido" name="apellido" class="form-control"
               maxlength="255" required
               value="<?= htmlspecialchars($old['apellido']) ?>">
      </div>

      <div class="mb-3">
        <label for="nombre" class="form-label">Nombre <span class="text-danger">*</span></label>
        <input type="text" id="nombre" name="nombre" class="form-control"
               maxlength="255" required
               value="<?= htmlspecialchars($old['nombre']) ?>">
      </div>

      <div class="mb-3">
        <label for="fecha_llegada" class="form-label">Fecha de llegada <span class="text-danger">*</span></label>
        <input type="date" id="fecha_llegada" name="fecha_llegada" class="form-control"
               required value="<?= htmlspecialchars($old['fecha_llegada']) ?>">
      </div>

      <div class="mb-3">
        <label for="crs_no" class="form-label">CRS No</label>
        <input type="text" id="crs_no" name="crs_no" class="form-control"
               maxlength="50"
               value="<?= htmlspecialchars($old['crs_no']) ?>">
      </div>

      <div class="mb-3">
        <label class="form-label">Documento(s) PDF adicionales</label>
        <div class="io-upload-box"
             data-upload-box
             data-upload-input="#documentos_input"
             tabindex="0" role="button" aria-label="Subir documentos PDF">
          <span data-upload-filename>Arrastra el archivo aqui o haz clic para seleccionar</span>
          <input type="file" id="documentos_input" name="documentos[]"
                 accept="application/pdf" multiple style="display:none;">
        </div>
        <div class="form-text mt-1">
          Opcional.
          <?= $ocr_temp_path !== '' ? 'La tarjeta de registro ya sera adjuntada. Puedes agregar mas PDFs aqui.' : 'Puede subir uno o varios PDFs.' ?>
        </div>
      </div>

      <div class="mb-4">
        <label class="form-label">Identificacion</label>
        <div class="io-upload-box"
             data-upload-box
             data-upload-input="#identificacion_input"
             tabindex="0" role="button" aria-label="Subir identificacion">
          <span data-upload-filename>Arrastra el archivo aqui o haz clic para seleccionar</span>
          <input type="file" id="identificacion_input" name="identificacion"
                 accept="application/pdf,image/*" style="display:none;">
        </div>
        <div class="form-text mt-1">Opcional. PDF o imagen.</div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-io-blue">Guardar</button>
        <a href="index.php" class="btn btn-outline-secondary"
           onclick="return confirm('¿Descartar este registro?');">Cancelar</a>
      </div>

    </form>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/upload-boxes.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
