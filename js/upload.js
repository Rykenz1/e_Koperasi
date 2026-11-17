/* ==========================================================================
   e-Koperasi - Drag & Drop Image Upload
   Generic component: any element with [data-dropzone] that contains a
   hidden <input type="file"> and a .dropzone-preview becomes a drop target.
   Client-side checks mirror includes/upload_image.php, but the server is
   always the authority.
   ========================================================================== */

const DROPZONE_MAX_BYTES = 5 * 1024 * 1024;
const DROPZONE_ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

// Stops the browser from navigating away when a file misses the dropzone.
['dragover', 'drop'].forEach(type => {
    document.addEventListener(type, event => event.preventDefault());
});

const dropzoneSetters = new WeakMap();

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-dropzone]').forEach(initDropzone);
});

function initDropzone(zone) {
    const input = zone.querySelector('input[type="file"]');
    if (!input) return;

    const img = zone.querySelector('.dropzone-preview img');

    const errorEl = document.createElement('small');
    errorEl.className = 'dropzone-error';
    errorEl.hidden = true;
    zone.insertAdjacentElement('afterend', errorEl);

    let objectUrl = null;

    function showError(message) {
        errorEl.textContent = message;
        errorEl.hidden = false;
    }

    function clearError() {
        errorEl.textContent = '';
        errorEl.hidden = true;
    }

    function releaseObjectUrl() {
        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
            objectUrl = null;
        }
    }

    // Returns true when the file is acceptable and the preview was updated.
    function preview(file) {
        clearError();

        if (!DROPZONE_ALLOWED_TYPES.includes(file.type)) {
            showError('Only JPG, PNG or WEBP images are allowed.');
            return false;
        }
        if (file.size > DROPZONE_MAX_BYTES) {
            showError('Image must be 5 MB or smaller.');
            return false;
        }

        releaseObjectUrl();
        objectUrl = URL.createObjectURL(file);
        img.src = objectUrl;
        zone.classList.add('has-image');
        return true;
    }

    function setImage(url) {
        clearError();
        releaseObjectUrl();
        input.value = '';

        if (url) {
            img.src = url;
            zone.classList.add('has-image');
        } else {
            img.removeAttribute('src');
            zone.classList.remove('has-image');
        }
    }

    dropzoneSetters.set(zone, setImage);

    // Open the file picker. Ignore the synthetic click from input.click(),
    // otherwise the event bubbles back here and recurses.
    zone.addEventListener('click', event => {
        if (event.target === input) return;
        input.click();
    });

    zone.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            input.click();
        }
    });

    input.addEventListener('change', () => {
        if (!input.files || !input.files[0]) return;
        if (!preview(input.files[0])) input.value = '';
    });

    ['dragenter', 'dragover'].forEach(type => {
        zone.addEventListener(type, event => {
            event.preventDefault();
            event.stopPropagation();
            zone.classList.add('dragover');
        });
    });

    zone.addEventListener('dragleave', event => {
        event.preventDefault();
        if (event.relatedTarget && zone.contains(event.relatedTarget)) return;
        zone.classList.remove('dragover');
    });

    zone.addEventListener('drop', event => {
        event.preventDefault();
        event.stopPropagation();
        zone.classList.remove('dragover');

        const files = event.dataTransfer && event.dataTransfer.files;
        if (!files || !files.length) return;

        const file = files[0];
        if (!preview(file)) return;

        // A dropped file never lands in <input> by itself - copy it across.
        const transfer = new DataTransfer();
        transfer.items.add(file);
        input.files = transfer.files;
    });
}

/* Public helper so page scripts can pre-fill an existing photo. */
function setDropzoneImage(target, url) {
    const zone = typeof target === 'string' ? document.getElementById(target) : target;
    if (!zone) return;

    const setter = dropzoneSetters.get(zone);
    if (setter) setter(url);
}
