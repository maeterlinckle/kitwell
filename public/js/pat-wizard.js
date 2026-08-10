/* ==========================================================================
   The guided PAT test.

   Turns the stacked steps in templates/pat/wizard.php into one screen at a
   time, keeps the live guideline text in step with the extension-lead length,
   and gates the save button until every applicable check has been answered.

   None of this is the control. The form is ordinary HTML: without JavaScript
   every step is simply visible and it submits perfectly well, and the overall
   result is derived by PatController::validateGuided() either way. This makes
   the flow pleasant on a phone; the server makes it correct.
   ========================================================================== */
(function () {
    'use strict';

    var form = document.querySelector('[data-pat-wizard]');
    if (!form) return;

    var steps = Array.prototype.slice.call(form.querySelectorAll('[data-wizard-step]'));
    if (!steps.length) return;

    var progress = form.querySelector('[data-wizard-progress]');
    var backBtn  = form.querySelector('[data-wizard-back]');
    var nextBtn  = form.querySelector('[data-wizard-next]');
    var saveBtn  = form.querySelector('[data-wizard-save]');
    var countEl  = form.querySelector('[data-wizard-count]');
    var banner   = form.querySelector('[data-result-banner]');
    var bannerText = form.querySelector('[data-result-text]');
    var failNotes  = form.querySelector('[data-fail-notes]');

    var current = 0;

    // Server-side validation sends the tester back with errors; land them on
    // the first step that actually has one rather than at the beginning.
    steps.forEach(function (step, i) {
        if (step.querySelector('.field-error') && current === 0) current = i;
    });

    /* --- Progress rail ---------------------------------------------------- */

    steps.forEach(function (step, i) {
        var li = document.createElement('li');
        li.className = 'wizard-progress-step';
        li.textContent = step.getAttribute('data-step-name') || ('Step ' + (i + 1));
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

        Array.prototype.forEach.call(progress.children, function (li, i) {
            li.className = 'wizard-progress-step'
                + (i === current ? ' is-current' : '')
                + (i < current ? ' is-done' : '');
        });

        backBtn.hidden = current === 0;
        nextBtn.hidden = current === steps.length - 1;
        saveBtn.hidden = current !== steps.length - 1;

        if (countEl) countEl.textContent = 'Step ' + (current + 1) + ' of ' + steps.length;

        // Moving between steps on a phone should start at the top of the new one.
        if (form.getBoundingClientRect().top < 0) {
            form.scrollIntoView({ block: 'start' });
        }
    }

    backBtn.addEventListener('click', function () { show(current - 1); });

    nextBtn.addEventListener('click', function () {
        var unanswered = firstUnanswered(steps[current]);

        if (unanswered) {
            flag(unanswered);
            return;
        }

        show(current + 1);
    });

    /* --- Answering -------------------------------------------------------- */

    /** Every pass/fail group in a step, as { name: [inputs] }. */
    function groups(scope) {
        var found = {};

        Array.prototype.forEach.call(scope.querySelectorAll('.verdict-input'), function (input) {
            (found[input.name] = found[input.name] || []).push(input);
        });

        return found;
    }

    function answered(inputs) {
        return inputs.some(function (input) { return input.checked; });
    }

    function firstUnanswered(scope) {
        var found = groups(scope);

        for (var name in found) {
            if (Object.prototype.hasOwnProperty.call(found, name) && !answered(found[name])) {
                return found[name][0];
            }
        }

        // A reading is expected wherever a verdict is.
        var reading = scope.querySelector('input[type="number"][name$="_ohms"], input[type="number"][name$="_mohms"], input[type="number"][name$="_ma"]');
        if (reading && reading.value.trim() === '') return reading;

        return null;
    }

    function flag(input) {
        var row = input.closest('.check-row') || input.closest('.field');
        if (row) {
            row.classList.add('has-error');
            row.scrollIntoView({ block: 'center' });
        }

        if (input.type !== 'radio') input.focus();

        window.setTimeout(function () {
            if (row) row.classList.remove('has-error');
        }, 2000);
    }

    /* --- Result ----------------------------------------------------------- */

    function review() {
        var all = groups(form);
        var failedLabels = [];
        var outstanding = 0;

        for (var name in all) {
            if (!Object.prototype.hasOwnProperty.call(all, name)) continue;

            var inputs = all[name];

            if (!answered(inputs)) {
                outstanding++;
                continue;
            }

            var chosen = inputs.filter(function (i) { return i.checked; })[0];

            if (chosen.value === '0') {
                var row = chosen.closest('.check-row');
                var heading = row ? row.querySelector('h3') : null;
                failedLabels.push(heading ? heading.textContent.trim() : name);
            }
        }

        // Readings count as outstanding too — a verdict with no number is not
        // a completed test.
        Array.prototype.forEach.call(
            form.querySelectorAll('.check-row-test input[type="number"]'),
            function (input) { if (input.value.trim() === '') outstanding++; }
        );

        var complete = outstanding === 0;
        var passing  = complete && failedLabels.length === 0;

        if (banner) {
            banner.hidden = false;
            banner.className = 'result-banner ' + (
                !complete ? 'result-pending' : (passing ? 'result-pass' : 'result-fail')
            );

            if (!complete) {
                bannerText.textContent = outstanding === 1
                    ? '1 check still to answer before this test can be recorded.'
                    : outstanding + ' checks still to answer before this test can be recorded.';
            } else if (passing) {
                bannerText.textContent = 'Every applicable check passed. This will be recorded as a Pass.';
            } else {
                bannerText.textContent = 'Failed: ' + failedLabels.join(', ')
                    + '. This will be recorded as a Fail — one failed check fails the test.';
            }
        }

        if (failNotes) failNotes.hidden = !(complete && !passing);

        // The Pass is what gets unlocked: an incomplete test cannot be saved at
        // all, and a failing one saves as a Fail without further ceremony.
        saveBtn.disabled = !complete;
        saveBtn.textContent = !complete
            ? 'Answer every check to save'
            : (passing ? 'Record a Pass' : 'Record a Fail');
    }

    form.addEventListener('change', review);
    form.addEventListener('input', review);

    /* --- Live guideline for earth continuity ------------------------------ */

    var leadInput = form.querySelector('[data-guide-lead]');
    var earthGuide = form.querySelector('[data-guide-earth]');

    if (leadInput && earthGuide) {
        var base    = parseFloat(earthGuide.getAttribute('data-base') || '0.1');
        var perLead = parseFloat(earthGuide.getAttribute('data-per') || '0.1');
        var perM    = parseFloat(earthGuide.getAttribute('data-metres') || '7.5');
        var original = earthGuide.innerHTML;

        leadInput.addEventListener('input', function () {
            var metres = parseFloat(leadInput.value);

            if (!isFinite(metres) || metres <= 0) {
                earthGuide.innerHTML = original;
                return;
            }

            var limit = base + (metres / perM) * perLead;

            earthGuide.innerHTML = '<span class="guideline-tag">Guidance</span> Typically under '
                + (Math.round(limit * 1000) / 1000)
                + ' Ω for the appliance plus ' + metres
                + ' m of lead. Your judgement decides the result, not this figure.';
        });
    }

    /* --- Go --------------------------------------------------------------- */

    backBtn.hidden = false;
    nextBtn.hidden = false;
    show(current);
    review();
})();
