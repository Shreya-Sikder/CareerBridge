// Repeating rows for the profile page.
//
// Works on any container with class "row-list" and a data-prefix
// giving the POST array name, e.g. data-prefix="education" produces
// education[0][institution], education[1][institution] and so on.
//
// Every name has to be renumbered after an add or a remove. A gap in
// the indexes would silently drop a row on save.

document.addEventListener('DOMContentLoaded', function () {

    var maxRows = 15;

    function renumber(list) {
        var prefix = list.getAttribute('data-prefix');
        var rows = list.querySelectorAll('.repeat-row');

        for (var i = 0; i < rows.length; i++) {
            var label = rows[i].querySelector('.repeat-number');
            if (label) {
                label.textContent = 'Entry ' + (i + 1);
            }

            var fields = rows[i].querySelectorAll('[name]');
            for (var f = 0; f < fields.length; f++) {
                var name = fields[f].getAttribute('name');
                var pattern = new RegExp('^' + prefix + '\\[\\d+\\]');
                fields[f].setAttribute('name', name.replace(pattern, prefix + '[' + i + ']'));
            }
        }
    }

    function blankCopy(row) {
        var copy = row.cloneNode(true);
        var fields = copy.querySelectorAll('input, textarea');

        for (var i = 0; i < fields.length; i++) {
            if (fields[i].tagName === 'TEXTAREA') {
                fields[i].textContent = '';
            }
            fields[i].value = '';
        }
        return copy;
    }

    var addButtons = document.querySelectorAll('.add-row');

    for (var b = 0; b < addButtons.length; b++) {
        addButtons[b].addEventListener('click', function () {
            var list = document.getElementById(this.getAttribute('data-target'));
            if (!list) {
                return;
            }

            var rows = list.querySelectorAll('.repeat-row');
            if (rows.length >= maxRows) {
                return;
            }

            list.appendChild(blankCopy(rows[rows.length - 1]));
            renumber(list);

            var added = list.querySelectorAll('.repeat-row');
            var first = added[added.length - 1].querySelector('input');
            if (first) {
                first.focus();
            }
        });
    }

    var lists = document.querySelectorAll('.row-list');

    for (var l = 0; l < lists.length; l++) {
        lists[l].addEventListener('click', function (event) {
            if (!event.target.classList.contains('remove-row')) {
                return;
            }

            var rows = this.querySelectorAll('.repeat-row');

            // Never remove the last row: clear it instead, so the form
            // still has somewhere to type and an empty row is dropped
            // server-side anyway.
            if (rows.length <= 1) {
                var fields = rows[0].querySelectorAll('input, textarea');
                for (var i = 0; i < fields.length; i++) {
                    fields[i].value = '';
                }
                return;
            }

            event.target.closest('.repeat-row').remove();
            renumber(this);
        });

        renumber(lists[l]);
    }
});
