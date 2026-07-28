(function () {
    function appendCategorizedGroup(container, template) {
        if (!container || !template) {
            return false;
        }

        var nextIndex = Number(container.getAttribute('data-next-index') || container.querySelectorAll('.klxm-categorized-group').length || 0);
        if (!isFinite(nextIndex) || nextIndex < 0) {
            nextIndex = container.querySelectorAll('.klxm-categorized-group').length;
        }

        var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex));
        container.setAttribute('data-next-index', String(nextIndex + 1));

        var wrap = document.createElement('div');
        wrap.innerHTML = html;
        var group = wrap.firstElementChild;
        if (!group) {
            return false;
        }

        container.appendChild(group);
        return group;
    }

    function initSelectpickers(root) {
        if (!window.jQuery || typeof window.jQuery.fn.selectpicker !== 'function') {
            return;
        }

        var $root = root ? window.jQuery(root) : window.jQuery(document);
        var $selects = $root.find('select.selectpicker');
        if (root && root.matches && root.matches('select.selectpicker')) {
            $selects = $selects.add(root);
        }

        $selects.each(function () {
            var $select = window.jQuery(this);
            if ($select.data('selectpicker')) {
                $select.selectpicker('refresh');
            } else {
                $select.selectpicker();
            }
        });
    }

    function toggleManualFields() {
        var mode = document.getElementById('source_mode');
        if (!mode) {
            return;
        }

        var manualElements = document.querySelectorAll('[data-file-share-manual]');
        var isManual = mode.value === 'manual';

        manualElements.forEach(function (el) {
            el.style.display = isManual ? '' : 'none';
        });
    }

    function toggleShareModeFields() {
        var mode = document.getElementById('share_mode');
        if (!mode) {
            return;
        }

        var articleBlocks = document.querySelectorAll('[data-share-mode-article]');
        var isArticle = mode.value === 'article';
        articleBlocks.forEach(function (el) {
            el.style.display = isArticle ? '' : 'none';
        });
    }

    function createRequestRow() {
        var wrapper = document.createElement('div');
        wrapper.className = 'row klxm-request-row';
        wrapper.style.marginBottom = '8px';
        wrapper.innerHTML = ''
            + '<div class="col-sm-2"><input class="form-control" type="text" name="request_field_key[]" placeholder="key z.B. firma"></div>'
            + '<div class="col-sm-3"><input class="form-control" type="text" name="request_field_label[]" placeholder="Label z.B. Firma"></div>'
            + '<div class="col-sm-2">'
            + '  <select class="form-control" name="request_field_type[]">'
            + '    <option value="text">Text</option>'
            + '    <option value="textarea">Freitext</option>'
            + '    <option value="checkbox">Checkbox</option>'
            + '    <option value="select">Select</option>'
            + '    <option value="radio">Radio</option>'
            + '    <option value="rating">Rating (Sterne)</option>'
            + '  </select>'
            + '</div>'
            + '<div class="col-sm-3"><input class="form-control" type="text" name="request_field_options[]" placeholder="Optionen fuer Select"></div>'
            + '<div class="col-sm-1">'
            + '  <select class="form-control" name="request_field_required[]">'
            + '    <option value="0">Optional</option>'
            + '    <option value="1">Pflicht</option>'
            + '  </select>'
            + '</div>'
            + '<div class="col-sm-1"><button type="button" class="btn btn-default klxm-request-remove">-</button></div>';

        return wrapper;
    }

    function bindRequestBuilder() {
        document.addEventListener('click', function (event) {
            var addButton = event.target.closest('#klxm-request-add-row');
            if (addButton) {
                var container = document.getElementById('klxm-request-fields');
                if (container) {
                    container.appendChild(createRequestRow());
                }
                return;
            }

            var removeButton = event.target.closest('.klxm-request-remove');
            if (!removeButton) {
                return;
            }

            var row = removeButton.closest('.klxm-request-row');
            if (!row) {
                return;
            }

            var containerRef = document.getElementById('klxm-request-fields');
            if (containerRef && containerRef.querySelectorAll('.klxm-request-row').length <= 1) {
                row.querySelectorAll('input, select, textarea').forEach(function (field) {
                    if (field.type === 'checkbox') {
                        field.checked = false;
                    } else {
                        field.value = '';
                    }
                });
                return;
            }

            row.remove();
        });
    }

    function bindFileLimitBuilder() {
        var container = document.getElementById('klxm-file-limit-rows');
        if (!container) {
            return;
        }

        var template = document.getElementById('klxm-file-limit-template');
        var initialCount = container.querySelectorAll('.klxm-file-limit-row').length;
        container.setAttribute('data-next-index', String(initialCount));

        document.addEventListener('click', function (event) {
            var addButton = event.target.closest('#klxm-file-limit-add');
            if (addButton) {
                if (!template) {
                    return;
                }

                var nextIndex = Number(container.getAttribute('data-next-index') || container.querySelectorAll('.klxm-file-limit-row').length || 0);
                if (!isFinite(nextIndex) || nextIndex < 0) {
                    nextIndex = container.querySelectorAll('.klxm-file-limit-row').length;
                }

                var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex));
                container.setAttribute('data-next-index', String(nextIndex + 1));

                var wrapper = document.createElement('div');
                wrapper.innerHTML = html;
                var row = wrapper.firstElementChild;
                if (!row) {
                    return;
                }

                container.appendChild(row);
                return;
            }

            var removeButton = event.target.closest('.klxm-file-limit-remove');
            if (!removeButton) {
                return;
            }

            var rowRef = removeButton.closest('.klxm-file-limit-row');
            if (!rowRef) {
                return;
            }

            if (container.querySelectorAll('.klxm-file-limit-row').length <= 1) {
                rowRef.querySelectorAll('input, select, textarea').forEach(function (field) {
                    if (field.type === 'checkbox') {
                        field.checked = false;
                    } else {
                        field.value = '';
                    }
                });
                rowRef.querySelectorAll('input[id^="REX_MEDIALIST_"]').forEach(function (hiddenField) {
                    hiddenField.value = '';
                });
                rowRef.querySelectorAll('select[id^="REX_MEDIALIST_SELECT_"]').forEach(function (listSelect) {
                    while (listSelect.options.length > 0) {
                        listSelect.remove(0);
                    }
                });
                return;
            }

            rowRef.remove();
        });
    }

    function toggleCategorizedGroupSource(group) {
        if (!group) {
            return;
        }

        var sourceSelect = group.querySelector('.klxm-categorized-source');
        var manualBlock = group.querySelector('.klxm-categorized-manual-block');
        var categoryBlock = group.querySelector('.klxm-categorized-category-block');
        if (!sourceSelect || !manualBlock || !categoryBlock) {
            return;
        }

        var useCategory = sourceSelect.value === 'media_category';
        manualBlock.style.display = useCategory ? 'none' : '';
        categoryBlock.style.display = useCategory ? '' : 'none';
    }

    function bindCategorizedRepeater() {
        var container = document.getElementById('klxm-categorized-groups');
        if (!container) {
            return;
        }

        var template = document.getElementById('klxm-categorized-template');
        container.setAttribute('data-next-index', String(container.querySelectorAll('.klxm-categorized-group').length));

        container.querySelectorAll('.klxm-categorized-group').forEach(function (group) {
            toggleCategorizedGroupSource(group);
        });
        initSelectpickers(container);

        document.addEventListener('change', function (event) {
            var source = event.target.closest('.klxm-categorized-source');
            if (!source) {
                return;
            }

            toggleCategorizedGroupSource(source.closest('.klxm-categorized-group'));
        });

        document.addEventListener('click', function (event) {
            var removeButton = event.target.closest('.klxm-categorized-remove');
            if (removeButton) {
                var row = removeButton.closest('.klxm-categorized-group');
                if (!row) {
                    return;
                }

                if (container.querySelectorAll('.klxm-categorized-group').length <= 1) {
                    row.querySelectorAll('input[type="text"], textarea').forEach(function (field) {
                        field.value = '';
                    });
                    row.querySelectorAll('select').forEach(function (select) {
                        select.selectedIndex = 0;
                    });
                    row.querySelectorAll('input[id^="REX_MEDIALIST_"]').forEach(function (hiddenField) {
                        hiddenField.value = '';
                    });
                    row.querySelectorAll('select[id^="REX_MEDIALIST_SELECT_"]').forEach(function (listSelect) {
                        while (listSelect.options.length > 0) {
                            listSelect.remove(0);
                        }
                    });
                    toggleCategorizedGroupSource(row);
                    initSelectpickers(row);
                    return;
                }

                row.remove();
                return;
            }

            var add = event.target.closest('#klxm-categorized-add');
            if (add) {
                return;
            }
        });

        if (!window.__klxmCategorizedAddCaptureBound) {
            window.__klxmCategorizedAddCaptureBound = true;
            document.addEventListener('click', function (event) {
                var add = event.target.closest('#klxm-categorized-add');
                if (!add) {
                    return;
                }

                var liveContainer = document.getElementById('klxm-categorized-groups');
                var liveTemplate = document.getElementById('klxm-categorized-template');
                if (!liveContainer || !liveTemplate) {
                    return;
                }

                event.preventDefault();
                var group = appendCategorizedGroup(liveContainer, liveTemplate);
                if (!group) {
                    return;
                }

                toggleCategorizedGroupSource(group);
                initSelectpickers(group);
            }, true);
        }
    }

    function bindStatsDetailLinks() {
        document.addEventListener('click', function (event) {
            var link = event.target.closest('.klxm-stats-detail-link[data-open-parent="1"]');
            if (!link) {
                return;
            }

            var url = link.getAttribute('href') || '';
            if (url === '') {
                return;
            }

            if (window.opener && !window.opener.closed) {
                event.preventDefault();
                window.opener.location.href = url;
                window.opener.focus();
                // In popup context we return control to the parent and close this helper window.
                window.setTimeout(function () {
                    window.close();
                }, 60);
                return;
            }

            if (window.parent && window.parent !== window) {
                event.preventDefault();
                window.parent.location.href = url;
            }
        });
    }

    function init() {
        var mode = document.getElementById('source_mode');
        if (mode) {
            mode.addEventListener('change', toggleManualFields);
            toggleManualFields();
        }

        var shareMode = document.getElementById('share_mode');
        if (shareMode) {
            shareMode.addEventListener('change', toggleShareModeFields);
            toggleShareModeFields();
        }

        bindRequestBuilder();
        bindFileLimitBuilder();
        bindCategorizedRepeater();
        bindStatsDetailLinks();
        initSelectpickers(document);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
