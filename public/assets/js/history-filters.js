(function () {
    'use strict';

    var form = document.querySelector('[data-history-filter-form]');
    if (!form) {
        return;
    }

    form.addEventListener('formdata', function (event) {
        if (event.formData.get('range') !== 'custom') {
            event.formData.delete('start');
            event.formData.delete('end');
        }
    });
}());
