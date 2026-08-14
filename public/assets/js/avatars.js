(function () {
    'use strict';

    function reveal(img) {
        const avatar = img.closest('.watcher-avatar');
        if (avatar) {
            avatar.classList.add('has-image');
        }
    }

    function bind(root) {
        const images = (root || document).querySelectorAll('[data-avatar-img]');
        images.forEach(function (img) {
            if (img.dataset.avatarBound === '1') {
                return;
            }
            img.dataset.avatarBound = '1';

            img.addEventListener('load', function () {
                reveal(img);
            });
            img.addEventListener('error', function () {
                img.remove();
            });

            if (img.complete) {
                if (img.naturalWidth > 0) {
                    reveal(img);
                } else {
                    img.remove();
                }
            }
        });
    }

    bind();

    if (typeof MutationObserver === 'function' && document.body) {
        new MutationObserver(function () {
            bind();
        }).observe(document.body, { childList: true, subtree: true });
    }
})();
