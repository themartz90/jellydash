(function () {
    const content = document.querySelector('.dashboard-content');
    const stateKey = 'jellydashContentScrollTop';

    if (!content || !window.history?.replaceState) {
        return;
    }

    function savePosition() {
        const currentState = window.history.state;
        const nextState = currentState && typeof currentState === 'object' && !Array.isArray(currentState)
            ? { ...currentState }
            : {};

        nextState[stateKey] = content.scrollTop;

        try {
            window.history.replaceState(nextState, '', window.location.href);
        } catch (error) {
            // A browser that rejects history state still keeps normal navigation working.
        }
    }

    function restorePosition() {
        const position = window.history.state?.[stateKey];
        if (!Number.isFinite(position) || position < 0) {
            return;
        }

        window.requestAnimationFrame(() => {
            content.scrollTop = position;
        });
    }

    window.addEventListener('pagehide', savePosition);
    window.addEventListener('pageshow', restorePosition);
}());
