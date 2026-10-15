// Assessment: one question at a time, autosave, countdown.
//
// Every question is already in the page; this only shows one at a
// time. With JavaScript off they are all visible and the form still
// submits, so nothing here is load-bearing for correctness.
//
// The clock is not security. The server checks expires_at when the
// attempt is submitted, and the expiry sweep closes attempts that are
// never submitted at all. This is just so the candidate can see the
// time left.

document.addEventListener('DOMContentLoaded', function () {

    var exam = document.getElementById('exam');
    if (!exam) {
        return;
    }

    var cards    = Array.prototype.slice.call(exam.querySelectorAll('.question-card'));
    var total    = cards.length;
    var attempt  = exam.getAttribute('data-attempt');
    var form     = document.getElementById('exam-form');

    var prevBtn    = document.getElementById('prev-question');
    var nextBtn    = document.getElementById('next-question');
    var reviewBtn  = document.getElementById('open-submit');
    var panel      = document.getElementById('confirm-panel');
    var panelText  = document.getElementById('confirm-text');
    var cancelBtn  = document.getElementById('cancel-submit');
    var fill       = document.getElementById('progress-fill');
    var progress   = document.getElementById('progress-text');
    var answeredEl = document.getElementById('answered-count');
    var clock      = document.getElementById('exam-clock');

    var current = 0;

    // The server's clock, not the browser's: the offset corrects for a
    // device whose time is wrong.
    var expires = Number(exam.getAttribute('data-expires'));
    var offset  = Number(exam.getAttribute('data-now')) - Math.floor(Date.now() / 1000);

    function answeredCount() {
        var n = 0;
        for (var i = 0; i < cards.length; i++) {
            if (cards[i].querySelector('.answer-radio:checked')) {
                n++;
            }
        }
        return n;
    }

    function show(index) {
        if (index < 0 || index >= total) {
            return;
        }

        for (var i = 0; i < cards.length; i++) {
            cards[i].hidden = i !== index;
        }
        current = index;

        prevBtn.disabled = current === 0;

        // On the last question Next is removed entirely and replaced
        // by Submit Exam, so there is nothing to click past the end.
        var onLast = current === total - 1;
        nextBtn.hidden = onLast;
        reviewBtn.hidden = !onLast;

        var done = answeredCount();
        fill.style.width = Math.round(((current + 1) / total) * 100) + '%';
        progress.innerHTML = 'Question ' + (current + 1) + ' of ' + total
                           + ' &middot; <span id="answered-count">' + done + '</span> answered';
        answeredEl = document.getElementById('answered-count');

        panel.hidden = true;
    }

    // Save one answer. Failure is quiet on purpose: the form still
    // carries every answer on submit, so a dropped autosave only
    // costs the abandoned-attempt safety net.
    function save(questionId, optionId) {
        if (!window.fetch) {
            return;
        }

        var body = new FormData();
        body.append('action', 'save');
        body.append('attempt_id', attempt);
        body.append('question_id', questionId);
        body.append('option_id', optionId);

        fetch('candidate-assessment-submit.php', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.ok && answeredEl) {
                    answeredEl.textContent = data.answered;
                }
            })
            .catch(function () { /* form submit still carries it */ });
    }

    exam.addEventListener('change', function (event) {
        if (!event.target.classList.contains('answer-radio')) {
            return;
        }

        var card = event.target.closest('.question-card');

        var labels = card.querySelectorAll('.answer');
        for (var i = 0; i < labels.length; i++) {
            labels[i].classList.remove('chosen');
        }
        event.target.closest('.answer').classList.add('chosen');

        if (answeredEl) {
            answeredEl.textContent = answeredCount();
        }

        save(card.getAttribute('data-question'), event.target.value);
    });

    prevBtn.addEventListener('click', function () { show(current - 1); });
    nextBtn.addEventListener('click', function () { show(current + 1); });

    reviewBtn.addEventListener('click', function () {
        var done = answeredCount();
        var left = total - done;

        panelText.textContent = left === 0
            ? 'All ' + total + ' questions answered.'
            : left + ' of ' + total + ' questions are still unanswered. '
              + 'Unanswered questions score zero.';

        panel.hidden = false;

        if (typeof panel.scrollIntoView === 'function') {
            panel.scrollIntoView({ block: 'nearest' });
        }
    });

    cancelBtn.addEventListener('click', function () {
        panel.hidden = true;
    });

    // Enter must not submit from a radio: that would end the attempt
    // by accident.
    form.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && event.target.tagName !== 'BUTTON') {
            event.preventDefault();
        }
    });

    function tick() {
        var left = expires - (Math.floor(Date.now() / 1000) + offset);

        if (left <= 0) {
            clock.textContent = '0:00';
            clock.classList.add('clock-out');
            form.submit();
            return;
        }

        var minutes = Math.floor(left / 60);
        var seconds = left % 60;
        clock.textContent = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;

        clock.classList.toggle('clock-low', left <= 60);
        window.setTimeout(tick, 1000);
    }

    show(0);
    tick();
});