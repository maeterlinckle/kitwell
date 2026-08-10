/* Asset Register — small vanilla-JS behaviours. No build step, no framework. */
(function () {
    'use strict';

    // --- Theme -------------------------------------------------------------
    // The initial theme is applied by an inline script in <head> so the page
    // never flashes. This handles the toggle and remembers the choice in both
    // localStorage (fast) and a cookie (survives a cleared storage on iOS).
    function currentTheme() {
        return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);

        try {
            localStorage.setItem('theme', theme);
        } catch (e) { /* private mode */ }

        var secure = location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = 'theme=' + theme + '; path=/; max-age=31536000; SameSite=Lax' + secure;

        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) {
            meta.setAttribute('content', theme === 'dark' ? '#0b1120' : '#ffffff');
        }

        updateThemeLabels(theme);
    }

    function updateThemeLabels(theme) {
        var label = theme === 'dark' ? 'Light mode' : 'Dark mode';
        document.querySelectorAll('[data-theme-label]').forEach(function (el) {
            el.textContent = label;
        });
        document.querySelectorAll('[data-theme-toggle]').forEach(function (el) {
            el.setAttribute('aria-label', 'Switch to ' + label.toLowerCase());
        });
    }

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-theme-toggle]');
        if (toggle) {
            applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
        }
    });

    updateThemeLabels(currentTheme());

    // --- Mobile navigation -------------------------------------------------
    var navToggle = document.querySelector('[data-nav-toggle]');
    var nav = document.querySelector('[data-nav]');

    if (navToggle && nav) {
        navToggle.addEventListener('click', function () {
            var open = nav.classList.toggle('is-open');
            navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        // Close the menu when tapping outside it, or on Escape.
        document.addEventListener('click', function (event) {
            if (!nav.classList.contains('is-open')) return;
            if (nav.contains(event.target) || navToggle.contains(event.target)) return;

            nav.classList.remove('is-open');
            navToggle.setAttribute('aria-expanded', 'false');
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && nav.classList.contains('is-open')) {
                nav.classList.remove('is-open');
                navToggle.setAttribute('aria-expanded', 'false');
                navToggle.focus();
            }
        });
    }

    // --- Show/hide password ------------------------------------------------
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-toggle-password]');
        if (!button) return;

        var input = document.getElementById(button.getAttribute('data-toggle-password'));
        if (!input) return;

        var reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        button.textContent = reveal ? 'Hide' : 'Show';
    });

    // --- Copy a read-only field to the clipboard ---------------------------
    // Progressive enhancement only: without JavaScript the field is still
    // selectable and the address is still visible, which is all it has to be.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-copy]');
        if (!button) return;

        var field = document.querySelector(button.getAttribute('data-copy'));
        if (!field) return;

        field.focus();
        field.select();

        var done = function () {
            var original = button.getAttribute('data-copy-label') || button.textContent;
            button.setAttribute('data-copy-label', original);
            button.textContent = 'Copied';
            window.setTimeout(function () { button.textContent = original; }, 2000);
        };

        // navigator.clipboard needs a secure context; execCommand is the
        // fallback for a plain-http install on a local network.
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(field.value).then(done, function () {});
            return;
        }

        try {
            if (document.execCommand('copy')) done();
        } catch (error) {
            /* Leave the text selected — the user can copy it themselves. */
        }
    });

    // Selecting the whole address on focus makes it one gesture to copy by
    // hand, which is the no-JavaScript path's only alternative.
    document.addEventListener('focusin', function (event) {
        if (event.target.matches('[data-select-on-focus]')) event.target.select();
    });

    // --- Confirmation on destructive actions -------------------------------
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-confirm]');
        if (!trigger) return;

        if (!window.confirm(trigger.getAttribute('data-confirm'))) {
            event.preventDefault();
            event.stopPropagation();
        }
    });

    // --- Dismissable flash messages ----------------------------------------
    document.addEventListener('click', function (event) {
        var dismiss = event.target.closest('[data-dismiss]');
        if (dismiss && dismiss.parentElement) {
            dismiss.parentElement.remove();
        }
    });

    // --- Select all / none within a permission group -----------------------
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-check-group]');
        if (!button) return;

        var group = button.closest('.permission-group');
        if (!group) return;

        var boxes = group.querySelectorAll('input[type="checkbox"]');
        var allChecked = Array.prototype.every.call(boxes, function (box) { return box.checked; });

        boxes.forEach(function (box) { box.checked = !allChecked; });
        button.textContent = allChecked ? 'Select all' : 'Clear all';
    });

    // --- Print button (label sheets) ---------------------------------------
    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-print]')) {
            window.print();
        }
    });

    // --- Row selection in the asset register -------------------------------
    // Keeps the "select all" box, the selected count and the bulk-action
    // buttons in step with the checkboxes.
    (function () {
        var table = document.querySelector('[data-selectable]');
        if (!table) return;

        var selectAll = document.querySelector('[data-select-all]');
        var counter   = document.querySelector('[data-selected-count]');
        var actions   = document.querySelectorAll('[data-requires-selection]');

        function boxes() {
            return Array.prototype.slice.call(table.querySelectorAll('input[name="ids[]"]'));
        }

        function sync() {
            var all      = boxes();
            var selected = all.filter(function (b) { return b.checked; });

            if (counter) {
                counter.textContent = selected.length
                    ? selected.length + ' selected'
                    : 'None selected';
            }

            actions.forEach(function (el) {
                el.disabled = selected.length === 0;
                el.classList.toggle('is-disabled', selected.length === 0);
            });

            if (selectAll) {
                selectAll.checked = all.length > 0 && selected.length === all.length;
                selectAll.indeterminate = selected.length > 0 && selected.length < all.length;
            }
        }

        table.addEventListener('change', function (event) {
            if (event.target.name === 'ids[]') sync();
        });

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                boxes().forEach(function (b) { b.checked = selectAll.checked; });
                sync();
            });
        }

        sync();
    })();

    // --- Conditional form sections -----------------------------------------
    // Shows/hides a block when a checkbox or select changes, e.g. the PAT
    // interval only matters when "requires PAT" is ticked.
    document.querySelectorAll('[data-toggles]').forEach(function (control) {
        var target = document.querySelector(control.getAttribute('data-toggles'));
        if (!target) return;

        function apply() {
            var on = control.type === 'checkbox' ? control.checked : control.value !== '';
            target.hidden = !on;
        }

        control.addEventListener('change', apply);
        apply();
    });

    // --- Maintenance schedule type ------------------------------------------
    // Shows only the fields that apply to the chosen schedule type: a one-off
    // job has no recurrence, so its interval fields are pointless noise.
    (function () {
        var typeInputs = document.querySelectorAll('[data-schedule-type]');
        if (!typeInputs.length) return;

        var sections = document.querySelectorAll('[data-when-type]');

        function apply() {
            var selected = document.querySelector('[data-schedule-type]:checked');
            var current = selected ? selected.value : '';

            sections.forEach(function (section) {
                var applies = section.getAttribute('data-when-type').split(' ');
                section.hidden = applies.indexOf(current) === -1;
            });
        }

        typeInputs.forEach(function (input) {
            input.addEventListener('change', apply);
        });

        apply();
    })();

    // --- Checkbox that reveals its own fields --------------------------------
    // Progressive enhancement: without JavaScript the fields stay visible and
    // are simply ignored unless the box is ticked, so nothing is unreachable.
    (function () {
        var toggles = document.querySelectorAll('[data-toggle-fields]');
        if (!toggles.length) return;

        toggles.forEach(function (toggle) {
            var target = document.getElementById(toggle.getAttribute('data-toggle-fields'));
            if (!target) return;

            function apply() {
                target.hidden = !toggle.checked;
            }

            toggle.addEventListener('change', apply);
            apply();
        });
    })();

    // Choosing a routine cadence fills in the matching interval fields.
    (function () {
        var preset = document.getElementById('routine_preset');
        if (!preset) return;

        var presets = {
            weekly:      [1, 'weeks'],
            fortnightly: [2, 'weeks'],
            monthly:     [1, 'months'],
            quarterly:   [3, 'months'],
            biannual:    [6, 'months'],
            annual:      [1, 'years']
        };

        preset.addEventListener('change', function () {
            var chosen = presets[preset.value];
            if (!chosen) return;

            var interval = document.getElementById('frequency_interval');
            var unit = document.getElementById('frequency_unit');

            if (interval) interval.value = chosen[0];
            if (unit) unit.value = chosen[1];
        });
    })();

    // --- PAT form: show only the fields that apply -------------------------
    // Earth continuity is meaningless on a Class II (double-insulated)
    // appliance, and the remedial-action fields only matter on a failure.
    (function () {
        var classSelect = document.querySelector('[data-pat-class]');
        var resultInputs = document.querySelectorAll('[data-pat-result]');

        if (!classSelect && !resultInputs.length) return;

        function applyClass() {
            if (!classSelect) return;

            document.querySelectorAll('[data-pat-when-class]').forEach(function (section) {
                var applies = section.getAttribute('data-pat-when-class').split('|');
                section.hidden = applies.indexOf(classSelect.value) === -1;
            });
        }

        function applyResult() {
            var selected = document.querySelector('[data-pat-result]:checked');
            var current = selected ? selected.value : '';

            document.querySelectorAll('[data-pat-when-result]').forEach(function (section) {
                var applies = section.getAttribute('data-pat-when-result').split('|');
                section.hidden = applies.indexOf(current) === -1;
            });
        }

        if (classSelect) classSelect.addEventListener('change', applyClass);
        resultInputs.forEach(function (input) {
            input.addEventListener('change', applyResult);
        });

        applyClass();
        applyResult();
    })();

    // A failed visual inspection means the item fails overall, so flip the
    // result for the tester rather than letting them save a contradiction.
    (function () {
        var visual = document.querySelector('input[name="visual_inspection_pass"]');
        var fail = document.querySelector('[data-pat-result][value="Fail"]');

        if (!visual || !fail) return;

        visual.addEventListener('change', function () {
            if (!visual.checked && !fail.checked) {
                fail.checked = true;
                fail.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    })();

    // --- Photo upload: previews and size warnings --------------------------
    // Phone cameras produce large files and workshop signal is often poor, so
    // show what is about to be sent and flag anything over the server limit
    // before the upload is attempted rather than after it fails.
    (function () {
        var forms = document.querySelectorAll('[data-photo-form]');
        if (!forms.length) return;

        function formatBytes(bytes) {
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
            return Math.round(bytes / 1024) + ' KB';
        }

        forms.forEach(function (form) {
            // The server is the authority on the limit; it is passed down on
            // the form so this stays in step with config/config.php.
            var MAX_BYTES = parseInt(form.getAttribute('data-max-bytes'), 10) || (10 * 1024 * 1024);

            var preview = form.querySelector('[data-photo-preview]');
            var meta    = form.querySelector('[data-photo-meta]');
            var submit  = form.querySelector('[data-photo-submit]');
            var inputs  = form.querySelectorAll('[data-photo-input]');

            inputs.forEach(function (input) {
                input.addEventListener('change', function () {
                    // Only one input can carry files, or the browser sends both.
                    inputs.forEach(function (other) {
                        if (other !== input) other.value = '';
                    });

                    if (!preview) return;

                    preview.innerHTML = '';
                    var files = Array.prototype.slice.call(input.files || []);
                    var oversized = 0;

                    files.forEach(function (file) {
                        var item = document.createElement('div');
                        item.className = 'photo-preview-item';

                        if (file.size > MAX_BYTES) {
                            item.classList.add('is-too-big');
                            oversized++;
                        }

                        var img = document.createElement('img');
                        img.alt = '';
                        // Revoked on load so a big selection does not hold
                        // every full-size image in memory.
                        img.src = URL.createObjectURL(file);
                        img.addEventListener('load', function () {
                            URL.revokeObjectURL(img.src);
                        });

                        var size = document.createElement('span');
                        size.className = 'photo-preview-size';
                        size.textContent = formatBytes(file.size);

                        item.appendChild(img);
                        item.appendChild(size);
                        preview.appendChild(item);
                    });

                    var hasFiles = files.length > 0;
                    preview.hidden = !hasFiles;
                    if (meta) meta.hidden = !hasFiles;

                    if (submit) {
                        submit.hidden = !hasFiles;
                        submit.textContent = files.length === 1
                            ? 'Upload photo'
                            : 'Upload ' + files.length + ' photos';
                    }

                    if (oversized > 0) {
                        var warning = document.createElement('p');
                        warning.className = 'field-error';
                        warning.textContent = oversized + ' file' + (oversized === 1 ? ' is' : 's are')
                            + ' over the ' + Math.round(MAX_BYTES / 1048576) + ' MB limit and will be rejected.';
                        preview.appendChild(warning);
                    }
                });
            });
        });
    })();

    // --- Lightbox ----------------------------------------------------------
    (function () {
        var dialog = null;
        var links = [];
        var index = 0;

        function build() {
            dialog = document.createElement('dialog');
            dialog.className = 'lightbox';
            dialog.innerHTML =
                '<img alt="">' +
                '<div class="lightbox-bar">' +
                '  <span class="lightbox-info">' +
                '    <span class="lightbox-caption"></span>' +
                '    <span class="lightbox-meta"></span>' +
                '  </span>' +
                '  <span class="lightbox-controls">' +
                '    <button type="button" class="btn" data-lightbox-prev>&larr; Previous</button>' +
                '    <button type="button" class="btn" data-lightbox-next>Next &rarr;</button>' +
                '    <button type="button" class="btn" data-lightbox-close>Close</button>' +
                '  </span>' +
                '</div>';

            document.body.appendChild(dialog);

            dialog.addEventListener('click', function (event) {
                if (event.target === dialog) dialog.close();
                if (event.target.closest('[data-lightbox-close]')) dialog.close();
                if (event.target.closest('[data-lightbox-prev]')) step(-1);
                if (event.target.closest('[data-lightbox-next]')) step(1);
            });

            dialog.addEventListener('keydown', function (event) {
                if (event.key === 'ArrowLeft') step(-1);
                if (event.key === 'ArrowRight') step(1);
            });
        }

        function step(delta) {
            if (!links.length) return;
            index = (index + delta + links.length) % links.length;
            render();
        }

        function render() {
            var link = links[index];
            if (!link) return;

            var img = dialog.querySelector('img');
            img.src = link.getAttribute('href');
            img.alt = link.getAttribute('data-caption') || 'Condition photo';

            dialog.querySelector('.lightbox-caption').textContent = link.getAttribute('data-caption') || '';
            dialog.querySelector('.lightbox-meta').textContent =
                (link.getAttribute('data-meta') || '') + '  ·  ' + (index + 1) + ' of ' + links.length;

            var single = links.length < 2;
            dialog.querySelector('[data-lightbox-prev]').hidden = single;
            dialog.querySelector('[data-lightbox-next]').hidden = single;
        }

        document.addEventListener('click', function (event) {
            var link = event.target.closest('[data-lightbox]');
            if (!link) return;

            // Without dialog support, let the browser just open the image.
            if (typeof HTMLDialogElement === 'undefined') return;

            event.preventDefault();

            if (!dialog) build();

            links = Array.prototype.slice.call(document.querySelectorAll('[data-lightbox]'));
            index = links.indexOf(link);
            render();
            dialog.showModal();
        });
    })();

    // --- Guard against double submits --------------------------------------
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form.hasAttribute('data-allow-resubmit')) return;

        var submit = form.querySelector('button[type="submit"]');
        if (!submit) return;

        window.setTimeout(function () {
            submit.disabled = true;
            submit.dataset.originalText = submit.textContent;
            submit.textContent = 'Working…';
        }, 0);
    });
})();

/* --- Navigation groups ----------------------------------------------------
   The menu groups are <details> elements, so they open and close on their own
   with no JavaScript at all. This only adds the two desktop manners a
   drop-down is expected to have: shut when you click away, and shut on Escape.
   On a phone they stay as a plain accordion, where leaving one open is the
   helpful behaviour, not a bug. */
(function () {
    'use strict';

    var groups = document.querySelectorAll('[data-nav-group]');
    if (!groups.length) return;

    var desktop = window.matchMedia('(min-width: 900px)');

    function closeAll(except) {
        Array.prototype.forEach.call(groups, function (group) {
            if (group !== except) group.open = false;
        });
    }

    Array.prototype.forEach.call(groups, function (group) {
        group.addEventListener('toggle', function () {
            if (group.open && desktop.matches) closeAll(group);
        });
    });

    // A group carrying data-nav-autoopen was opened by the server to show which
    // section the current page belongs to, not because anyone asked for it.
    //
    // On a phone that is an accordion and is exactly what we want. On a desktop
    // the panel floats over the page, so the stylesheet hides it — but the
    // element is still `open`, which would make the first click *close* it and
    // leave the user clicking twice to see a menu. So on desktop, close it
    // properly and drop the attribute: the highlight on the summary already
    // says which section you are in.
    function normaliseAutoOpen() {
        if (!desktop.matches) return;

        Array.prototype.forEach.call(groups, function (group) {
            if (!group.hasAttribute('data-nav-autoopen')) return;
            group.open = false;
            group.removeAttribute('data-nav-autoopen');
        });
    }

    normaliseAutoOpen();

    if (desktop.addEventListener) {
        desktop.addEventListener('change', normaliseAutoOpen);
    } else if (desktop.addListener) {
        desktop.addListener(normaliseAutoOpen);   // Safari < 14
    }

    // Below the breakpoint the accordion stays open; drop the attribute once
    // it has been touched so nothing lingers if the window is later widened.
    Array.prototype.forEach.call(groups, function (group) {
        group.addEventListener('click', function () {
            group.removeAttribute('data-nav-autoopen');
        });
    });

    document.addEventListener('click', function (event) {
        if (!desktop.matches) return;
        if (event.target.closest('[data-nav-group]')) return;
        closeAll(null);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || !desktop.matches) return;

        var open = document.querySelector('[data-nav-group][open]');
        if (!open) return;

        open.open = false;
        var summary = open.querySelector('summary');
        if (summary) summary.focus();
    });
})();
