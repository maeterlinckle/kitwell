/* ==========================================================================
   Carrying out a maintenance routine.

   Turns the stacked pages in templates/routines/run.php into one screen at a
   time, refuses to advance past an unanswered required step, and previews the
   photographs a step is about to send.

   None of this is the control. The form is ordinary HTML: without JavaScript
   every page is simply visible and it submits perfectly well, and
   RoutineRunController::readAnswers() checks every required step either way.
   This makes the flow bearable on a phone; the server makes it correct.
   ========================================================================== */
(function () {
    'use strict';

    var form = document.querySelector('[data-routine-wizard]');
    if (!form) return;

    var steps = Array.prototype.slice.call(form.querySelectorAll('[data-wizard-step]'));
    if (!steps.length) return;

    var progress = form.querySelector('[data-wizard-progress]');
    var backBtn  = form.querySelector('[data-wizard-back]');
    var nextBtn  = form.querySelector('[data-wizard-next]');
    var saveBtn  = form.querySelector('[data-wizard-save]');
    var countEl  = form.querySelector('[data-wizard-count]');

    var current = 0;

    // A rejected submission comes back with the offending steps marked. Land
    // on the first page that has one rather than at the beginning.
    steps.forEach(function (step, i) {
        if (step.querySelector('.field-error') && current === 0) current = i;
    });

    /* --- Progress rail ---------------------------------------------------- */

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

        steps.forEach(function (step, i) {
            step.hidden = i !== current;
        });

        paint();

        backBtn.hidden = current === 0;
        nextBtn.hidden = current === steps.length - 1;
        saveBtn.hidden = current !== steps.length - 1;

        if (countEl) countEl.textContent = 'Page ' + (current + 1) + ' of ' + steps.length;

        if (form.getBoundingClientRect().top < 0) {
            form.scrollIntoView({ block: 'start' });
        }
    }

    /* --- Answering -------------------------------------------------------- */

    /** Has this step been answered? Files count as answered once one is picked. */
    function answered(step) {
        var fileInput = step.querySelector('input[type="file"]');

        if (step.getAttribute('data-field-type') === 'photo'
            || step.getAttribute('data-field-type') === 'document') {
            var inputs = Array.prototype.slice.call(step.querySelectorAll('input[type="file"]'));
            return inputs.some(function (input) { return input.files && input.files.length > 0; });
        }

        var radios = step.querySelectorAll('input[type="radio"]');
        if (radios.length) {
            return Array.prototype.some.call(radios, function (r) { return r.checked; });
        }

        var boxes = step.querySelectorAll('input[type="checkbox"]');
        if (boxes.length) {
            return Array.prototype.some.call(boxes, function (b) { return b.checked; });
        }

        var field = step.querySelector('[data-step-field]');
        if (!field) return fileInput ? false : true;

        return String(field.value || '').trim() !== '';
    }

    /** The first required step on this page that has not been answered. */
    function firstOutstanding(page) {
        var required = Array.prototype.slice.call(page.querySelectorAll('[data-routine-step][data-required="1"]'));

        for (var i = 0; i < required.length; i++) {
            if (!answered(required[i])) return required[i];
        }

        return null;
    }

    /** How many required steps on a page are still outstanding. */
    function outstanding(page) {
        return Array.prototype.slice
            .call(page.querySelectorAll('[data-routine-step][data-required="1"]'))
            .filter(function (step) { return !answered(step); })
            .length;
    }

    function paint() {
        Array.prototype.forEach.call(progress.children, function (li, i) {
            var page = steps[i];
            var required = page.querySelectorAll('[data-routine-step][data-required="1"]').length;
            var done = required > 0 && outstanding(page) === 0;

            li.className = 'wizard-progress-step'
                + (i === current ? ' is-current' : '')
                + (done ? ' is-pass' : '');
        });
    }

    function flag(step) {
        step.classList.add('has-error');
        step.scrollIntoView({ block: 'center' });

        var field = step.querySelector('[data-step-field]');
        if (field && field.type !== 'radio' && field.type !== 'checkbox') field.focus();

        window.setTimeout(function () { step.classList.remove('has-error'); }, 2500);
    }

    backBtn.addEventListener('click', function () { show(current - 1); });

    nextBtn.addEventListener('click', function () {
        var missing = firstOutstanding(steps[current]);

        if (missing) {
            flag(missing);
            return;
        }

        show(current + 1);
    });

    form.addEventListener('change', paint);
    form.addEventListener('input', paint);

    /* --- Photo previews, per step ----------------------------------------- */
    /* Scoped to the step rather than the form: a routine can ask for several
       photographs, and the whole-form handler in app.js clears every other
       input when one changes — which is right for a page with one upload and
       wrong for a page with four. */

    Array.prototype.forEach.call(
        form.querySelectorAll('[data-routine-step][data-field-type="photo"]'),
        function (step) {
            var inputs = Array.prototype.slice.call(step.querySelectorAll('input[type="file"]'));
            if (!inputs.length) return;

            var preview = document.createElement('div');
            preview.className = 'photo-preview';
            preview.hidden = true;
            step.appendChild(preview);

            inputs.forEach(function (input) {
                input.addEventListener('change', function () {
                    inputs.forEach(function (other) { if (other !== input) other.value = ''; });

                    preview.innerHTML = '';
                    var files = Array.prototype.slice.call(input.files || []);

                    files.forEach(function (file) {
                        var item = document.createElement('div');
                        item.className = 'photo-preview-item';

                        var img = document.createElement('img');
                        img.alt = '';
                        img.src = URL.createObjectURL(file);
                        img.addEventListener('load', function () { URL.revokeObjectURL(img.src); });

                        item.appendChild(img);
                        preview.appendChild(item);
                    });

                    preview.hidden = files.length === 0;
                });
            });
        }
    );

    /* --- Go --------------------------------------------------------------- */

    backBtn.hidden = false;
    nextBtn.hidden = false;
    show(current);
})();
