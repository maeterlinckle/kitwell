/* ==========================================================================
   Barcode scanning.

   Three ways in, all ending at the same lookup:

     1. The device camera. Uses the native BarcodeDetector API where the
        browser has it (Chrome and Edge on Android and desktop). Where it does
        not — Safari, including every iPhone — falls back to the reader in
        barcode.js, which decodes Code 128, Code 39 and QR.
     2. A USB barcode scanner. These act as keyboards: they "type" the code
        and press Enter. The input is focused by default, so this needs no
        special handling beyond keeping focus.
     3. Typing the tag by hand.

   Two scanners live here: the full-page one on /scan, and the small modal one
   attached to any barcode field by templates/partials/scan-button.php. They
   share the decoding, the frame loop and the camera handling — the difference
   is only where a successful read goes.
   ========================================================================== */
(function () {
    'use strict';

    /* --- Decoding -------------------------------------------------------- */

    // The formats we can act on. Kept in step with public/js/barcode.js, which
    // is what reads them when the browser has no reader of its own.
    var FORMATS = ['code_128', 'code_39', 'qr_code'];

    var detector = null;

    if ('BarcodeDetector' in window) {
        window.BarcodeDetector.getSupportedFormats()
            .then(function (formats) {
                var wanted = FORMATS.filter(function (format) {
                    return formats.indexOf(format) !== -1;
                });

                if (wanted.length) {
                    detector = new window.BarcodeDetector({ formats: wanted });
                }
            })
            .catch(function () { detector = null; });
    }

    /**
     * Read one frame. The native detector is asynchronous and ours is not, so
     * both report through the same callback rather than a return value.
     */
    function readFrame(context, canvas, onFound) {
        if (detector) {
            detector.detect(canvas)
                .then(function (codes) {
                    if (codes && codes.length && codes[0].rawValue) onFound(codes[0].rawValue);
                })
                .catch(function () { /* a bad frame is not worth reporting */ });
            return;
        }

        if (!window.AssetBarcode) return;

        var found = window.AssetBarcode.decodeCanvas(context, canvas.width, canvas.height);
        if (found) onFound(found);
    }

    function aimingHint() {
        return detector
            ? 'Point the camera at the barcode or QR code.'
            : 'Point the camera at the barcode or QR code. Hold it steady and fill the frame.';
    }

    /* --- Shared UI ------------------------------------------------------- */

    function setStatus(element, message, tone) {
        if (!element) return;
        element.textContent = message;
        element.className = 'scan-status' + (tone ? ' scan-status-' + tone : '');
    }

    function beep() {
        // A short tone confirms a read without needing to look at the screen.
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            var ctx = new Ctx();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.frequency.value = 1180;
            gain.gain.value = 0.08;
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            setTimeout(function () { osc.stop(); ctx.close(); }, 110);
        } catch (e) { /* silence is fine */ }
    }

    function vibrate() {
        if (navigator.vibrate) navigator.vibrate(60);
    }

    function cameraError(error) {
        if (error && error.name === 'NotAllowedError') {
            return 'Camera permission was refused. Allow it in your browser settings, or type the tag.';
        }

        if (error && error.name === 'NotFoundError') {
            return 'No camera was found. Use a USB scanner, or type the tag.';
        }

        return 'Could not start the camera.';
    }

    function openCamera(video) {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            return Promise.reject({ name: 'NotSupportedError' });
        }

        return navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }
        }).then(function (stream) {
            video.srcObject = stream;
            video.setAttribute('playsinline', 'true');
            return video.play().then(function () { return stream; });
        });
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function slug(value) {
        return String(value || '').toLowerCase().replace(/\s+/g, '-');
    }

    /* --- The full-page scanner ------------------------------------------- */

    (function () {
        var page = document.querySelector('[data-scanner]');
        if (!page) return;

        var video     = page.querySelector('[data-scan-video]');
        var canvas    = document.createElement('canvas');
        var context   = canvas.getContext('2d', { willReadFrequently: true });
        var startBtn  = page.querySelector('[data-scan-start]');
        var stopBtn   = page.querySelector('[data-scan-stop]');
        var statusEl  = page.querySelector('[data-scan-status]');
        var resultEl  = page.querySelector('[data-scan-result]');
        var input     = page.querySelector('[data-scan-input]');
        var mode      = page.getAttribute('data-scan-mode') || 'view';
        var lookupUrl = page.getAttribute('data-lookup-url');

        var stream = null;
        var scanning = false;
        var lastCode = '';
        var lastCodeAt = 0;

        function lookup(code) {
            var now = Date.now();

            // Ignore the same code re-read within a couple of seconds.
            if (code === lastCode && now - lastCodeAt < 2500) return;
            lastCode = code;
            lastCodeAt = now;

            beep();
            vibrate();
            setStatus(statusEl, 'Looking up ' + code + '…');

            fetch(lookupUrl + '?code=' + encodeURIComponent(code), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
                .then(function (response) { return response.json(); })
                .then(function (data) { render(data, code); })
                .catch(function () {
                    setStatus(statusEl, 'Could not reach the server. Check your connection.', 'error');
                });
        }

        function render(data, code) {
            if (!resultEl) return;

            if (!data.found) {
                setStatus(statusEl, data.message || ('No asset matches ' + code), 'error');
                resultEl.innerHTML = '<p class="muted">Nothing found for <span class="mono">'
                    + escapeHtml(code) + '</span>. Check the tag, or search the register.</p>';
                resultEl.hidden = false;
                return;
            }

            setStatus(statusEl, 'Found ' + data.asset.tag, 'ok');

            var html = '<div class="scan-hit">'
                + '<p class="eyebrow mono">' + escapeHtml(data.asset.tag) + '</p>'
                + '<h2>' + escapeHtml(data.asset.name) + '</h2>'
                + '<p class="badge-row">'
                + '<span class="badge status-' + escapeHtml(slug(data.asset.status)) + '">' + escapeHtml(data.asset.status) + '</span>'
                + '<span class="badge">' + escapeHtml(data.asset.condition) + '</span>'
                + (data.asset.location ? '<span class="badge badge-muted">' + escapeHtml(data.asset.location) + '</span>' : '')
                + '</p>';

            if (data.hire) {
                html += '<p class="' + (data.hire.overdue ? 'scan-warn' : 'muted') + '">'
                    + 'Out with ' + escapeHtml(data.hire.hirer) + ', due ' + escapeHtml(data.hire.due)
                    + (data.hire.overdue ? ' — overdue' : '') + '</p>';
            }

            if (data.blocked && !data.hire) {
                html += '<p class="scan-warn">' + escapeHtml(data.blocked) + '</p>';
            }

            html += '<div class="scan-actions">';

            if (mode === 'checkout' && data.can.checkout) {
                html += '<a class="btn btn-primary btn-lg" href="' + escapeHtml(data.checkout_url) + '">Check out</a>';
            }

            if (mode === 'return' && data.can.return) {
                html += '<a class="btn btn-primary btn-lg" href="' + escapeHtml(data.hire.return_url) + '">Book in</a>';
            }

            if (mode === 'maintenance' && data.can.maintenance) {
                html += '<a class="btn btn-primary btn-lg" href="' + escapeHtml(data.maintenance_url) + '">Record work</a>';
            }

            if (mode !== 'checkout' && data.can.checkout) {
                html += '<a class="btn" href="' + escapeHtml(data.checkout_url) + '">Check out</a>';
            }

            if (mode !== 'return' && data.can.return) {
                html += '<a class="btn" href="' + escapeHtml(data.hire.return_url) + '">Book in</a>';
            }

            if (mode !== 'maintenance' && data.can.maintenance) {
                html += '<a class="btn" href="' + escapeHtml(data.maintenance_url) + '">Record work</a>';
            }

            html += '<a class="btn' + (mode === 'view' ? ' btn-primary btn-lg' : '') + '" href="'
                + escapeHtml(data.asset.url) + '">Open asset</a>';
            html += '</div></div>';

            resultEl.innerHTML = html;
            resultEl.hidden = false;
        }

        function tick() {
            if (!scanning || !video.videoWidth) {
                if (scanning) requestAnimationFrame(tick);
                return;
            }

            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            context.drawImage(video, 0, 0, canvas.width, canvas.height);

            readFrame(context, canvas, lookup);

            setTimeout(function () {
                if (scanning) requestAnimationFrame(tick);
            }, 120);
        }

        function start() {
            setStatus(statusEl, 'Starting the camera…');

            openCamera(video)
                .then(function (mediaStream) {
                    stream = mediaStream;
                    scanning = true;
                    page.classList.add('is-scanning');

                    if (startBtn) startBtn.hidden = true;
                    if (stopBtn) stopBtn.hidden = false;

                    setStatus(statusEl, aimingHint());
                    requestAnimationFrame(tick);
                })
                .catch(function (error) {
                    if (error && error.name === 'NotSupportedError') {
                        setStatus(statusEl,
                            'This browser cannot use the camera. Use a USB scanner or type the tag.',
                            'error');
                        return;
                    }

                    setStatus(statusEl, cameraError(error), 'error');
                });
        }

        function stop() {
            scanning = false;
            page.classList.remove('is-scanning');

            if (stream) {
                stream.getTracks().forEach(function (track) { track.stop(); });
                stream = null;
            }

            if (startBtn) startBtn.hidden = false;
            if (stopBtn) stopBtn.hidden = true;
            setStatus(statusEl, 'Camera stopped.');
        }

        if (startBtn) startBtn.addEventListener('click', start);
        if (stopBtn) stopBtn.addEventListener('click', stop);
        window.addEventListener('pagehide', stop);

        // A USB scanner types the code and presses Enter, so the only thing that
        // matters is that this input keeps focus.
        if (input) {
            input.focus();

            page.addEventListener('click', function (event) {
                if (!event.target.closest('a, button, input, select, textarea')) {
                    input.focus();
                }
            });
        }
    })();

    /* --- The field scanner -----------------------------------------------

       Attaches a camera scan button to any input that takes an asset tag or
       barcode. Rendered by templates/partials/scan-button.php, so adding one to
       a new field is a single line of markup and no JavaScript:

           <?= partial('partials/scan-button', ['target' => 'asset_tag']) ?>

       A USB scanner still works without any of this — it types into the focused
       field and presses Enter.
       -------------------------------------------------------------------- */

    (function () {
        var buttons = document.querySelectorAll('[data-scan-for]');
        if (!buttons.length) return;

        var dialog = null;
        var video = null;
        var statusEl = null;
        var canvas = document.createElement('canvas');
        var context = canvas.getContext('2d', { willReadFrequently: true });

        var stream = null;
        var scanning = false;
        var target = null;      // the input being filled
        var autoSubmit = false;

        function build() {
            dialog = document.createElement('dialog');
            dialog.className = 'scan-modal';
            dialog.innerHTML =
                '<div class="scan-modal-head">' +
                '  <h2 class="scan-modal-title">Scan a barcode</h2>' +
                '  <button type="button" class="btn" data-scan-cancel>Close</button>' +
                '</div>' +
                '<div class="scan-modal-frame"><video playsinline muted></video></div>' +
                '<p class="scan-status" data-scan-modal-status></p>' +
                '<p class="scan-modal-hint">A USB scanner or typing the tag works too — close this and use the field.</p>';

            document.body.appendChild(dialog);

            video = dialog.querySelector('video');
            statusEl = dialog.querySelector('[data-scan-modal-status]');

            dialog.addEventListener('click', function (event) {
                if (event.target === dialog || event.target.closest('[data-scan-cancel]')) close();
            });

            // Escape closes a modal dialog natively; make sure the camera stops too.
            dialog.addEventListener('close', stopCamera);
        }

        function tick() {
            if (!scanning) return;

            if (video.readyState === video.HAVE_ENOUGH_DATA && video.videoWidth) {
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                context.drawImage(video, 0, 0, canvas.width, canvas.height);

                readFrame(context, canvas, accept);
            }

            setTimeout(function () {
                if (scanning) requestAnimationFrame(tick);
            }, 120);
        }

        function accept(code) {
            if (!scanning || !target) return;
            scanning = false;

            target.value = code;

            // Let anything listening (validation, live search) react as if typed.
            target.dispatchEvent(new Event('input', { bubbles: true }));
            target.dispatchEvent(new Event('change', { bubbles: true }));

            close();

            // Where the field is the whole question — a lookup or a search — go
            // straight there rather than making the tester press another button.
            if (autoSubmit && target.form) {
                if (typeof target.form.requestSubmit === 'function') {
                    target.form.requestSubmit();
                } else {
                    target.form.submit();
                }
                return;
            }

            target.focus();
        }

        function stopCamera() {
            scanning = false;

            if (stream) {
                stream.getTracks().forEach(function (track) { track.stop(); });
                stream = null;
            }

            if (video) video.srcObject = null;
        }

        function close() {
            stopCamera();
            if (dialog && dialog.open) dialog.close();
        }

        function open(button) {
            target = document.getElementById(button.getAttribute('data-scan-for'));
            if (!target) return;

            autoSubmit = button.getAttribute('data-scan-submit') === '1';

            if (!dialog) build();
            dialog.showModal();

            setStatus(statusEl, 'Starting the camera…');

            openCamera(video)
                .then(function (mediaStream) {
                    stream = mediaStream;
                    scanning = true;
                    setStatus(statusEl, aimingHint());
                    requestAnimationFrame(tick);
                })
                .catch(function (error) {
                    if (error && error.name === 'NotSupportedError') {
                        setStatus(statusEl,
                            'This browser cannot use the camera. Close this and use a USB scanner, or type the tag.',
                            'error');
                        return;
                    }

                    setStatus(statusEl, cameraError(error), 'error');
                });
        }

        Array.prototype.forEach.call(buttons, function (button) {
            button.hidden = false;
            button.addEventListener('click', function () { open(button); });
        });

        window.addEventListener('pagehide', close);
    })();
})();
