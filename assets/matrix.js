console.log("KLXM Matrix JS loaded!");

$(document).on('change', '.klxm-matrix-checkbox', function () {
    const itemType = this.dataset.itemType;
    const itemId = this.dataset.itemId;
    const roleId = parseInt(this.dataset.roleId, 10);
    const state = this.checked ? 1 : 0;
    const tr = $(this).closest('tr');

    // Pseudo-roles logic (only one explicit state per row makes sense)
    const ROLE_PUBLIC = 1000000;
    const ROLE_LOGGED_IN = 1000001;
    const ROLE_GUEST = 1000002;

    if (this.checked) {
        if ([ROLE_PUBLIC, ROLE_LOGGED_IN, ROLE_GUEST].includes(roleId)) {
            // Uncheck all other checkboxes in the same row
            tr.find('.klxm-matrix-checkbox').not(this).each(function () {
                if (this.checked) {
                    if (!this.disabled) {
                        $(this).prop('checked', false).trigger('change');
                    } else {
                        // Just visually clear inherited ones
                        $(this).prop('checked', false);
                    }
                }
            });
        } else {
            // If a specific group is checked, uncheck pseudo roles automatically
            tr.find('.klxm-matrix-checkbox').each(function () {
                const rId = parseInt(this.dataset.roleId, 10);
                if (this.checked && [ROLE_PUBLIC, ROLE_LOGGED_IN, ROLE_GUEST].includes(rId)) {
                    if (!this.disabled) {
                        $(this).prop('checked', false).trigger('change');
                    } else {
                        // Just visually clear inherited ones
                        $(this).prop('checked', false);
                    }
                }
            });
        }
    }

    tr.addClass('rex-ajax-loader');

    const formData = new FormData();
    formData.append('item_type', itemType);
    formData.append('item_id', itemId);
    formData.append('role_id', roleId);
    formData.append('state', state);
    
    // Füge CSRF-Token hinzu, das wir im View gerendert haben
    if (typeof klxm_matrix_csrf !== 'undefined') {
        formData.append('_csrf_token', klxm_matrix_csrf);
    }

    fetch('index.php?rex-api-call=klxm_restricted_matrix_update', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        tr.removeClass('rex-ajax-loader');
        if (data && data.error) {
            alert('Fehler beim Speichern der Rechte: ' + data.error);
            this.checked = !this.checked; // Revert
        } else {
            // Nach erfolgreichem Speichern per pjax relaoden um die Vererbungen visuell korrekt darzustellen
            if (typeof $.pjax !== 'undefined') {
                $.pjax.reload('#rex-js-page-main', {url: window.location.href, push: false});
            } else {
                location.reload();
            }
        }
    })
    .catch(error => {
        tr.removeClass('rex-ajax-loader');
        console.error('Error:', error);
        alert('Netzwerkfehler beim Speichern.');
        this.checked = !this.checked; // Revert
    });
});

$(document).on('change', '.klxm-request-checkbox', function () {
    const itemType = this.dataset.itemType;
    const itemId = this.dataset.itemId;
    const roleId = parseInt(this.dataset.roleId, 10);
    const state = this.checked ? 1 : 0;
    const tr = $(this).closest('tr');

    tr.addClass('rex-ajax-loader');

    const formData = new FormData();
    formData.append('item_type', itemType);
    formData.append('item_id', itemId);
    formData.append('role_id', roleId);
    formData.append('state', state);

    if (typeof klxm_matrix_csrf !== 'undefined') {
        formData.append('_csrf_token', klxm_matrix_csrf);
    }

    fetch('index.php?rex-api-call=klxm_restricted_matrix_update', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        tr.removeClass('rex-ajax-loader');
        if (data && data.error) {
            alert('Fehler beim Speichern der Anfrage-Option: ' + data.error);
            this.checked = !this.checked;
        } else {
            if (typeof $.pjax !== 'undefined') {
                $.pjax.reload('#rex-js-page-main', {url: window.location.href, push: false});
            } else {
                location.reload();
            }
        }
    })
    .catch(error => {
        tr.removeClass('rex-ajax-loader');
        console.error('Error:', error);
        alert('Netzwerkfehler beim Speichern.');
        this.checked = !this.checked;
    });
});

