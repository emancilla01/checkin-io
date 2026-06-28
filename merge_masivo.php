<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/merge_helper.php';

if (!class_exists('FPDF')) {
    class_alias(\Fpdf\Fpdf::class, 'FPDF');
}

auth_require();
auth_start_session();

// --- Flash ---
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// --- Search ---
$search = trim($_GET['search'] ?? '');

// --- Pagination ---
$per_page = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

// --- Base WHERE: today's arrivals with no merged documento ---
// An expediente is "unmerged" when no documentos row with is_merged=1 exists for it.
$where  = "WHERE DATE(e.fecha_llegada) = CURDATE()
           AND NOT EXISTS (
               SELECT 1 FROM documentos dm
               WHERE dm.expediente_id = e.id AND dm.is_merged = 1
           )";
$params = [];

if ($search !== '') {
    $where .= " AND (e.nombre LIKE ? OR e.apellido LIKE ? OR e.crs_no LIKE ? OR e.habitacion LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// --- Count ---
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM expedientes e $where");
$count_stmt->execute($params);
$total       = (int)$count_stmt->fetchColumn();
$total_pages = (int)ceil($total / $per_page);

// --- Main query: include doc count (is_merged=0 only) per expediente ---
$sql = "
    SELECT
        e.id,
        e.apellido,
        e.nombre,
        e.fecha_llegada,
        e.crs_no,
        e.habitacion,
        COUNT(d.id) AS doc_count
    FROM expedientes e
    LEFT JOIN documentos d ON d.expediente_id = e.id AND d.is_merged = 0
    $where
    GROUP BY e.id, e.apellido, e.nombre, e.fecha_llegada, e.crs_no, e.habitacion
    ORDER BY e.apellido ASC, e.nombre ASC
    LIMIT $per_page OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// --- POST: perform a single merge ---
$merge_error      = '';
$merge_error_id   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $exp_id = (int)($_POST['expediente_id'] ?? 0);
    $nivel  = $_POST['nivel_reconocimiento'] ?? '';

    if ($exp_id > 0) {
        $exp_stmt = $pdo->prepare("SELECT * FROM expedientes WHERE id = ?");
        $exp_stmt->execute([$exp_id]);
        $exp = $exp_stmt->fetch();

        if ($exp) {
            $docs_stmt = $pdo->prepare(
                "SELECT * FROM documentos WHERE expediente_id = ? AND is_merged = 0 ORDER BY created_at ASC"
            );
            $docs_stmt->execute([$exp_id]);
            $unmerged_docs = $docs_stmt->fetchAll();

            $result = perform_merge($pdo, $exp, $unmerged_docs, $nivel, $staticDocsPath);

            if ($result === '') {
                // Success — redirect back preserving filters/page
                $_SESSION['flash'] = 'Combinado: ' . $exp['apellido'] . ', ' . $exp['nombre'] . '.';
                $qs = http_build_query(array_filter([
                    'search' => $search,
                    'page'   => $page,
                ]));
                header('Location: merge_masivo.php' . ($qs ? '?' . $qs : ''));
                exit;
            } else {
                $merge_error    = $result;
                $merge_error_id = $exp_id;
            }
        }
    }
}

function mm_page_qs(int $p, string $search): string {
    return http_build_query(array_filter(['search' => $search, 'page' => $p]));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Combinar masivo — IO</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>

<?php $active_nav = 'merge-masivo'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container-lg py-4">

  <div class="io-page-header">
    <div>
      <h1>Combinar (masivo)</h1>
      <p class="text-muted mb-0" style="font-size:0.875rem;">
        Llegadas de hoy pendientes de combinar.
      </p>
    </div>
    <a href="merge_grupo.php" class="btn btn-outline-secondary btn-sm">Combinar grupo</a>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <?= htmlspecialchars($flash) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if ($merge_error !== ''): ?>
    <div class="alert alert-danger">
      <?= $merge_error ?>
    </div>
  <?php endif; ?>

  <!-- Search -->
  <div class="io-card">
    <form method="GET" action="merge_masivo.php" class="d-flex align-items-center gap-2">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
             class="form-control form-control-sm" style="max-width:320px;"
             placeholder="Buscar por nombre, apellido, CRS No, habitacion">
      <button type="submit" class="btn btn-outline-secondary btn-sm">Buscar</button>
      <?php if ($search !== ''): ?>
        <a href="merge_masivo.php" class="btn btn-link btn-sm text-muted">Limpiar</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Table -->
  <div class="io-card p-0">
    <?php if (empty($rows)): ?>
      <p class="p-4 mb-0 text-muted">No hay llegadas pendientes de combinar.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Apellido</th>
            <th>Nombre</th>
            <th>CRS No</th>
            <th>Habitacion</th>
            <th>Documento(s)</th>
            <th style="min-width:200px;">Nivel de reconocimiento</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row):
            $has_docs    = (int)$row['doc_count'] > 0;
            $doc_label   = (int)$row['doc_count'] === 1 ? '1 archivo' : $row['doc_count'] . ' archivos';
            $row_error   = ($merge_error_id === (int)$row['id']);
          ?>
          <tr <?= $row_error ? 'class="table-danger"' : '' ?>>
            <td><?= htmlspecialchars($row['apellido']) ?></td>
            <td><?= htmlspecialchars($row['nombre']) ?></td>
            <td class="text-muted"><?= !empty($row['crs_no'])    ? htmlspecialchars($row['crs_no'])    : '—' ?></td>
            <td class="text-muted"><?= !empty($row['habitacion']) ? htmlspecialchars($row['habitacion']) : '—' ?></td>
            <td>
              <?php if ($has_docs): ?>
                <span class="text-muted" style="font-size:0.875rem;"><?= $doc_label ?></span>
              <?php else: ?>
                <span class="text-danger" style="font-size:0.8rem;">Sin tarjeta de registro</span>
              <?php endif; ?>
            </td>
            <td>
              <form method="POST" action="merge_masivo.php?<?= htmlspecialchars(mm_page_qs($page, $search)) ?>"
                    id="form-<?= (int)$row['id'] ?>">
                <input type="hidden" name="expediente_id" value="<?= (int)$row['id'] ?>">
                <select name="nivel_reconocimiento" class="form-select form-select-sm"
                        <?= !$has_docs ? 'disabled' : '' ?>>
                  <?php foreach (RECONOCIMIENTO_OPCIONES as $val => $label): ?>
                    <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td>
              <?php if ($has_docs): ?>
                <button type="submit" form="form-<?= (int)$row['id'] ?>"
                        class="btn btn-io-blue btn-sm">Combinar</button>
              <?php else: ?>
                <button class="btn btn-outline-secondary btn-sm" disabled
                        title="Sin tarjeta de registro cargada">Combinar</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="d-flex justify-content-center py-3">
      <nav>
        <ul class="pagination pagination-sm mb-0">
          <?php if ($page > 1): ?>
            <li class="page-item">
              <a class="page-link" href="?<?= mm_page_qs($page - 1, $search) ?>">«</a>
            </li>
          <?php endif; ?>
          <?php for ($p = 1; $p <= $total_pages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
              <a class="page-link" href="?<?= mm_page_qs($p, $search) ?>"><?= $p ?></a>
            </li>
          <?php endfor; ?>
          <?php if ($page < $total_pages): ?>
            <li class="page-item">
              <a class="page-link" href="?<?= mm_page_qs($page + 1, $search) ?>">»</a>
            </li>
          <?php endif; ?>
        </ul>
      </nav>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <p class="text-muted" style="font-size:0.8rem;">
    <?= $total ?> expediente(s) pendiente(s) de combinar hoy.
  </p>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
