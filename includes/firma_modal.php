<!-- =====================================================================
     Firma Modal — include once per page, just before </body>.
     Trigger buttons must carry:
       data-firma-expid="<int>"
       data-firma-docpath="<relative path e.g. uploads/foo.pdf>"
       data-firma-nombre="<display name for modal title>"
     ===================================================================== -->

<div class="modal fade" id="firmaModal" tabindex="-1"
     aria-labelledby="firmaModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">

      <div class="modal-header" style="background:var(--io-navy); color:#fff;">
        <h5 class="modal-title" id="firmaModalLabel">Firma del documento</h5>
        <button type="button" class="btn-close btn-close-white"
                data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>

      <div class="modal-body p-0">

        <!-- PDF.js canvas viewer (55vh, dark-grey background matching PDF.js viewer convention) -->
        <div id="firmaViewer"
             style="height:55vh; overflow-y:auto; background:#525659; -webkit-overflow-scrolling:touch;">
        </div>

        <div class="p-4">

          <!-- Accept checkbox -->
          <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox" id="firmaAceptar">
            <label class="form-check-label fw-semibold" for="firmaAceptar">
              He leido el documento y acepto sus terminos
            </label>
          </div>

          <!-- Signature canvas -->
          <div class="mb-3">
            <p class="mb-1 fw-semibold" style="font-size:0.9rem; color:var(--io-navy);">
              Firma del huesped
            </p>
            <div id="firmaCanvasWrap"
                 style="border:2px dashed #adb5bd; border-radius:6px; background:#f8f9fa;
                        opacity:0.45; pointer-events:none; transition:opacity .2s;">
              <canvas id="firmaCanvas" style="width:100%; height:200px; display:block;"></canvas>
            </div>
            <p class="text-muted mt-1" style="font-size:0.8rem;">
              Acepta los terminos arriba para habilitar el area de firma.
            </p>
          </div>

          <!-- Error message -->
          <div id="firmaError" class="alert alert-danger d-none" role="alert"></div>

          <!-- Hidden expediente id -->
          <input type="hidden" id="firmaExpedienteId" value="">

        </div>
      </div><!-- /modal-body -->

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary"
                id="firmaLimpiar" disabled>Limpiar firma</button>
        <button type="button" class="btn btn-secondary"
                data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-io-orange"
                id="firmaGuardar" disabled>Guardar firma</button>
      </div>

    </div>
  </div>
</div>

<!-- PDF.js 3.11.174 via CDN (same version as pdf-viewer.js worker) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<!-- Signature Pad v4.0.0 -->
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
<!-- Shared PDF canvas viewer -->
<script src="assets/js/pdf-viewer.js"></script>

<script>
(function () {
    var modal       = document.getElementById('firmaModal');
    var viewer      = document.getElementById('firmaViewer');
    var checkbox    = document.getElementById('firmaAceptar');
    var canvasWrap  = document.getElementById('firmaCanvasWrap');
    var canvas      = document.getElementById('firmaCanvas');
    var errorBox    = document.getElementById('firmaError');
    var btnLimpiar  = document.getElementById('firmaLimpiar');
    var btnGuardar  = document.getElementById('firmaGuardar');
    var expIdInput  = document.getElementById('firmaExpedienteId');

    var pad         = null;
    var pendingUrl  = ''; // set in show.bs.modal, consumed in shown.bs.modal

    function initPad() {
        var ratio = Math.max(window.devicePixelRatio || 1, 1);
        canvas.width  = canvas.offsetWidth  * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        canvas.getContext('2d').scale(ratio, ratio);

        pad = new SignaturePad(canvas, {
            minWidth: 0.5,
            maxWidth: 2.5,
            penColor: '#001f4f'
        });
    }

    // Populate state before the modal animates in
    modal.addEventListener('show.bs.modal', function (e) {
        var btn = e.relatedTarget;
        if (!btn) return;

        pendingUrl       = btn.dataset.firmaDocpath || '';
        expIdInput.value = btn.dataset.firmaExpid   || '';
        document.getElementById('firmaModalLabel').textContent =
            'Firma del documento' + (btn.dataset.firmaNombre ? ' — ' + btn.dataset.firmaNombre : '');

        // Reset UI
        checkbox.checked                 = false;
        errorBox.classList.add('d-none');
        canvasWrap.style.opacity         = '0.45';
        canvasWrap.style.pointerEvents   = 'none';
        btnLimpiar.disabled              = true;
        btnGuardar.disabled              = true;

        // Clear the viewer immediately so old content isn't visible during animation
        viewer.innerHTML = '';
    });

    // After the modal is fully visible: render PDF and init signature pad.
    // Both operations need the elements to be laid out (canvas sizing, viewer width).
    modal.addEventListener('shown.bs.modal', function () {
        // PDF viewer
        initPdfViewer(viewer, pendingUrl);

        // Signature pad
        if (pad) pad.off();
        initPad();
        if (!checkbox.checked) pad.off();
    });

    // Clean up on close
    modal.addEventListener('hidden.bs.modal', function () {
        viewer.innerHTML = '';
        pendingUrl       = '';
        if (pad) { pad.clear(); pad.off(); }
    });

    // Checkbox gates the signature canvas
    checkbox.addEventListener('change', function () {
        if (checkbox.checked) {
            canvasWrap.style.opacity       = '1';
            canvasWrap.style.pointerEvents = 'auto';
            btnLimpiar.disabled            = false;
            if (pad) pad.on();
        } else {
            canvasWrap.style.opacity       = '0.45';
            canvasWrap.style.pointerEvents = 'none';
            btnLimpiar.disabled            = true;
            btnGuardar.disabled            = true;
            if (pad) { pad.clear(); pad.off(); }
        }
        errorBox.classList.add('d-none');
    });

    // Enable Guardar once something is actually drawn
    canvas.addEventListener('pointerup', function () {
        if (pad && !pad.isEmpty() && checkbox.checked) {
            btnGuardar.disabled = false;
        }
    });

    btnLimpiar.addEventListener('click', function () {
        if (pad) pad.clear();
        btnGuardar.disabled = true;
        errorBox.classList.add('d-none');
    });

    btnGuardar.addEventListener('click', function () {
        if (!pad || pad.isEmpty()) {
            showError('Por favor, proporciona una firma antes de guardar.');
            return;
        }
        var expId = expIdInput.value;
        if (!expId) { showError('Error interno: falta el ID del expediente.'); return; }

        var sigData = pad.toDataURL('image/png');

        btnGuardar.disabled        = true;
        btnGuardar.textContent     = 'Guardando…';
        errorBox.classList.add('d-none');

        var fd = new FormData();
        fd.append('expediente_id', expId);
        fd.append('signature', sigData);

        fetch('firma_guardar.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok) {
                    bootstrap.Modal.getInstance(modal).hide();
                    window.location.reload();
                } else {
                    showError(data.error || 'Error desconocido al guardar la firma.');
                    btnGuardar.disabled    = false;
                    btnGuardar.textContent = 'Guardar firma';
                }
            })
            .catch(function () {
                showError('Error de red. Intenta de nuevo.');
                btnGuardar.disabled    = false;
                btnGuardar.textContent = 'Guardar firma';
            });
    });

    function showError(msg) {
        errorBox.textContent = msg;
        errorBox.classList.remove('d-none');
    }
}());
</script>
