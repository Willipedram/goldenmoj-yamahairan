(function () {
    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function format(template, values) {
        return template.replace(/%([0-9]+)\$[ds]/g, function (_, position) {
            var index = parseInt(position, 10) - 1;
            return values[index] !== undefined ? values[index] : '';
        });
    }

    ready(function () {
        var settings = window.yamahairanImporter || {};
        var form = document.getElementById('yamahairan-import-form');
        if (!form || !settings.nonce) {
            return;
        }

        var textarea = document.getElementById('yamahairan_product_urls');
        var statusSelect = document.getElementById('yamahairan_product_status');
        var progress = document.getElementById('yamahairan-progress');
        var progressValue = document.getElementById('yamahairan-progress-value');
        var progressLabel = document.getElementById('yamahairan-progress-label');
        var logBox = document.getElementById('yamahairan-log');
        var logList = document.getElementById('yamahairan-log-list');
        var submitButton = form.querySelector('input[type="submit"], button[type="submit"]');

        function setFormDisabled(disabled) {
            if (textarea) {
                textarea.disabled = disabled;
            }
            if (statusSelect) {
                statusSelect.disabled = disabled;
            }
            if (submitButton) {
                submitButton.disabled = disabled;
                submitButton.classList.toggle('is-busy', disabled);
            }
        }

        function resetLog() {
            if (logList) {
                logList.innerHTML = '';
            }
        }

        function appendLog(message, type) {
            if (!logList) {
                return;
            }

            var item = document.createElement('li');
            if (type) {
                item.classList.add(type);
            }
            item.innerHTML = message;
            logList.appendChild(item);
            logList.scrollTop = logList.scrollHeight;
        }

        function setProgress(percent, label) {
            if (progressValue) {
                progressValue.style.width = Math.min(100, Math.max(0, percent)) + '%';
            }
            if (progressLabel && label) {
                progressLabel.textContent = label;
            }
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var urls = textarea.value
                .split(/\r?\n/)
                .map(function (url) { return url.trim(); })
                .filter(function (url) { return url.length > 0; });

            if (!urls.length) {
                window.alert(settings.i18n && settings.i18n.invalidRequest ? settings.i18n.invalidRequest : 'لطفاً حداقل یک آدرس محصول وارد کنید.');
                return;
            }

            if (progress) {
                progress.hidden = false;
            }
            if (logBox) {
                logBox.hidden = false;
            }
            resetLog();
            setProgress(0, settings.i18n ? settings.i18n.ready : 'آماده برای شروع…');
            setFormDisabled(true);

            var index = 0;
            var total = urls.length;
            var hasErrors = false;

            function processNext() {
                if (index >= total) {
                    setProgress(100, settings.i18n ? settings.i18n.complete : 'همه محصولات با موفقیت منتقل شدند.');
                    appendLog(settings.i18n ? settings.i18n.complete : 'همه محصولات با موفقیت منتقل شدند.', hasErrors ? 'is-error' : 'is-success');
                    setFormDisabled(false);
                    return;
                }

                var currentNumber = index + 1;
                var statusLabel = settings.i18n && settings.i18n.processing ? format(settings.i18n.processing, [currentNumber, total]) : 'در حال پردازش…';
                setProgress((index / total) * 100, statusLabel);

                var formData = new window.FormData();
                formData.append('action', 'yamahairan_import_product');
                formData.append('nonce', settings.nonce);
                formData.append('status', statusSelect.value);
                formData.append('url', urls[index]);

                fetch(settings.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('خطای ارتباط با سرور: ' + response.status);
                        }
                        return response.json();
                    })
                    .then(function (payload) {
                        var data = payload && payload.data ? payload.data : {};
                        var messages = data.messages || [];

                        if (messages.length) {
                            messages.forEach(function (message) {
                                appendLog(message, payload.success ? 'is-success' : 'is-error');
                            });
                        }

                        if (!payload.success) {
                            hasErrors = true;
                        }

                        index += 1;
                        processNext();
                    })
                    .catch(function (error) {
                        hasErrors = true;
                        appendLog(error.message || (settings.i18n ? settings.i18n.error : 'خطایی رخ داد. لطفاً دوباره تلاش کنید.'), 'is-error');
                        index += 1;
                        processNext();
                    });
            }

            processNext();
        });
    });
})();
