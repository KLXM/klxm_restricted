(function () {
    function copyToClipboard(text) {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', 'readonly');
            textarea.style.position = 'absolute';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();

            try {
                document.execCommand('copy');
                document.body.removeChild(textarea);
                resolve();
            } catch (error) {
                document.body.removeChild(textarea);
                reject(error);
            }
        });
    }

    function attachHandler() {
        document.addEventListener('click', function (event) {
            var button = event.target.closest('.klxm-copy-share-link');
            if (!button) {
                return;
            }

            var targetId = button.getAttribute('data-target') || '';
            var input = document.getElementById(targetId);
            if (!input) {
                return;
            }

            copyToClipboard(input.value).then(function () {
                var original = button.textContent;
                button.textContent = 'Kopiert';
                setTimeout(function () {
                    button.textContent = original;
                }, 1200);
            }).catch(function () {
                input.focus();
                input.select();
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachHandler);
    } else {
        attachHandler();
    }
})();
