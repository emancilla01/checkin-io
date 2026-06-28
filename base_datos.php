<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
auth_require();

// --- Name search ---
$search = trim($_GET['search'] ?? '');

// --- Date range ---
$fecha_desde = trim($_GET['fecha_desde'] ?? '');
$fecha_hasta = trim($_GET['fecha_hasta'] ?? '');

// --- Sort ---
$allowed_sort = ['nombre', 'apellido', 'fecha_llegada'];
$sort      = in_array($_GET['sort'] ?? '', $allowed_sort) ? $_GET['sort'] : null;
$direction = strtolower($_GET['direction'] ?? '') === 'asc' ? 'ASC' : 'DESC';

$order_clause = $sort
    ? "ORDER BY e.$sort $direction"
    : "ORDER BY e.fecha_llegada DESC, e.apellido ASC, e.nombre ASC";

// --- WHERE ---
$conditions = [];
$params     = [];

if ($search !== '') {
    $conditions[] = "(e.nombre LIKE ? OR e.apellido LIKE ? OR e.crs_no LIKE ? OR e.habitacion LIKE ?)";
    $params[]     = "%$search%";
    $params[]     = "%$search%";
    $params[]     = "%$search%";
    $params[]     = "%$search%";
}

if ($fecha_desde !== '' && $fecha_hasta === '') {
    // Single day
    $conditions[] = "DATE(e.fecha_llegada) = ?";
    $params[]     = $fecha_desde;
} elseif ($fecha_desde !== '' && $fecha_hasta !== '') {
    // Inclusive range
    $conditions[] = "DATE(e.fecha_llegada) BETWEEN ? AND ?";
    $params[]     = $fecha_desde;
    $params[]     = $fecha_hasta;
}
// If neither filled: no date filter

$where = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";

// --- Pagination ---
$per_page = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

// --- Count ---
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM expedientes e $where");
$count_stmt->execute($params);
$total       = (int)$count_stmt->fetchColumn();
$total_pages = (int)ceil($total / $per_page);

// --- Main query ---
$sql = "
    SELECT
        e.id,
        e.nombre,
        e.apellido,
        e.fecha_llegada,
        e.crs_no,
        e.habitacion,
        e.identificacion_path,
        d.id        AS doc_id,
        d.signed_at AS doc_signed_at
    FROM expedientes e
    LEFT JOIN documentos d ON d.expediente_id = e.id AND d.is_merged = 1
    $where
    $order_clause
    LIMIT $per_page OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// --- Helpers ---
function doc_status_bd(array $row): string {
    if ($row['doc_id'] === null)        return 'Faltante';
    if ($row['doc_signed_at'] !== null) return 'Firmado';
    return 'Pendiente';
}

function sort_link_bd(string $col, string $label, ?string $current_sort, string $current_dir, array $filters): string {
    $new_dir = ($current_sort === $col && $current_dir === 'DESC') ? 'asc' : 'desc';
    $q       = http_build_query(array_merge($filters, ['sort' => $col, 'direction' => $new_dir, 'page' => 1]));
    $arrow   = '';
    if ($current_sort === $col) {
        $arrow = $current_dir === 'ASC' ? ' ↑' : ' ↓';
    }
    return '<a href="base_datos.php?' . $q . '" class="text-decoration-none text-dark fw-semibold">' . $label . $arrow . '</a>';
}

function page_qs_bd(int $p, array $filters): string {
    return http_build_query(array_merge($filters, ['page' => $p]));
}

$filters = [
    'search'      => $search,
    'fecha_desde' => $fecha_desde,
    'fecha_hasta' => $fecha_hasta,
    'sort'        => $sort ?? '',
    'direction'   => strtolower($direction),
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Base de datos — IO</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>

<?php $active_nav = 'base-de-datos'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container-lg py-4">

  <div class="io-page-header">
    <div>
      <h1>Base de datos</h1>
      <p class="text-muted mb-0" style="font-size:0.9rem;">Consulta y administra el historial de expedientes</p>
    </div>
  </div>

  <!-- Search / filter card -->
  <div class="io-card">
    <form method="GET" action="base_datos.php" class="row g-2 align-items-end">

      <div class="col-12 col-md-4">
        <label for="search" class="form-label mb-1">Nombre o apellido</label>
        <input type="text" id="search" name="search" class="form-control form-control-sm"
               value="<?= htmlspecialchars($search) ?>" placeholder="Buscar...">
      </div>

      <div class="col-6 col-md-3">
        <label for="fecha_desde" class="form-label mb-1">Fecha desde</label>
        <input type="date" id="fecha_desde" name="fecha_desde" class="form-control form-control-sm"
               value="<?= htmlspecialchars($fecha_desde) ?>">
      </div>

      <div class="col-6 col-md-3">
        <label for="fecha_hasta" class="form-label mb-1">Fecha hasta</label>
        <input type="date" id="fecha_hasta" name="fecha_hasta" class="form-control form-control-sm"
               value="<?= htmlspecialchars($fecha_hasta) ?>">
      </div>

      <?php if ($sort):      ?><input type="hidden" name="sort"      value="<?= htmlspecialchars($sort) ?>"><?php endif; ?>
      <?php if ($direction): ?><input type="hidden" name="direction" value="<?= htmlspecialchars(strtolower($direction)) ?>"><?php endif; ?>

      <div class="col-12 col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-io-blue btn-sm">Buscar</button>
        <?php if ($search !== '' || $fecha_desde !== '' || $fecha_hasta !== ''): ?>
          <a href="base_datos.php" class="btn btn-outline-secondary btn-sm">Limpiar</a>
        <?php endif; ?>
      </div>

    </form>
  </div>

  <!-- Table card -->
  <div class="io-card p-0">
    <?php if (empty($rows)): ?>
      <p class="p-4 mb-0 text-muted">No se encontraron expedientes.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th><?= sort_link_bd('apellido',      'Apellido',        $sort, $direction, $filters) ?></th>
            <th><?= sort_link_bd('nombre',        'Nombre',          $sort, $direction, $filters) ?></th>
            <th><?= sort_link_bd('fecha_llegada', 'Fecha de llegada', $sort, $direction, $filters) ?></th>
            <th>CRS No</th>
            <th>Habitacion</th>
            <th>Estado del registro</th>
            <th>Estado de la identificacion</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row):
            $status = doc_status_bd($row);
            $id_ok  = !empty($row['identificacion_path']);
          ?>
          <tr>
            <td><?= htmlspecialchars($row['apellido']) ?></td>
            <td><?= htmlspecialchars($row['nombre']) ?></td>
            <td><?= htmlspecialchars($row['fecha_llegada']) ?></td>
            <td class="text-muted"><?= !empty($row['crs_no'])    ? htmlspecialchars($row['crs_no'])    : '-' ?></td>
            <td class="text-muted"><?= !empty($row['habitacion']) ? htmlspecialchars($row['habitacion']) : '-' ?></td>
            <td>
              <?php if ($status === 'Firmado'): ?>
                <span class="badge bg-success">Firmado</span>
              <?php elseif ($status === 'Pendiente'): ?>
                <span class="badge bg-warning text-dark">Pendiente</span>
              <?php else: ?>
                <span class="badge bg-danger">Faltante</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($id_ok): ?>
                <span class="badge bg-success">Ok</span>
              <?php else: ?>
                <span class="badge bg-danger">Faltante</span>
              <?php endif; ?>
            </td>
            <td>
              <a href="expediente.php?id=<?= (int)$row['id'] ?>"
                 class="btn btn-io-blue btn-sm">Ver</a>
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
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="base_datos.php?<?= page_qs_bd($page - 1, $filters) ?>">Anterior</a>
          </li>
          <?php for ($p = 1; $p <= $total_pages; $p++): ?>
          <li class="page-item <?= $p === $page ? 'active' : '' ?>">
            <a class="page-link" href="base_datos.php?<?= page_qs_bd($p, $filters) ?>"><?= $p ?></a>
          </li>
          <?php endfor; ?>
          <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
            <a class="page-link" href="base_datos.php?<?= page_qs_bd($page + 1, $filters) ?>">Siguiente</a>
          </li>
        </ul>
      </nav>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
