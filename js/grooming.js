// Highlights the selected grooming option. Nothing here is required:
// the radios submit correctly without it.

document.addEventListener('DOMContentLoaded', function () {

    var list = document.querySelector('.choice-list');
    if (!list) {
        return;
    }

    list.addEventListener('change', function (event) {
        if (!event.target.classList.contains('choice-radio')) {
            return;
        }

        var choices = list.querySelectorAll('.choice');
        for (var i = 0; i < choices.length; i++) {
            choices[i].classList.remove('chosen');
        }
        event.target.closest('.choice').classList.add('chosen');

        // Typing a link should also select the custom option.
        var url = document.getElementById('grooming_url');
        if (url && event.target.value !== 'custom') {
            url.blur();
        }
    });

    var url = document.getElementById('grooming_url');
    if (url) {
        url.addEventListener('focus', function () {
            var radio = list.querySelector('.choice-radio[value="custom"]');
            if (radio && !radio.checked) {
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }
});