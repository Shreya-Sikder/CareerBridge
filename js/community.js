// Unified composer: one box, with attachment buttons that open a
// hidden file input or reveal a link field, and a preview of whatever
// has been chosen before posting.
//
// Without JavaScript the file inputs are still in the form and still
// submit; they just are not hidden behind a button.

document.addEventListener('DOMContentLoaded', function () {

    var form = document.getElementById('composer-form');
    if (!form) {
        return;
    }

    var preview = document.getElementById('attach-preview');

    function refreshPreview() {
        var any = false;
        var items = preview.querySelectorAll('.preview-item');

        for (var i = 0; i < items.length; i++) {
            if (!items[i].hidden) {
                any = true;
            }
        }
        preview.hidden = !any;
    }

    function showItem(id, name, thumbUrl) {
        var item = document.getElementById(id);
        if (!item) {
            return;
        }

        item.hidden = name === '';
        var label = item.querySelector('.preview-name');
        if (label) {
            label.textContent = name;
        }

        if (thumbUrl) {
            var img = item.querySelector('.preview-thumb');
            if (img) {
                img.src = thumbUrl;
            }
        }
        refreshPreview();
    }

    // "Photo" and "File" buttons open their hidden input.
    var openers = form.querySelectorAll('[data-open]');
    for (var o = 0; o < openers.length; o++) {
        openers[o].addEventListener('click', function () {
            var input = document.getElementById(this.getAttribute('data-open'));
            if (input) {
                input.click();
            }
        });
    }

    // "Video" and "Job" buttons reveal a field instead.
    var togglers = form.querySelectorAll('[data-toggle]');
    for (var t = 0; t < togglers.length; t++) {
        togglers[t].addEventListener('click', function () {
            var field = document.getElementById(this.getAttribute('data-toggle'));
            if (!field) {
                return;
            }
            field.hidden = !field.hidden;

            if (!field.hidden) {
                var input = field.querySelector('input, select');
                if (input) {
                    input.focus();
                }
            }
        });
    }

    var image = document.getElementById('post-image');
    if (image) {
        image.addEventListener('change', function () {
            if (!this.files || !this.files.length) {
                showItem('preview-image', '');
                return;
            }

            var file = this.files[0];
            var url = window.URL && window.URL.createObjectURL
                ? window.URL.createObjectURL(file)
                : null;

            showItem('preview-image', file.name, url);
        });
    }

    var attachment = document.getElementById('post-attachment');
    if (attachment) {
        attachment.addEventListener('change', function () {
            showItem('preview-file', this.files && this.files.length ? this.files[0].name : '');
        });
    }

    var video = document.getElementById('post-video');
    if (video) {
        video.addEventListener('input', function () {
            showItem('preview-video', this.value.trim());
        });
    }

    var job = document.getElementById('post-job');
    if (job) {
        job.addEventListener('change', function () {
            var chosen = this.value !== '0' && this.value !== ''
                ? this.options[this.selectedIndex].textContent.trim()
                : '';
            showItem('preview-job', chosen);
        });
    }

    // Remove buttons clear whichever input they belong to.
    preview.addEventListener('click', function (event) {
        if (!event.target.classList.contains('preview-remove')) {
            return;
        }

        var input = document.getElementById(event.target.getAttribute('data-clear'));
        if (!input) {
            return;
        }

        if (input.tagName === 'SELECT') {
            input.value = '0';
        } else {
            input.value = '';
        }

        event.target.closest('.preview-item').hidden = true;
        refreshPreview();
    });

    refreshPreview();
});