(function () {
    'use strict';

    function reveal(img) {
        const avatar = img.closest('.watcher-avatar');
        if (avatar) {
            avatar.classList.add('has-image');
        }
    }

    function bind(root) {
        const images = [];
        if (root && root.matches && root.matches('[data-avatar-img]')) {
            images.push(root);
        }
        (root || document).querySelectorAll('[data-avatar-img]').forEach(function (img) {
            images.push(img);
        });
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
        new MutationObserver(function (records) {
            records.forEach(function (record) {
                record.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) {
                        bind(node);
                    }
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }
})();
