/* All-file picker: APK extension and size hints here; authoritative validation on the server. */
(function () {
    'use strict';
    var form = document.getElementById('delicat-apk-upload-form');
    var input = document.getElementById('delicat-apk');
    var status = document.getElementById('delicat-apk-status');
    if (!form || !input || !status) return;
    var button = form.querySelector('[type="submit"]');
    var maximum = form.querySelector('[name="MAX_FILE_SIZE"]');
    var limit = maximum ? Number(maximum.value) : 0;
    var unavailable = button ? button.disabled : false;
    var originalLabel = button ? button.value : '';
    var sending = false;

    function validate() {
        input.setCustomValidity('');
        var file = input.files && input.files[0];
        if (!file) {
            status.textContent = 'Aucun fichier sélectionné.';
            return false;
        }
        var description = file.name + ' · ' + (file.size / (1024 * 1024)).toFixed(2) + ' Mo';
        var error = '';
        // Do not check file.type: APKs may be reported as ZIP, octet-stream, or empty.
        if (!/\.apk$/i.test(file.name)) error = 'Choisissez un fichier .apk ; ZIP et AAB ne sont pas acceptés.';
        else if (file.size < 64) error = 'Le fichier est vide ou incomplet.';
        else if (file.size > limit) error = 'Le fichier dépasse la limite du serveur. Choisissez un APK plus petit ou ajustez les limites PHP.';
        input.setCustomValidity(error);
        status.textContent = description + ' — ' + (error || 'Prêt à téléverser. Le serveur vérifiera le contenu avant publication.');
        return !error;
    }

    input.addEventListener('change', validate);
    form.addEventListener('submit', function (event) {
        if (sending || unavailable || !validate() || !form.checkValidity()) {
            event.preventDefault();
            if (!sending && !unavailable) form.reportValidity();
            return;
        }
        sending = true;
        form.setAttribute('aria-busy', 'true');
        if (button) {
            button.disabled = true;
            button.value = 'Téléversement et validation en cours…';
        }
        status.textContent = 'Transfert et validation en cours. Gardez cette page ouverte jusqu’à la confirmation.';
    });
    // Restore controls when returning after a server error or via back/forward cache.
    window.addEventListener('pageshow', function () {
        sending = false;
        form.removeAttribute('aria-busy');
        if (button) {
            button.disabled = unavailable;
            button.value = originalLabel;
        }
        validate();
    });
    validate();
}());
