document.querySelectorAll('.stats-trend-bars').forEach((chart) => {
    const bars = [...chart.children];
    const labels = bars.map((bar) => bar.querySelector('b'));
    const fitLabels = () => {
        labels.forEach((label) => { label.hidden = false; });
        if (labels.length < 2) return;
        const bounds = labels.map((label) => label.getBoundingClientRect());
        const last = bounds.length - 1;
        let right = bounds[0].right;
        for (let index = 1; index < last; index += 1) {
            const fits = bounds[index].left >= right + 12
                && bounds[index].right + 12 <= bounds[last].left;
            labels[index].hidden = !fits;
            if (fits) right = bounds[index].right;
        }
        labels[last].hidden = bounds[last].left < right + 12;
    };
    const detail = chart.nextElementSibling;
    bars.forEach((bar) => {
        const show = () => { detail.textContent = bar.getAttribute('data-trend-detail') || bar.getAttribute('aria-label'); };
        bar.addEventListener('focus', show);
        bar.addEventListener('pointerenter', show);
        bar.addEventListener('click', show);
    });
    new ResizeObserver(fitLabels).observe(chart);
    document.fonts.ready.then(fitLabels);
    fitLabels();
});
