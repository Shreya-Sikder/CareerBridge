// Skill picker: add skills one at a time, no Ctrl or Shift.
//
// The whole available list arrives in data-skills as JSON, and the
// already-saved ids in data-selected. Everything below happens in the
// browser with no page reload.
//
// Selected skills are kept as hidden inputs named skills[], which is
// exactly what the existing PHP already reads. The backend is
// untouched.
//
// Without JavaScript the <noscript> block in the page falls back to
// the plain multi-select, so the section still works.

document.addEventListener('DOMContentLoaded', function () {

    var picker = document.getElementById('skill-picker');
    if (!picker) {
        return;
    }

    var search   = document.getElementById('skill-search');
    var select   = document.getElementById('skill-select');
    var addBtn   = document.getElementById('skill-add');
    var chipList = document.getElementById('selected-skills');
    var hidden   = document.getElementById('skill-hidden');
    var empty    = document.getElementById('skill-empty');
    var dirty    = document.getElementById('skill-dirty');

    var all      = JSON.parse(picker.getAttribute('data-skills') || '[]');
    var selected = JSON.parse(picker.getAttribute('data-selected') || '[]').map(Number);
    var original = selected.slice();

    function nameFor(id) {
        for (var i = 0; i < all.length; i++) {
            if (Number(all[i].id) === Number(id)) {
                return all[i].name;
            }
        }
        return '';
    }

    function isDirty() {
        if (selected.length !== original.length) {
            return true;
        }
        var a = selected.slice().sort(function (x, y) { return x - y; });
        var b = original.slice().sort(function (x, y) { return x - y; });
        for (var i = 0; i < a.length; i++) {
            if (a[i] !== b[i]) {
                return true;
            }
        }
        return false;
    }

    // Rebuild the dropdown with everything not already chosen, filtered
    // by whatever is typed in the search box.
    function refreshOptions() {
        var term = (search.value || '').toLowerCase();
        var previous = select.value;

        select.innerHTML = '';

        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Choose a skill';
        select.appendChild(placeholder);

        var shown = 0;

        for (var i = 0; i < all.length; i++) {
            var skill = all[i];

            if (selected.indexOf(Number(skill.id)) !== -1) {
                continue;
            }
            if (term !== '' && skill.name.toLowerCase().indexOf(term) === -1) {
                continue;
            }

            var option = document.createElement('option');
            option.value = skill.id;
            option.textContent = skill.name;
            select.appendChild(option);
            shown++;
        }

        if (shown === 0) {
            placeholder.textContent = term !== ''
                ? 'No skills match that search'
                : 'All skills added';
        }

        select.disabled = shown === 0;
        addBtn.disabled = shown === 0;

        // Keep the user's choice if it is still in the list.
        if (previous) {
            select.value = previous;
            if (select.value !== previous) {
                select.value = '';
            }
        }
    }

    function refreshChips() {
        chipList.innerHTML = '';
        hidden.innerHTML = '';

        for (var i = 0; i < selected.length; i++) {
            var id = selected[i];

            var chip = document.createElement('span');
            chip.className = 'chip';

            var label = document.createElement('span');
            label.className = 'chip-label';
            label.textContent = nameFor(id);
            chip.appendChild(label);

            var remove = document.createElement('button');
            remove.className = 'chip-remove';
            remove.type = 'button';
            remove.setAttribute('data-id', id);
            remove.setAttribute('aria-label', 'Remove ' + nameFor(id));
            remove.textContent = '\u00d7';
            chip.appendChild(remove);

            chipList.appendChild(chip);

            var field = document.createElement('input');
            field.type = 'hidden';
            field.name = 'skills[]';
            field.value = id;
            hidden.appendChild(field);
        }

        if (empty) {
            empty.hidden = selected.length > 0;
        }
        if (dirty) {
            dirty.hidden = !isDirty();
        }
    }

    function addSelected() {
        var id = Number(select.value);
        if (!id) {
            return;
        }
        if (selected.indexOf(id) === -1) {
            selected.push(id);
        }
        search.value = '';
        refreshChips();
        refreshOptions();
        search.focus();
    }

    addBtn.addEventListener('click', addSelected);

    // Picking from the dropdown adds it straight away.
    select.addEventListener('change', function () {
        if (select.value) {
            addSelected();
        }
    });

    // Enter in the search box adds the first match instead of
    // submitting the form.
    search.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') {
            return;
        }
        event.preventDefault();

        if (select.options.length > 1) {
            select.value = select.options[1].value;
            addSelected();
        }
    });

    search.addEventListener('input', refreshOptions);

    chipList.addEventListener('click', function (event) {
        if (!event.target.classList.contains('chip-remove')) {
            return;
        }

        var id = Number(event.target.getAttribute('data-id'));
        var at = selected.indexOf(id);

        if (at !== -1) {
            selected.splice(at, 1);
        }

        refreshChips();
        refreshOptions();
    });

    // The no-JS fallback select must not post alongside the hidden
    // inputs, or every skill would be sent twice.
    var fallback = document.getElementById('skills-fallback');
    if (fallback) {
        fallback.parentNode.removeChild(fallback);
    }

    picker.hidden = false;
    refreshChips();
    refreshOptions();
});