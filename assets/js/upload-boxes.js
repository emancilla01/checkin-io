// Drag-and-drop upload box behaviour.
// Looks for [data-upload-box] elements. Each must have:
//   data-upload-input="#inputId"  -- CSS selector for the linked <input type="file">
// and contain a [data-upload-filename] element for the display label.

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-upload-box]').forEach(function (box) {
        var inputSel = box.getAttribute('data-upload-input');
        var input    = document.querySelector(inputSel);
        var label    = box.querySelector('[data-upload-filename]');
        if (!input || !label) return;

        var defaultText = label.textContent;

        function updateLabel() {
            if (input.files && input.files.length > 0) {
                var names = Array.from(input.files).map(function (f) { return f.name; });
                label.textContent = names.join(', ');
                box.classList.add('has-file');
            } else {
                label.textContent = defaultText;
                box.classList.remove('has-file');
            }
        }

        // Click / keyboard open file picker
        box.addEventListener('click', function (e) {
            if (e.target === input) return;
            input.click();
        });
        box.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
        });

        // Drag and drop
        box.addEventListener('dragover', function (e) {
            e.preventDefault();
            box.classList.add('drag-over');
        });
        box.addEventListener('dragleave', function () {
            box.classList.remove('drag-over');
        });
        box.addEventListener('drop', function (e) {
            e.preventDefault();
            box.classList.remove('drag-over');
            if (e.dataTransfer && e.dataTransfer.files.length) {
                // Transfer files to the real input via DataTransfer
                try {
                    var dt = new DataTransfer();
                    Array.from(e.dataTransfer.files).forEach(function (f) { dt.items.add(f); });
                    input.files = dt.files;
                } catch (_) {}
            }
            // Dispatch 'change' so external listeners (e.g. button enablement) are notified.
            // The 'change' listener below will call updateLabel().
            input.dispatchEvent(new Event('change'));
        });

        input.addEventListener('change', updateLabel);
    });
});
