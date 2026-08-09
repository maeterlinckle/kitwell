/* ==========================================================================
   Barcode scanning.

   Three ways in, all ending at the same lookup:

     1. The device camera. Uses the native BarcodeDetector API where the
        browser has it (Chrome and Edge on Android and desktop). Where it does
        not — Safari, including every iPhone — falls back to the Code 128
        reader below, which decodes the labels this application prints.
     2. A USB barcode scanner. These act as keyboards: they "type" the code
        and press Enter. The input is focused by default, so this needs no
        special handling beyond keeping focus.
     3. Typing the tag by hand.

   No third-party library: the Content-Security-Policy only allows scripts
   from this origin, and a barcode reader is not worth a vendored blob nobody
   can audit. The decoder below is the mirror image of src/Core/Barcode.php.
   ========================================================================== */
(function () {
    'use strict';

    /* --- Code 128 -------------------------------------------------------- */

    // Bar/space widths for values 0-106; identical to the PHP encoder's table.
    var PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312',
        '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
        '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131',
        '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321',
        '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121',
        '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321',
        '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224',
        '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114',
        '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112',
        '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113',
        '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412',
        '211214', '211232', '2331112'
    ];

    var START_A = 103, START_B = 104, START_C = 105, STOP = 106;

    var PATTERN_LOOKUP = (function () {
        var map = {};
        for (var i = 0; i < PATTERNS.length; i++) {
            map[PATTERNS[i]] = i;
        }
        return map;
    })();

    /**
     * Turn a run of bar/space widths into a symbol value.
     * Widths are normalised against the module width so any print scale works.
     */
    function symbolFromWidths(widths, moduleWidth) {
        var key = '';

        for (var i = 0; i < widths.length; i++) {
            var units = Math.round(widths[i] / moduleWidth);
            if (units < 1 || units > 4) return -1;
            key += units;
        }

        var value = PATTERN_LOOKUP[key];
        return value === undefined ? -1 : value;
    }

    /**
     * Decode one horizontal line of luminance samples.
     * Returns the decoded string, or null.
     */
    function decodeLine(samples) {
        // Threshold at the midpoint between the darkest and lightest samples;
        // simple, and robust enough for a printed label under workshop light.
        var min = 255, max = 0, i;

        for (i = 0; i < samples.length; i++) {
            if (samples[i] < min) min = samples[i];
            if (samples[i] > max) max = samples[i];
        }

        // Too little contrast to be a barcode.
        if (max - min < 40) return null;

        var threshold = (min + max) / 2;

        // Run-length encode into alternating bar/space widths.
        var runs = [];
        var runStart = 0;
        var isDark = samples[0] < threshold;

        for (i = 1; i < samples.length; i++) {
            var dark = samples[i] < threshold;
            if (dark !== isDark) {
                runs.push({ dark: isDark, width: i - runStart });
                runStart = i;
                isDark = dark;
            }
        }
        runs.push({ dark: isDark, width: samples.length - runStart });

        // A Code 128 symbol needs at least start + data + check + stop.
        if (runs.length < 20) return null;

        // Try every plausible starting bar, in both directions.
        for (var direction = 0; direction < 2; direction++) {
            var ordered = direction === 0 ? runs : runs.slice().reverse();
            var result = decodeRuns(ordered);
            if (result !== null) return result;
        }

        return null;
    }

    function decodeRuns(runs) {
        for (var start = 0; start < runs.length - 18; start++) {
            if (!runs[start].dark) continue;

            // The start symbol is 11 modules across 6 runs.
            var startWidth = 0;
            for (var k = 0; k < 6; k++) {
                if (start + k >= runs.length) return null;
                startWidth += runs[start + k].width;
            }

            var moduleWidth = startWidth / 11;
            if (moduleWidth < 0.7) continue;

            var decoded = decodeFrom(runs, start, moduleWidth);
            if (decoded !== null) return decoded;
        }

        return null;
    }

    function decodeFrom(runs, start, moduleWidth) {
        var values = [];
        var index = start;

        while (index + 6 <= runs.length && values.length < 64) {
            var widths = [];
            for (var k = 0; k < 6; k++) {
                widths.push(runs[index + k].width);
            }

            var value = symbolFromWidths(widths, moduleWidth);

            if (value === -1) {
                // The stop pattern is seven runs wide.
                if (index + 7 <= runs.length) {
                    var stopWidths = widths.concat([runs[index + 6].width]);
                    if (symbolFromWidths(stopWidths, moduleWidth) === STOP) {
                        return finish(values);
                    }
                }
                return null;
            }

            if (value === STOP) return finish(values);

            values.push(value);
            index += 6;

            // Re-estimate the module width as we go, so a slightly skewed or
            // curved label does not drift out of tolerance.
            var span = 0;
            for (var m = start; m < index; m++) span += runs[m].width;
            moduleWidth = span / (11 * values.length);
        }

        return null;
    }

    /** Verify the checksum and turn symbol values into text. */
    function finish(values) {
        if (values.length < 3) return null;

        var startValue = values[0];
        if (startValue !== START_A && startValue !== START_B && startValue !== START_C) return null;

        var checkValue = values[values.length - 1];
        var data = values.slice(1, values.length - 1);

        var sum = startValue;
        for (var i = 0; i < data.length; i++) {
            sum += data[i] * (i + 1);
        }

        if (sum % 103 !== checkValue) return null;

        var text = '';
        var mode = startValue;

        for (var j = 0; j < data.length; j++) {
            var value = data[j];

            // Code set switches. Anything else non-printable is not something
            // this application prints, so bail rather than guess.
            if (value === 99) { mode = START_C; continue; }
            if (value === 100) { mode = START_B; continue; }
            if (value === 101) { mode = START_A; continue; }

            if (mode === START_C) {
                if (value > 99) return null;
                text += (value < 10 ? '0' : '') + value;
            } else {
                if (value > 94) return null;
                text += String.fromCharCode(value + 32);
            }
        }

        return text === '' ? null : text;
    }

    /**
     * Look for a barcode in a canvas by sampling horizontal lines across it.
     * Several lines, because one may cross a smudge or a fold.
     */
    function decodeCanvas(context, width, height) {
        var lines = 15;

        for (var n = 1; n <= lines; n++) {
            var y = Math.floor((height * n) / (lines + 1));
            var row = context.getImageData(0, y, width, 1).data;
            var samples = new Uint8Array(width);

            for (var x = 0; x < width; x++) {
                var offset = x * 4;
                // Rec. 601 luma: good enough, and cheap.
                samples[x] = (row[offset] * 299 + row[offset + 1] * 587 + row[offset + 2] * 114) / 1000;
            }

            var result = decodeLine(samples);
            if (result !== null) return result;
        }

        return null;
    }

    // Exposed for the browser-based round-trip test.
    window.AssetBarcode = { decodeCanvas: decodeCanvas, decodeLine: decodeLine };

    /* --- The scanner UI -------------------------------------------------- */

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
    var detector = null;
    var scanning = false;
    var lastCode = '';
    var lastCodeAt = 0;

    function setStatus(message, tone) {
        if (!statusEl) return;
        statusEl.textContent = message;
        statusEl.className = 'scan-status' + (tone ? ' scan-status-' + tone : '');
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

    function lookup(code) {
        var now = Date.now();

        // Ignore the same code re-read within a couple of seconds.
        if (code === lastCode && now - lastCodeAt < 2500) return;
        lastCode = code;
        lastCodeAt = now;

        beep();
        vibrate();
        setStatus('Looking up ' + code + '…');

        fetch(lookupUrl + '?code=' + encodeURIComponent(code), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (response) { return response.json(); })
            .then(function (data) { render(data, code); })
            .catch(function () {
                setStatus('Could not reach the server. Check your connection.', 'error');
            });
    }

    function render(data, code) {
        if (!resultEl) return;

        if (!data.found) {
            setStatus(data.message || ('No asset matches ' + code), 'error');
            resultEl.innerHTML = '<p class="muted">Nothing found for <span class="mono">'
                + escapeHtml(code) + '</span>. Check the tag, or search the register.</p>';
            resultEl.hidden = false;
            return;
        }

        setStatus('Found ' + data.asset.tag, 'ok');

        var html = '<div class="scan-hit">'
            + '<p class="eyebrow mono">' + escapeHtml(data.asset.tag) + '</p>'
            + '<h2>' + escapeHtml(data.asset.name) + '</h2>'
            + '<p class="badge-row">'
            + '<span class="badge status-' + escapeHtml(slug(data.asset.status)) + '">' + escapeHtml(data.asset.status) + '</span>'
            + '<span class="badge">' + escapeHtml(data.asset.condition) + '</span>'
            + (data.asset.location ? '<span class="badge badge-muted">' + escapeHtml(data.asset.location) + '</span>' : '')
            + '</p>';

        if (data.loan) {
            html += '<p class="' + (data.loan.overdue ? 'scan-warn' : 'muted') + '">'
                + 'Out with ' + escapeHtml(data.loan.borrower) + ', due ' + escapeHtml(data.loan.due)
                + (data.loan.overdue ? ' — overdue' : '') + '</p>';
        }

        if (data.blocked && !data.loan) {
            html += '<p class="scan-warn">' + escapeHtml(data.blocked) + '</p>';
        }

        html += '<div class="scan-actions">';

        if (mode === 'checkout' && data.can.checkout) {
            html += '<a class="btn btn-primary btn-lg" href="' + escapeHtml(data.checkout_url) + '">Check out</a>';
        }

        if (mode === 'return' && data.can.return) {
            html += '<a class="btn btn-primary btn-lg" href="' + escapeHtml(data.loan.return_url) + '">Book in</a>';
        }

        if (mode !== 'checkout' && data.can.checkout) {
            html += '<a class="btn" href="' + escapeHtml(data.checkout_url) + '">Check out</a>';
        }

        if (mode !== 'return' && data.can.return) {
            html += '<a class="btn" href="' + escapeHtml(data.loan.return_url) + '">Book in</a>';
        }

        html += '<a class="btn' + (mode === 'view' ? ' btn-primary btn-lg' : '') + '" href="'
            + escapeHtml(data.asset.url) + '">Open asset</a>';
        html += '</div></div>';

        resultEl.innerHTML = html;
        resultEl.hidden = false;
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function slug(value) {
        return String(value || '').toLowerCase().replace(/\s+/g, '-');
    }

    function tick() {
        if (!scanning || !video.videoWidth) {
            if (scanning) requestAnimationFrame(tick);
            return;
        }

        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        context.drawImage(video, 0, 0, canvas.width, canvas.height);

        if (detector) {
            detector.detect(canvas)
                .then(function (codes) {
                    if (codes && codes.length) lookup(codes[0].rawValue);
                })
                .catch(function () { /* a bad frame is not worth reporting */ });
        } else {
            var found = decodeCanvas(context, canvas.width, canvas.height);
            if (found) lookup(found);
        }

        setTimeout(function () {
            if (scanning) requestAnimationFrame(tick);
        }, 120);
    }

    function start() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            setStatus('This browser cannot use the camera. Use a USB scanner or type the tag.', 'error');
            return;
        }

        setStatus('Starting the camera…');

        navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }
        })
            .then(function (mediaStream) {
                stream = mediaStream;
                video.srcObject = mediaStream;
                video.setAttribute('playsinline', 'true');
                return video.play();
            })
            .then(function () {
                scanning = true;
                page.classList.add('is-scanning');
                if (startBtn) startBtn.hidden = true;
                if (stopBtn) stopBtn.hidden = false;

                setStatus(detector
                    ? 'Point the camera at the barcode.'
                    : 'Point the camera at the barcode. Hold it steady and fill the width of the frame.');

                requestAnimationFrame(tick);
            })
            .catch(function (error) {
                var message = 'Could not start the camera.';

                if (error && error.name === 'NotAllowedError') {
                    message = 'Camera permission was refused. Allow it in your browser settings, or type the tag below.';
                } else if (error && error.name === 'NotFoundError') {
                    message = 'No camera was found. Use a USB scanner or type the tag below.';
                }

                setStatus(message, 'error');
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
        setStatus('Camera stopped.');
    }

    // Prefer the browser's own detector when it exists.
    if ('BarcodeDetector' in window) {
        window.BarcodeDetector.getSupportedFormats()
            .then(function (formats) {
                var wanted = ['code_128', 'code_39', 'ean_13', 'qr_code'].filter(function (f) {
                    return formats.indexOf(f) !== -1;
                });

                if (wanted.length) {
                    detector = new window.BarcodeDetector({ formats: wanted });
                }
            })
            .catch(function () { detector = null; });
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
