<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
auth_require();
auth_start_session();
auth_require_role(['admin', 'editor']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Carga masiva — IO</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>

<?php $active_nav = 'carga-masiva'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="container-lg py-4">

  <div class="io-page-header">
    <div>
      <h1>Carga masiva</h1>
      <p class="text-muted mb-0" style="font-size:0.875rem;">
        Procesa multiples tarjetas de registro con OCR. Cada archivo se procesa por separado.
      </p>
    </div>
  </div>

  <!-- Upload card -->
  <div class="io-card" style="max-width:680px;">
    <h6 class="fw-semibold mb-3" style="color:var(--io-navy);">Selecciona los archivos PDF</h6>

    <div class="io-upload-box mb-3"
         data-upload-box
         data-upload-input="#batch_input"
         tabindex="0" role="button" aria-label="Subir tarjetas de registro">
      <span data-upload-filename>Arrastra los archivos aqui o haz clic para seleccionar</span>
      <input type="file" id="batch_input" name="reg_cards[]"
             accept="application/pdf" multiple style="display:none;">
    </div>

    <button id="btn-procesar" class="btn btn-io-blue" disabled>
      Procesar registros
    </button>
  </div>

  <!-- Summary badges -->
  <div id="summary-bar" class="mb-3" style="display:none;">
    <span class="badge bg-success fs-6 me-2" id="badge-listos">0 Listos</span>
    <span class="badge bg-warning text-dark fs-6 me-2" id="badge-incompletos">0 Incompletos</span>
    <span class="badge bg-danger fs-6" id="badge-errores">0 Errores</span>
    <span id="processing-indicator" class="ms-3 text-muted" style="font-size:0.875rem; display:none;">
      Procesando...
    </span>
  </div>

  <!-- Results table -->
  <div id="results-section" style="display:none;">
    <div class="io-card p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:36px;"></th>
              <th>Archivo</th>
              <th>Apellido</th>
              <th>Nombre</th>
              <th>Fecha llegada</th>
              <th>CRS No</th>
              <th>Estado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody id="results-tbody"></tbody>
        </table>
      </div>
    </div>

    <!-- Save buttons -->
    <div class="d-flex gap-2 mt-3" id="save-bar" style="display:none !important;">
      <button id="btn-guardar-seleccionados" class="btn btn-io-blue">
        Guardar seleccionados
      </button>
      <button id="btn-guardar-todos" class="btn btn-outline-secondary">
        Guardar todos los validos
      </button>
      <a href="index.php" class="btn btn-link text-muted ms-auto">Cancelar</a>
    </div>

    <div id="save-result" class="mt-3" style="display:none;"></div>
  </div>

</div>

<!-- Revisar modal -->
<div class="modal fade" id="modal-revisar" tabindex="-1" aria-labelledby="modal-revisar-label" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--io-navy); color:#fff;">
        <h5 class="modal-title" id="modal-revisar-label">Revisar registro</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted mb-3" style="font-size:0.875rem;" id="modal-filename"></p>
        <input type="hidden" id="modal-row-index">
        <div class="mb-3">
          <label for="modal-apellido" class="form-label">Apellido <span class="text-danger">*</span></label>
          <input type="text" id="modal-apellido" class="form-control" maxlength="255">
        </div>
        <div class="mb-3">
          <label for="modal-nombre" class="form-label">Nombre <span class="text-danger">*</span></label>
          <input type="text" id="modal-nombre" class="form-control" maxlength="255">
        </div>
        <div class="mb-3">
          <label for="modal-fecha" class="form-label">Fecha de llegada <span class="text-danger">*</span></label>
          <input type="date" id="modal-fecha" class="form-control">
        </div>
        <div class="mb-3">
          <label for="modal-crs" class="form-label">CRS No</label>
          <input type="text" id="modal-crs" class="form-control" maxlength="50">
        </div>
        <p id="modal-error" class="text-danger mb-0" style="display:none;font-size:0.875rem;"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-io-blue" id="modal-guardar">Guardar cambios</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/upload-boxes.js"></script>
<script>
(function () {
  // ── State ─────────────────────────────────────────────────────────────────
  let rows = [];           // {index, filename, apellido, nombre, fecha_llegada, crs_no, temp_path, status, saved}
  let counters = { listo: 0, incompleto: 0, error: 0 };
  let processing = false;

  // ── DOM refs ──────────────────────────────────────────────────────────────
  const batchInput       = document.getElementById('batch_input');
  const btnProcesar      = document.getElementById('btn-procesar');
  const summaryBar       = document.getElementById('summary-bar');
  const badgeListo       = document.getElementById('badge-listos');
  const badgeIncompleto  = document.getElementById('badge-incompletos');
  const badgeError       = document.getElementById('badge-errores');
  const processingInd    = document.getElementById('processing-indicator');
  const resultsSection   = document.getElementById('results-section');
  const resultsTbody     = document.getElementById('results-tbody');
  const saveBar          = document.getElementById('save-bar');
  const saveResult       = document.getElementById('save-result');
  const modalEl = document.getElementById('modal-revisar');
  let bsModal   = null;  // lazy — created on first open to avoid timing issues
  function getModal() {
    if (!bsModal) bsModal = new bootstrap.Modal(modalEl);
    return bsModal;
  }

  // Enable "Procesar registros" by watching the upload box for the has-file class.
  // upload-boxes.js adds has-file on both click-to-browse and drag-and-drop,
  // so this works regardless of which event path was taken.
  const uploadBox = document.querySelector('[data-upload-input="#batch_input"]');
  if (uploadBox) {
    new MutationObserver(function () {
      btnProcesar.disabled = !uploadBox.classList.contains('has-file');
    }).observe(uploadBox, { attributes: true, attributeFilter: ['class'] });
  }

  // ── Process ───────────────────────────────────────────────────────────────
  btnProcesar.addEventListener('click', async function () {
    if (processing) return;
    const files = batchInput.files;
    if (!files || files.length === 0) return;

    // Reset state
    rows = [];
    counters = { listo: 0, incompleto: 0, error: 0 };
    resultsTbody.innerHTML = '';
    saveResult.style.display = 'none';
    saveBar.style.setProperty('display', 'none', 'important');
    resultsSection.style.display = 'block';
    summaryBar.style.display = 'block';
    processingInd.style.display = 'inline';
    btnProcesar.disabled = true;
    updateBadges();

    processing = true;

    for (let i = 0; i < files.length; i++) {
      await processOne(files[i], i);
    }

    processing = false;
    processingInd.style.display = 'none';
    btnProcesar.disabled = false;

    // Show save bar if any usable rows
    const usable = rows.filter(r => r.status !== 'error').length;
    if (usable > 0) {
      saveBar.style.removeProperty('display');
    }
  });

  async function processOne(file, index) {
    // Add pending row
    rows.push({
      index,
      filename:      file.name,
      apellido:      '',
      nombre:        '',
      fecha_llegada: '',
      crs_no:        '',
      temp_path:     '',
      status:        'procesando',
      saved:         false,
    });
    renderRow(index);

    const fd = new FormData();
    fd.append('reg_card', file);

    try {
      const resp = await fetch('carga_masiva_ocr.php', { method: 'POST', body: fd });
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      const data = await resp.json();

      rows[index].apellido      = data.apellido      || '';
      rows[index].nombre        = data.nombre        || '';
      rows[index].fecha_llegada = data.fecha_llegada || '';
      rows[index].crs_no        = data.crs_no        || '';
      rows[index].temp_path     = data.temp_path     || '';
      rows[index].status        = data.status || 'error';

    } catch (e) {
      rows[index].status = 'error';
      rows[index].errorMsg = e.message;
    }

    counters[rows[index].status] = (counters[rows[index].status] || 0) + 1;
    updateBadges();
    renderRow(index);
  }

  // ── Render ────────────────────────────────────────────────────────────────
  function renderRow(index) {
    const r = rows[index];
    const existing = document.getElementById('row-' + index);
    const tr = existing || document.createElement('tr');
    tr.id = 'row-' + index;

    const statusBadge = statusBadgeHtml(r.status);
    const canSelect   = (r.status === 'listo' || r.status === 'incompleto') && !r.saved;
    const checkedAttr = r.status === 'listo' && !r.saved ? 'checked' : '';
    const savedClass  = r.saved ? 'table-success' : '';

    tr.className = savedClass;

    tr.innerHTML = `
      <td>
        ${canSelect
          ? `<input type="checkbox" class="form-check-input row-check" data-idx="${index}" ${checkedAttr}>`
          : ''}
      </td>
      <td style="font-size:0.8rem;color:#555;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
          title="${esc(r.filename)}">${esc(r.filename)}</td>
      <td>${r.status === 'procesando' ? '<span class="text-muted">…</span>' : esc(r.apellido) || '<span class="text-muted">—</span>'}</td>
      <td>${r.status === 'procesando' ? '' : esc(r.nombre)    || '<span class="text-muted">—</span>'}</td>
      <td>${r.status === 'procesando' ? '' : esc(r.fecha_llegada) || '<span class="text-muted">—</span>'}</td>
      <td>${r.status === 'procesando' ? '' : esc(r.crs_no)    || '<span class="text-muted">—</span>'}</td>
      <td>${r.saved ? '<span class="badge bg-success">Guardado</span>' : statusBadge}</td>
      <td>
        ${!r.saved && r.status !== 'procesando'
          ? `<button class="btn btn-outline-secondary btn-sm btn-revisar" data-idx="${index}">Revisar</button>`
          : ''}
      </td>`;

    if (!existing) resultsTbody.appendChild(tr);
  }

  function statusBadgeHtml(status) {
    const map = {
      procesando:  '<span class="badge bg-secondary">Procesando…</span>',
      listo:       '<span class="badge bg-success">Listo</span>',
      incompleto:  '<span class="badge bg-warning text-dark">Incompleto</span>',
      error:       '<span class="badge bg-danger">Error</span>',
    };
    return map[status] || '<span class="badge bg-secondary">' + esc(status) + '</span>';
  }

  function updateBadges() {
    badgeListo.textContent      = counters.listo      + ' Listos';
    badgeIncompleto.textContent = counters.incompleto + ' Incompletos';
    badgeError.textContent      = counters.error      + ' Errores';
  }

  function esc(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ── Revisar modal ─────────────────────────────────────────────────────────
  resultsTbody.addEventListener('click', function (e) {
    const btn = e.target.closest('.btn-revisar');
    if (!btn) return;
    const idx = parseInt(btn.dataset.idx, 10);
    const r   = rows[idx];
    document.getElementById('modal-row-index').value = idx;
    document.getElementById('modal-filename').textContent = r.filename;
    document.getElementById('modal-apellido').value = r.apellido;
    document.getElementById('modal-nombre').value   = r.nombre;
    document.getElementById('modal-fecha').value    = r.fecha_llegada;
    document.getElementById('modal-crs').value      = r.crs_no;
    document.getElementById('modal-error').style.display = 'none';
    getModal().show();
  });

  document.getElementById('modal-guardar').addEventListener('click', function () {
    const idx      = parseInt(document.getElementById('modal-row-index').value, 10);
    const apellido = document.getElementById('modal-apellido').value.trim();
    const nombre   = document.getElementById('modal-nombre').value.trim();
    const fecha    = document.getElementById('modal-fecha').value.trim();
    const crs      = document.getElementById('modal-crs').value.trim();
    const errEl    = document.getElementById('modal-error');

    if (!apellido || !nombre || !fecha) {
      errEl.textContent = 'Apellido, Nombre y Fecha de llegada son obligatorios.';
      errEl.style.display = 'block';
      return;
    }

    rows[idx].apellido      = apellido;
    rows[idx].nombre        = nombre;
    rows[idx].fecha_llegada = fecha;
    rows[idx].crs_no        = crs;

    // Promote incompleto → listo if all required fields now filled
    if (rows[idx].status === 'incompleto') {
      counters.incompleto = Math.max(0, counters.incompleto - 1);
      rows[idx].status = 'listo';
      counters.listo++;
      updateBadges();
    }

    renderRow(idx);
    getModal().hide();
  });

  // ── Save ──────────────────────────────────────────────────────────────────
  document.getElementById('btn-guardar-seleccionados').addEventListener('click', function () {
    const checked = [...document.querySelectorAll('.row-check:checked')]
      .map(cb => parseInt(cb.dataset.idx, 10));
    saveRows(checked);
  });

  document.getElementById('btn-guardar-todos').addEventListener('click', function () {
    const validIdx = rows
      .filter(r => (r.status === 'listo' || r.status === 'incompleto') && !r.saved)
      .map(r => r.index);
    saveRows(validIdx);
  });

  async function saveRows(indices) {
    if (!indices.length) {
      showSaveResult('warning', 'No hay registros seleccionados.');
      return;
    }

    const payload = indices.map(idx => ({
      apellido:      rows[idx].apellido,
      nombre:        rows[idx].nombre,
      fecha_llegada: rows[idx].fecha_llegada,
      crs_no:        rows[idx].crs_no,
      temp_path:     rows[idx].temp_path,
      _idx:          idx,
    }));

    document.getElementById('btn-guardar-seleccionados').disabled = true;
    document.getElementById('btn-guardar-todos').disabled = true;

    try {
      const resp = await fetch('carga_masiva_guardar.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(payload),
      });
      const data = await resp.json();

      // Mark saved rows
      (data.results || []).forEach(function (result) {
        // result.index is position within the payload array
        const payloadRow = payload[result.index];
        if (payloadRow && result.success) {
          rows[payloadRow._idx].saved = true;
          renderRow(payloadRow._idx);
        }
      });

      if (data.saved > 0) {
        showSaveResult('success',
          data.saved + ' registro(s) guardado(s) correctamente.' +
          (data.skipped > 0 ? ' ' + data.skipped + ' omitido(s) por error.' : '')
        );
      } else {
        showSaveResult('danger', 'No se pudo guardar ningún registro. Revisa los datos e intenta de nuevo.');
      }

    } catch (e) {
      showSaveResult('danger', 'Error de comunicación: ' + e.message);
    }

    document.getElementById('btn-guardar-seleccionados').disabled = false;
    document.getElementById('btn-guardar-todos').disabled = false;
  }

  function showSaveResult(type, msg) {
    saveResult.className = 'alert alert-' + type + ' mt-3';
    saveResult.textContent = msg;
    saveResult.style.display = 'block';
  }

})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
