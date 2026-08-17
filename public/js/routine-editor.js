/* ==========================================================================
   The routine editor.

   One job: show a step's type-specific settings only when that type is
   chosen. The unit box belongs to a number and the choice list belongs to the
   two choice types, and showing all three at once on every step makes a page
   of six steps unreadable.

   Without this the fields are simply all visible — the template renders the
   hidden attribute from the saved type, and the server ignores whatever the
   chosen type has no use for, so nothing here is load-bearing.
   ========================================================================== */
(function () {
    'use strict';

    var rows = document.querySelectorAll('[data-step-editor]');
    if (!rows.length) return;

    Array.prototype.forEach.call(rows, function (row) {
        var select = row.querySelector('[data-step-type]');
        if (!select) return;

        var conditionals = Array.prototype.slice.call(row.querySelectorAll('[data-when-type]'));

        function sync() {
            conditionals.forEach(function (field) {
                var types = (field.getAttribute('data-when-type') || '').split(/\s+/);
                field.hidden = types.indexOf(select.value) === -1;
            });
        }

        select.addEventListener('change', sync);
        sync();
    });
})();
