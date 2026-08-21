/* ==========================================================================
   The guided LOLER thorough examination.

   Three jobs, none of them load-bearing:

     - turn the three stacked sections into one page at a time, and refuse to
       advance past a page whose required fields are blank;
     - keep the arithmetic in step — the interval suggests the statutory basis
       and the next examination date, both of which stay editable;
     - surface the consequences of what has been ticked, so an examiner sees
       the regulation 10 duties at the moment they become relevant rather than
       after submitting.

   LolerController checks every one of these on the server, including the
   contradiction between a dangerous defect and a "safe to operate"
   declaration. Without JavaScript all three sections are simply visible and
   the form submits perfectly well.
   ========================================================================== */
(function () {
    'use strict';

    var form = document.querySelector('[data-loler-wizard]');
    if (!form) return;

    var steps = Array.prototype.slice.call(form.querySelectorAll('[data-wizard-step]'));
    if (!steps.length) return;

    var progress = form.querySelector('[data-wizard-progress]');
    var backBtn  = form.querySelector('[data-wizard-back]');
    var nextBtn  = form.querySelector('[data-wizard-next]');
    var saveBtn  = form.querySelector('[data-wizard-save]');
    var countEl  = form.querySelector('[data-wizard-count]');

    var current = 0;

    // A rejected submission comes back with the offending fields marked. Land
    // on the first page that has one.
    steps.forEach(function (step, i) {
        if (step.querySelector('.field-error') && current === 0) current = i;
    });

    /* --- Paging ----------------------------------------------------------- */

    steps.forEach(function (step, i) {
        var li = document.createElement('li');
        li.className = 'wizard-progress-step';
        li.textContent = step.getAttribute('data-step-name') || ('Page ' + (i + 1));
        li.setAttribute('data-goto', String(i));
        progress.appendChild(li);
    });

    progress.addEventListener('click', function (event) {
        var item = event.target.closest('[data-goto]');
        if (item) show(parseInt(item.getAttribute('data-goto'), 10));
    });

    function show(index) {
        current = Math.max(0, Math.min(steps.length - 1, index));
        visited[current] = true;

        steps.forEach(function (step, i) { step.hidden = i !== current; });

        paint();

        backBtn.hidden = current === 0;
        nextBtn.hidden = current === steps.length - 1;
        saveBtn.hidden = current !== steps.length - 1;

        if (countEl) countEl.textContent = 'Page ' + (current + 1) + ' of ' + steps.length;

        if (form.getBoundingClientRect().top < 0) form.scrollIntoView({ block: 'start' });
    }

    /** The first required field on a page that has not been filled in. */
    function firstBlank(step) {
        var required = Array.prototype.slice.call(step.querySelectorAll('[required]'));

        for (var i = 0; i < required.length; i++) {
            var field = required[i];

            if (field.offsetParent === null && field.type !== 'hidden') continue;

            if (field.type === 'checkbox' ? !field.checked : String(field.value || '').trim() === '') {
                return field;
            }
        }

        /*
         * The photographs cannot carry `required`. There are two file inputs on
         * purpose — a camera one and a gallery one, so that a phone opens the
         * right thing — and only ever one of them holds the files, so marking
         * both required would make the page unsatisfiable and marking one would
         * be a guess at which the examiner used.
         *
         * Without this the page reads as complete the moment it is opened,
         * because everything else on it arrives prefilled. The photograph is
         * the part of this page that can only come from somebody standing in
         * front of the equipment, which makes it exactly the right thing for
         * the stage indicator to wait on.
         */
        var photoField = step.querySelector('[data-photo-required]');

        if (photoField) {
            var inputs = Array.prototype.slice.call(photoField.querySelectorAll('input[type="file"]'));
            var chosen = inputs.some(function (input) { return input.files && input.files.length > 0; });

            if (!chosen) {
                return inputs[0] || null;
            }
        }

        return null;
    }

    /*
     * A page is complete when it has been looked at *and* has nothing
     * outstanding on it.
     *
     * The second half alone is not enough here, the way it is on the PAT
     * wizard, because most of what these pages ask arrives prefilled: the
     * employer and premises come from Settings, the examiner is whoever is
     * signed in, the dates default to today and the interval, and the basis is
     * worked out from the type. Judged on blank fields alone, pages two and
     * three are finished before they have been opened — which paints the rail
     * green for an examination nobody has carried out yet, and green is the
     * one thing on this form that should never be given away.
     *
     * Page one counts as visited from the start, because it is the one on
     * screen.
     */
    var visited = { 0: true };

    function complete(step, index) {
        return visited[index] === true && firstBlank(step) === null;
    }

    function paint() {
        Array.prototype.forEach.call(progress.children, function (li, i) {
            li.className = 'wizard-progress-step'
                + (i === current ? ' is-current' : '')
                + (complete(steps[i], i) ? ' is-pass' : '');
        });
    }

    function flag(field) {
        if (!field) return;

        var row = field.closest('.confirm-row') || field.closest('.field') || field.closest('.defect-row');

        if (row) {
            row.classList.add('has-error');
            row.scrollIntoView({ block: 'center' });
            window.setTimeout(function () { row.classList.remove('has-error'); }, 2500);
        }

        if (field.type !== 'checkbox' && field.type !== 'radio') field.focus();
    }

    backBtn.addEventListener('click', function () { show(current - 1); });

    nextBtn.addEventListener('click', function () {
        var blank = firstBlank(steps[current]);

        if (blank) {
            flag(blank);
            return;
        }

        show(current + 1);
    });

    /* --- Page 1: confirming the equipment --------------------------------- */

    var confirmWrap = form.querySelector('[data-confirm-all-wrap]');
    var confirmAll  = form.querySelector('[data-confirm-all]');

    if (confirmWrap && confirmAll) {
        confirmWrap.hidden = false;

        confirmAll.addEventListener('click', function () {
            Array.prototype.forEach.call(form.querySelectorAll('[data-confirm]'), function (box) {
                box.checked = true;
            });

            paint();
        });
    }

    // Schedule 1(3) asks for the date of manufacture where known, so saying it
    // is not known has to actually clear the date rather than sit beside one.
    var unknownBox   = form.querySelector('[data-manufacture-unknown]');
    var manufactured = form.querySelector('[data-manufacture-date]');

    if (unknownBox && manufactured) {
        var syncManufacture = function () {
            manufactured.disabled = unknownBox.checked;
            if (unknownBox.checked) manufactured.value = '';
        };

        unknownBox.addEventListener('change', syncManufacture);
        syncManufacture();
    }

    /* --- The arithmetic --------------------------------------------------- */

    var typeSelect = form.querySelector('#loler_type');
    var interval   = form.querySelector('[data-interval]');
    var basis      = form.querySelector('[data-basis]');
    var examinedOn = form.querySelector('[data-examined-on]');
    var nextDate   = form.querySelector('[data-next-examination]');

    /** What regulation 9(3)(a) sets for the chosen type before any scheme. */
    function statutoryInterval() {
        if (!typeSelect) return 12;

        var option = typeSelect.options[typeSelect.selectedIndex];
        var kind   = option ? option.getAttribute('data-kind') : 'equipment';

        return kind === 'equipment' ? 12 : 6;
    }

    function syncBasis() {
        if (!basis || !interval) return;

        // Exceptional circumstances are never inferred: only the examiner knows
        // one has occurred, so a choice of (iv) is left alone.
        if (basis.value === 'exceptional') return;

        var months    = parseInt(interval.value, 10);
        var statutory = statutoryInterval();

        basis.value = (months === statutory && (statutory === 6 || statutory === 12))
            ? (statutory === 6 ? '6-month' : '12-month')
            : 'scheme';
    }

    /** Schedule 1(8)(d), counted from the date of examination. */
    function syncNextDate() {
        if (!examinedOn || !nextDate || !interval) return;

        var months = parseInt(interval.value, 10);
        if (!examinedOn.value || !isFinite(months) || months < 1) return;

        var parts = examinedOn.value.split('-');
        var date  = new Date(Date.UTC(+parts[0], +parts[1] - 1, +parts[2]));
        var day   = date.getUTCDate();

        date.setUTCDate(1);
        date.setUTCMonth(date.getUTCMonth() + months);

        // Adding six months to the 31st must not roll into the next month.
        var lastDay = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 0)).getUTCDate();
        date.setUTCDate(Math.min(day, lastDay));

        nextDate.value = date.toISOString().slice(0, 10);
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', function () {
            // A type change re-suggests the interval it implies, but only when
            // the interval still reads as the other type's statutory figure —
            // a scheme interval somebody typed is theirs to keep.
            if (interval) {
                var months = parseInt(interval.value, 10);

                if (months === 6 || months === 12) interval.value = String(statutoryInterval());
            }

            syncBasis();
            syncNextDate();
        });
    }

    if (interval) {
        interval.addEventListener('change', function () { syncBasis(); syncNextDate(); });
        interval.addEventListener('input', syncNextDate);
    }

    if (examinedOn) examinedOn.addEventListener('change', syncNextDate);

    /* --- Page 2: defects and their consequences --------------------------- */

    var defectBlock = form.querySelector('[data-defect-block]');
    var defectRows  = form.querySelector('[data-defect-rows]');
    var addDefect   = form.querySelector('[data-add-defect]');
    var dangerNote  = form.querySelector('[data-danger-notice]');
    var outOfUse    = form.querySelector('[data-out-of-service]');
    var safeBox     = form.querySelector('[data-safe]');

    function outcome() {
        var chosen = form.querySelector('[data-outcome]:checked');

        return chosen ? chosen.value : 'none';
    }

    /** Is any recorded defect categorised as a present danger to persons? */
    function anyDanger() {
        if (outcome() !== 'defects') return false;

        return Array.prototype.some.call(
            form.querySelectorAll('[data-defect-category]:checked'),
            function (input) { return input.value === 'danger'; }
        );
    }

    function syncConsequences() {
        var defects = outcome() === 'defects';

        if (defectBlock) defectBlock.hidden = !defects;

        var danger = anyDanger();

        if (dangerNote) dangerNote.hidden = !danger;
        if (outOfUse) outOfUse.hidden = !danger;

        // Regulation 10(3)(a) forbids use of equipment with a defect that is a
        // danger until it is rectified, so the two cannot both be true. The
        // server refuses the combination outright; this stops it being ticked
        // in the first place.
        if (safeBox) {
            if (danger) safeBox.checked = false;
            safeBox.disabled = danger;
        }

        // Only a "could become a danger" defect needs a date by which it could.
        Array.prototype.forEach.call(form.querySelectorAll('[data-defect-row]'), function (row) {
            var chosen = row.querySelector('[data-defect-category]:checked');
            var when   = row.querySelector('[data-defect-when]');
            var notice = row.querySelector('[data-serious-notice]');
            var seriousBox = row.querySelector('[data-serious]');

            if (when) when.hidden = !chosen || chosen.value !== 'becoming_danger';
            if (notice && seriousBox) notice.hidden = !seriousBox.checked;
        });

        paint();
    }

    form.addEventListener('change', syncConsequences);

    if (addDefect && defectRows) {
        addDefect.hidden = false;

        addDefect.addEventListener('click', function () {
            var rows  = defectRows.querySelectorAll('[data-defect-row]');
            var index = rows.length;
            var clone = rows[0].cloneNode(true);

            // Renumber every name, id and label so the new row posts as its own
            // entry rather than overwriting the first.
            Array.prototype.forEach.call(clone.querySelectorAll('[name]'), function (field) {
                field.name = field.name.replace(/defect\[\d+\]/, 'defect[' + index + ']');

                if (field.type === 'checkbox' || field.type === 'radio') {
                    field.checked = false;
                } else {
                    field.value = '';
                }
            });

            Array.prototype.forEach.call(clone.querySelectorAll('[id]'), function (field) {
                var fresh = field.id.replace(/-\d+$/, '-' + index);
                var label = clone.querySelector('label[for="' + field.id + '"]');

                field.id = fresh;
                if (label) label.setAttribute('for', fresh);
            });

            var number = clone.querySelector('[data-defect-number]');
            if (number) number.textContent = String(index + 1);

            clone.classList.remove('has-error');

            defectRows.appendChild(clone);
            syncConsequences();
        });
    }

    /* --- Page 3: the inverted toggle, and filling from settings ----------- */

    Array.prototype.forEach.call(form.querySelectorAll('[data-hides]'), function (control) {
        var target = form.querySelector(control.getAttribute('data-hides'));
        if (!target) return;

        var apply = function () { target.hidden = control.checked; };

        control.addEventListener('change', apply);
        apply();
    });

    var orgName    = form.getAttribute('data-org-name') || '';
    var orgAddress = form.getAttribute('data-org-address') || '';

    var fillTargets = {
        employer:    ['employer_name', 'employer_address'],
        examination: [null, 'examination_address'],
        owner:       ['owner_name', 'owner_address'],
        examiner:    ['examiner_employer_name', 'examiner_employer_address']
    };

    Array.prototype.forEach.call(form.querySelectorAll('[data-fill]'), function (button) {
        // Hidden until the script runs: a button that does nothing without
        // JavaScript is worse than no button.
        if (orgName === '' && orgAddress === '') return;

        button.hidden = false;

        button.addEventListener('click', function () {
            var pair = fillTargets[button.getAttribute('data-fill')];
            if (!pair) return;

            if (pair[0]) {
                var nameField = form.querySelector('#' + pair[0]);
                if (nameField) nameField.value = orgName;
            }

            if (pair[1]) {
                var addressField = form.querySelector('#' + pair[1]);
                if (addressField) addressField.value = orgAddress;
            }

            paint();
        });
    });

    /* --- Go --------------------------------------------------------------- */

    backBtn.hidden = false;
    nextBtn.hidden = false;
    syncConsequences();
    show(current);
})();
