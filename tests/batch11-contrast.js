// Browser-evaluated contrast checks for the newly styled text against actual ancestor colors.
// Gradient/photo backdrops and baseline decorative art are excluded from this solid-color check.
(() => {
    const rgb = value => (value.match(/[\d.]+/g) || []).map(Number);
    const luminance = value => {
        const c = value.map(n => { n /= 255; return n <= .04045 ? n / 12.92 : ((n + .055) / 1.055) ** 2.4; });
        return .2126 * c[0] + .7152 * c[1] + .0722 * c[2];
    };
    const selector = '.prism-record-summary dt,.prism-record-primary strong,.prism-record-details summary,.prism-filter-pill,.prism-row-size,.prism-calendar-legend span,.public-stages h3,.public-nav button';
    return [...document.querySelectorAll(selector)].filter(n => n.getClientRects().length).map(n => {
        const layers = [];
        for (let p = n; p; p = p.parentElement) {
            const s = getComputedStyle(p), c = rgb(s.backgroundColor);
            if (s.backgroundImage !== 'none') return { selector: n.className || n.tagName, excluded: 'image backdrop' };
            layers.push(c);
            if (c.length === 3 || c[3] === 1) break;
        }
        let background = [255,255,255];
        for (const c of layers.reverse()) { const alpha = c.length === 3 ? 1 : c[3]; background = background.map((v,i) => c[i] * alpha + v * (1-alpha)); }
        const foreground = rgb(getComputedStyle(n).color).slice(0,3), a = luminance(foreground), b = luminance(background);
        const ratio = (Math.max(a,b) + .05) / (Math.min(a,b) + .05);
        return { selector: n.className || n.tagName, text: n.textContent.trim().slice(0,70), ratio, passed: ratio >= 4.5 };
    });
})()
