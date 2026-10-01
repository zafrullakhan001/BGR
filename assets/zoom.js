// Zoom slider, wheel zoom and drag-to-pan for the picture. The overlay canvas moves with the
// picture inside #view, and overlay.js reads its on-screen box, so layer editing stays aligned.
(() => {
    'use strict';

    const $ = (id) => document.getElementById(id);
    const stage = $('stage');
    const view = $('view');
    const img = $('img');
    const slider = $('zoom');
    const out = $('zoomOut');
    const MIN = +slider.min / 100;
    const MAX = +slider.max / 100;
    let s = 1, tx = 0, ty = 0;
    let pan = null;
    let size = '';

    function apply() {
        view.style.transform = s === 1 && !tx && !ty ? '' : `translate(${tx}px, ${ty}px) scale(${s})`;
        slider.value = Math.round(s * 100);
        out.value = Math.round(s * 100) + '%';
        stage.classList.toggle('zoomed', s > 1);
    }

    /** Zooms to scale n, keeping the screen point (px, py) over the same spot of the picture. */
    function zoomTo(n, px, py) {
        n = Math.min(MAX, Math.max(MIN, n));
        const r = view.getBoundingClientRect();
        const cx = r.left + r.width / 2 - tx;
        const cy = r.top + r.height / 2 - ty;
        px ??= cx + tx;
        py ??= cy + ty;
        tx = px - cx - (px - cx - tx) * n / s;
        ty = py - cy - (py - cy - ty) * n / s;
        s = n;
        if (s <= 1) tx = ty = 0;
        apply();
    }

    function reset() {
        s = 1;
        tx = ty = 0;
        apply();
    }

    slider.addEventListener('input', () => zoomTo(slider.value / 100));
    $('zoomReset').addEventListener('click', reset);

    view.addEventListener('wheel', (e) => {
        e.preventDefault();
        zoomTo(s * (e.deltaY < 0 ? 1.15 : 1 / 1.15), e.clientX, e.clientY);
    }, { passive: false });

    // Runs after overlay.js; it calls preventDefault when the press grabbed a layer or picked a colour.
    view.addEventListener('pointerdown', (e) => {
        if (e.defaultPrevented || s <= 1 || e.button !== 0) return;
        pan = { x: e.clientX - tx, y: e.clientY - ty, id: e.pointerId };
        view.setPointerCapture(e.pointerId);
        stage.classList.add('panning');
    });
    view.addEventListener('pointermove', (e) => {
        if (!pan || e.pointerId !== pan.id) return;
        tx = e.clientX - pan.x;
        ty = e.clientY - pan.y;
        apply();
    });
    ['pointerup', 'pointercancel'].forEach((ev) => view.addEventListener(ev, () => {
        pan = null;
        stage.classList.remove('panning');
    }));

    // A different picture starts at 100%; undo, redo and edits of the same picture keep the zoom.
    img.addEventListener('load', () => {
        const next = img.naturalWidth + 'x' + img.naturalHeight;
        if (next !== size) reset();
        size = next;
    });

    apply();
})();
