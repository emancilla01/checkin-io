/**
 * initPdfViewer(container, url)
 *
 * Renders a PDF file into `container` using PDF.js canvas rendering.
 * Replaces the native <iframe> PDF viewer, which fails on Safari/iOS for
 * FPDI-generated PDFs (Form XObject page structure).
 *
 * Requirements:
 *   - pdf.min.js (PDF.js) must be loaded before this script.
 *   - PDF_WORKER_SRC constant is set below to match the CDN version.
 *
 * @param {HTMLElement} container  Scrollable wrapper element. Will be cleared and populated.
 * @param {string}      url        Relative or absolute URL of the PDF. Empty string = show empty state.
 */

(function (global) {
    'use strict';

    var WORKER_SRC = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

    function initPdfViewer(container, url) {
        _clear(container);

        if (!url) {
            return; // Caller handles the empty-state UI outside the viewer div
        }

        if (!global.pdfjsLib) {
            _message(container, 'Error interno: PDF.js no cargado.');
            return;
        }

        global.pdfjsLib.GlobalWorkerOptions.workerSrc = WORKER_SRC;

        _message(container, 'Cargando documento…');

        var loadingTask = global.pdfjsLib.getDocument(url);

        loadingTask.promise.then(function (pdf) {
            _clear(container);

            var total = pdf.numPages;

            // Render pages sequentially to avoid memory spikes on iPad.
            // Each page waits for the previous render to complete.
            var chain = Promise.resolve();
            for (var i = 1; i <= total; i++) {
                chain = chain.then(_renderPage.bind(null, pdf, i, container, total));
            }

            chain.catch(function (err) {
                _message(container, 'Error al renderizar: ' + (err.message || String(err)));
            });

        }).catch(function (err) {
            _message(container, 'No se pudo cargar el documento.');
            console.error('[pdf-viewer]', err);
        });
    }

    function _renderPage(pdf, pageNum, container, total) {
        return pdf.getPage(pageNum).then(function (page) {
            // Fit page to the container's current CSS width.
            // Cap dpr at 2 to keep memory reasonable on older iPads.
            var containerW = container.clientWidth || 700;
            var dpr        = Math.min(window.devicePixelRatio || 1, 2);
            var baseVp     = page.getViewport({ scale: 1 });
            var cssScale   = containerW / baseVp.width;
            var physVp     = page.getViewport({ scale: cssScale * dpr });

            var canvas       = document.createElement('canvas');
            canvas.width     = Math.round(physVp.width);
            canvas.height    = Math.round(physVp.height);
            // CSS dimensions in logical pixels so the canvas fills the width
            // and its height is proportional without stretching.
            canvas.style.display = 'block';
            canvas.style.width   = containerW + 'px';
            canvas.style.height  = Math.round(physVp.height / dpr) + 'px';

            // Small gap between pages (not after the last one)
            if (pageNum < total) {
                canvas.style.marginBottom = '4px';
            }

            container.appendChild(canvas);

            return page.render({
                canvasContext: canvas.getContext('2d'),
                viewport:      physVp,
            }).promise;
        });
    }

    function _clear(container) {
        container.innerHTML = '';
    }

    function _message(container, text) {
        container.innerHTML =
            '<div style="color:#ccc;padding:24px;text-align:center;font-size:0.875rem;">'
            + _esc(text) + '</div>';
    }

    function _esc(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    global.initPdfViewer = initPdfViewer;

}(window));
