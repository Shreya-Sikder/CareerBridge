// Quiz builder: add and remove question blocks.
//
// The PHP side reads questions[0], questions[1], ... in order, so
// every name attribute has to be renumbered after any change. A gap
// in the indexes would silently drop questions on save.

document.addEventListener('DOMContentLoaded', function () {

    var list = document.getElementById('question-list');
    var template = document.getElementById('question-template');
    var addButton = document.getElementById('add-question');
    var maxQuestions = 30;

    if (!list || !template || !addButton) {
        return;
    }

    function renumber() {
        var blocks = list.querySelectorAll('.question-block');

        for (var i = 0; i < blocks.length; i++) {
            var block = blocks[i];

            var label = block.querySelector('.question-number');
            if (label) {
                label.textContent = 'Question ' + (i + 1);
            }

            var fields = block.querySelectorAll('[name]');
            for (var f = 0; f < fields.length; f++) {
                var name = fields[f].getAttribute('name');
                fields[f].setAttribute('name', name.replace(/^questions\[\d+\]/, 'questions[' + i + ']'));
            }
        }

        addButton.disabled = blocks.length >= maxQuestions;
    }

    addButton.addEventListener('click', function () {
        var blocks = list.querySelectorAll('.question-block');

        if (blocks.length >= maxQuestions) {
            return;
        }

        list.appendChild(template.content.cloneNode(true));
        renumber();

        var added = list.querySelectorAll('.question-block');
        var textarea = added[added.length - 1].querySelector('textarea');
        if (textarea) {
            textarea.focus();
        }
    });

    list.addEventListener('click', function (event) {
        if (!event.target.classList.contains('remove-question')) {
            return;
        }

        var blocks = list.querySelectorAll('.question-block');
        if (blocks.length <= 1) {
            return;
        }

        event.target.closest('.question-block').remove();
        renumber();
    });

    renumber();
});
