(function () {
    function toSeries(rawList) {
        if (!Array.isArray(rawList)) {
            return { labels: [], values: [] };
        }

        var labels = [];
        var values = [];

        rawList.forEach(function (entry) {
            if (!entry || typeof entry !== 'object') {
                return;
            }

            var label = String(entry.label || '').trim();
            var value = Number(entry.value || 0);
            if (label === '') {
                return;
            }

            labels.push(label);
            values.push(isFinite(value) ? value : 0);
        });

        return { labels: labels, values: values };
    }

    function createBarOption(title, labels, values, color) {
        return {
            animationDuration: 300,
            grid: {
                left: 24,
                right: 16,
                top: 36,
                bottom: 60,
                containLabel: true
            },
            tooltip: {
                trigger: 'axis',
                axisPointer: { type: 'shadow' }
            },
            title: {
                text: title,
                left: 0,
                textStyle: {
                    fontSize: 13,
                    fontWeight: 600
                }
            },
            xAxis: {
                type: 'category',
                data: labels,
                axisLabel: {
                    interval: 0,
                    rotate: labels.length > 6 ? 28 : 0,
                    fontSize: 11
                }
            },
            yAxis: {
                type: 'value',
                minInterval: 1
            },
            series: [
                {
                    type: 'bar',
                    data: values,
                    itemStyle: {
                        color: color || '#2a7de1'
                    },
                    barMaxWidth: 34
                }
            ]
        };
    }

    function createPieOption(title, labels, values) {
        var colors = ['#2d8cf0', '#18a058', '#f0a020', '#c678dd', '#e85d75'];
        var pieData = labels.map(function (label, index) {
            return {
                name: label,
                value: values[index] || 0,
                itemStyle: { color: colors[index % colors.length] }
            };
        });

        return {
            animationDuration: 300,
            tooltip: {
                trigger: 'item',
                formatter: '{b}: {c} ({d}%)'
            },
            title: {
                text: title,
                left: 0,
                textStyle: {
                    fontSize: 13,
                    fontWeight: 600
                }
            },
            legend: {
                bottom: 0,
                left: 'center'
            },
            series: [
                {
                    type: 'pie',
                    radius: ['38%', '68%'],
                    center: ['50%', '48%'],
                    avoidLabelOverlap: true,
                    label: {
                        formatter: '{b}: {c}'
                    },
                    data: pieData
                }
            ]
        };
    }

    function createTrendOption(title, labels, values) {
        return {
            animationDuration: 300,
            grid: {
                left: 24,
                right: 20,
                top: 36,
                bottom: 40,
                containLabel: true
            },
            tooltip: {
                trigger: 'axis'
            },
            title: {
                text: title,
                left: 0,
                textStyle: {
                    fontSize: 13,
                    fontWeight: 600
                }
            },
            xAxis: {
                type: 'category',
                data: labels,
                axisLabel: {
                    rotate: labels.length > 12 ? 35 : 0,
                    fontSize: 11
                }
            },
            yAxis: {
                type: 'value',
                minInterval: 1
            },
            series: [
                {
                    type: 'line',
                    smooth: true,
                    data: values,
                    symbolSize: 7,
                    lineStyle: {
                        width: 3,
                        color: '#f0a020'
                    },
                    itemStyle: {
                        color: '#d98400'
                    },
                    areaStyle: {
                        color: 'rgba(240, 160, 32, 0.20)'
                    }
                }
            ]
        };
    }

    function renderChart(elementId, title, seriesData) {
        var element = document.getElementById(elementId);
        if (!element || !window.echarts) {
            return null;
        }

        if (!seriesData || seriesData.labels.length === 0) {
            element.innerHTML = '<p class="text-muted" style="margin:6px 0 0;">Keine Daten vorhanden.</p>';
            return null;
        }

        var chart = window.echarts.init(element);
        chart.setOption(createBarOption(title, seriesData.labels, seriesData.values, '#2d8cf0'));
        return chart;
    }

    function renderPieChart(elementId, title, seriesData) {
        var element = document.getElementById(elementId);
        if (!element || !window.echarts) {
            return null;
        }

        if (!seriesData || seriesData.labels.length === 0) {
            element.innerHTML = '<p class="text-muted" style="margin:6px 0 0;">Keine Daten vorhanden.</p>';
            return null;
        }

        var chart = window.echarts.init(element);
        chart.setOption(createPieOption(title, seriesData.labels, seriesData.values));
        return chart;
    }

    function renderTrendChart(elementId, title, seriesData) {
        var element = document.getElementById(elementId);
        if (!element || !window.echarts) {
            return null;
        }

        if (!seriesData || seriesData.labels.length === 0) {
            element.innerHTML = '<p class="text-muted" style="margin:6px 0 0;">Keine Daten vorhanden.</p>';
            return null;
        }

        var chart = window.echarts.init(element);
        chart.setOption(createTrendOption(title, seriesData.labels, seriesData.values));
        return chart;
    }

    function initTopFilesFilter() {
        var filters = document.querySelectorAll('.klxm-top-files-filter');
        if (!filters.length) {
            return;
        }

        Array.prototype.forEach.call(filters, function (input) {
            input.addEventListener('input', function () {
                var needle = String(input.value || '').toLowerCase().trim();
                var targetId = input.getAttribute('data-target') || '';
                if (targetId === '') {
                    return;
                }

                var root = document.getElementById(targetId);
                if (!root) {
                    return;
                }

                var panels = root.querySelectorAll('.klxm-top-files-panel');
                Array.prototype.forEach.call(panels, function (panel) {
                    var rows = panel.querySelectorAll('.klxm-top-file-row');
                    var visibleRows = 0;

                    Array.prototype.forEach.call(rows, function (row) {
                        var haystack = String(row.getAttribute('data-search') || '').toLowerCase();
                        var match = needle === '' || haystack.indexOf(needle) !== -1;
                        row.style.display = match ? '' : 'none';
                        if (match) {
                            visibleRows++;
                        }
                    });

                    panel.style.display = visibleRows > 0 ? '' : 'none';
                });
            });
        });
    }

    function initPreviewModal() {
        var modal = document.getElementById('klxm-preview-modal');
        if (!modal) {
            return;
        }

        var fallbackBackdrop = null;
        var closeFallbackModal = function () {
            modal.style.display = 'none';
            modal.classList.remove('in');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open');
            if (fallbackBackdrop && fallbackBackdrop.parentNode) {
                fallbackBackdrop.parentNode.removeChild(fallbackBackdrop);
            }
            fallbackBackdrop = null;
        };

        var showFallbackModal = function () {
            if (modal.classList.contains('in') || modal.style.display === 'block') {
                return;
            }
            modal.style.display = 'block';
            modal.classList.add('in');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');

            fallbackBackdrop = document.querySelector('.modal-backdrop');
            if (!fallbackBackdrop) {
                fallbackBackdrop = document.createElement('div');
                fallbackBackdrop.className = 'modal-backdrop fade in';
                document.body.appendChild(fallbackBackdrop);
            }
            fallbackBackdrop.addEventListener('click', closeFallbackModal);
        };

        var modalTitle = document.getElementById('klxm-preview-modal-label');
        var imageWrap = document.getElementById('klxm-preview-modal-image-wrap');
        var imageEl = document.getElementById('klxm-preview-modal-image');
        var fileWrap = document.getElementById('klxm-preview-modal-file-wrap');
        var fileTitle = document.getElementById('klxm-preview-modal-file-title');
        var fileName = document.getElementById('klxm-preview-modal-filename');
        var openLink = document.getElementById('klxm-preview-modal-open');

        modal.addEventListener('click', function (event) {
            var dismiss = event.target.closest('[data-dismiss="modal"]');
            if (dismiss && (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.modal)) {
                event.preventDefault();
                closeFallbackModal();
            }
        });

        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('.klxm-preview-trigger');
            if (!trigger) {
                return;
            }

            event.preventDefault();

            var url = String(trigger.getAttribute('data-preview-url') || '');
            var title = String(trigger.getAttribute('data-preview-title') || 'Dateivorschau');
            var filename = String(trigger.getAttribute('data-preview-filename') || '');
            var isImage = String(trigger.getAttribute('data-preview-image') || '0') === '1';

            if (modalTitle) {
                modalTitle.textContent = title;
            }
            if (openLink) {
                openLink.setAttribute('href', url);
            }

            if (isImage) {
                if (imageEl) {
                    imageEl.setAttribute('src', url);
                    imageEl.setAttribute('alt', title);
                }
                if (imageWrap) {
                    imageWrap.style.display = '';
                }
                if (fileWrap) {
                    fileWrap.style.display = 'none';
                }
            } else {
                if (fileTitle) {
                    fileTitle.textContent = title;
                }
                if (fileName) {
                    fileName.textContent = filename;
                }
                if (imageWrap) {
                    imageWrap.style.display = 'none';
                }
                if (fileWrap) {
                    fileWrap.style.display = '';
                }
            }

            if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
                window.jQuery(modal).modal('show');
                window.setTimeout(function () {
                    var isShown = modal.classList.contains('in') || modal.style.display === 'block';
                    if (!isShown) {
                        showFallbackModal();
                    }
                }, 120);
            } else {
                showFallbackModal();
            }
        });
    }

    function init() {
        initTopFilesFilter();
        initPreviewModal();

        if (!window.echarts) {
            return;
        }

        var dataNode = document.getElementById('klxm-share-requests-chart-data');
        if (!dataNode) {
            return;
        }

        var parsed;
        try {
            parsed = JSON.parse(dataNode.textContent || '{}');
        } catch (error) {
            return;
        }

        var topShares = toSeries(parsed.topShares);
        var modes = toSeries(parsed.downloadModes);
        var trend = toSeries(parsed.dailyTrend);

        var charts = [];
        var chartA = renderChart('klxm-share-chart-top-shares', 'Datei-Downloads pro Freigabe', topShares);
        var chartB = renderPieChart('klxm-share-chart-modes', 'Downloadarten', modes);
        var chartC = renderTrendChart('klxm-share-chart-trend', 'Download-Trend', trend);

        if (chartA) {
            charts.push(chartA);
        }
        if (chartB) {
            charts.push(chartB);
        }
        if (chartC) {
            charts.push(chartC);
        }

        if (charts.length === 0) {
            return;
        }

        window.addEventListener('resize', function () {
            charts.forEach(function (chart) {
                chart.resize();
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
