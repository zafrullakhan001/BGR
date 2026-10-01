// Text, logo and watermark layers drawn on a canvas over the picture.
// The same draw() renders the on-screen preview and the final full-size picture.
window.Overlay = (() => {
    'use strict';

    const $ = (id) => document.getElementById(id);
    const LINE_HEIGHT = 1.2;
    const PAD = 6;
    const img = $('img');
    const canvas = $('overlay');
    const ctx = canvas.getContext('2d');
    const items = [];
    let selected = null;
    let drag = null;
    let picker = null;
    let pendingDefaults = true;
    const handleSize = () => 9 * (window.devicePixelRatio || 1);

    const f = {
        text: $('ovText'), font: $('ovFont'), size: $('ovSize'), color: $('ovColor'),
        bold: $('ovBold'), italic: $('ovItalic'), outline: $('ovShadow'),
        opacity: $('ovOpacity'), rotate: $('ovRotate'), tile: $('ovTile'), imgSize: $('ovImgSize'),
    };

    const fontOf = (it, s) => `${it.italic ? 'italic ' : ''}${it.bold ? 700 : 400} ${it.size * s}px "${it.font}"`;

    function measure(c, it, W, s) {
        if (it.type === 'image') {
            const w = it.width * W;
            return { w, h: w * it.bitmap.height / it.bitmap.width };
        }
        c.font = fontOf(it, s);
        const lines = it.text.split('\n');
        return {
            lines,
            w: Math.max(1, ...lines.map((l) => c.measureText(l).width)),
            h: lines.length * it.size * s * LINE_HEIGHT,
        };
    }

    function shape(c, it, box, s) {
        if (it.type === 'image') {
            c.drawImage(it.bitmap, -box.w / 2, -box.h / 2, box.w, box.h);
            return;
        }
        c.font = fontOf(it, s);
        c.textAlign = 'center';
        c.textBaseline = 'middle';
        c.fillStyle = it.color;
        const lh = it.size * s * LINE_HEIGHT;
        box.lines.forEach((line, i) => {
            const y = -box.h / 2 + lh * (i + 0.5);
            if (it.outline) {
                c.lineJoin = 'round';
                c.lineWidth = Math.max(1, it.size * s * 0.12);
                c.strokeStyle = 'rgba(0, 0, 0, 0.75)';
                c.strokeText(line, 0, y);
            }
            c.fillText(line, 0, y);
        });
    }

    /** s = canvas pixels per picture pixel; preview adds the selection outline. */
    function draw(c, W, H, s, preview) {
        for (const it of items) {
            const box = measure(c, it, W, s);
            const rad = it.rotate * Math.PI / 180;
            c.save();
            c.globalAlpha = it.opacity;
            if (it.tile) {
                c.translate(W / 2, H / 2);
                c.rotate(rad);
                const reach = Math.hypot(W, H) / 2;
                const sx = box.w + Math.max(box.w * 0.6, 40 * s);
                const sy = box.h * 2.5 + 20 * s;
                for (let y = -reach, row = 0; y <= reach + sy; y += sy, row++) {
                    for (let x = -reach - (row % 2) * sx / 2; x <= reach + sx; x += sx) {
                        c.save();
                        c.translate(x, y);
                        shape(c, it, box, s);
                        c.restore();
                    }
                }
            } else {
                c.translate(it.x * W, it.y * H);
                c.rotate(rad);
                shape(c, it, box, s);
                if (preview && it === selected) {
                    const hw = box.w / 2 + PAD;
                    const hh = box.h / 2 + PAD;
                    const hs = handleSize();
                    c.globalAlpha = 1;
                    c.setLineDash([6, 4]);
                    c.lineWidth = 1.5;
                    c.strokeStyle = '#FFC857';
                    c.strokeRect(-hw, -hh, hw * 2, hh * 2);
                    c.setLineDash([]);
                    c.fillStyle = '#fff';
                    for (const [x, y] of [[-hw, -hh], [hw, -hh], [-hw, hh], [hw, hh]]) {
                        c.fillRect(x - hs / 2, y - hs / 2, hs, hs);
                        c.strokeRect(x - hs / 2, y - hs / 2, hs, hs);
                    }
                }
            }
            c.restore();
        }
    }

    function redraw() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        if (img.naturalWidth) draw(ctx, canvas.width, canvas.height, canvas.width / img.naturalWidth, true);
    }

    function fitCanvas() {
        const dpr = window.devicePixelRatio || 1;
        canvas.width = Math.round(img.clientWidth * dpr);
        canvas.height = Math.round(img.clientHeight * dpr);
        redraw();
    }

    function pointer(e) {
        const r = canvas.getBoundingClientRect();
        return [(e.clientX - r.left) * canvas.width / r.width, (e.clientY - r.top) * canvas.height / r.height];
    }

    /** Pointer position in the layer's own unrotated coordinates, centred on the layer. */
    function local(it, px, py) {
        const box = measure(ctx, it, canvas.width, canvas.width / img.naturalWidth);
        const dx = px - it.x * canvas.width;
        const dy = py - it.y * canvas.height;
        const r = -it.rotate * Math.PI / 180;
        return { box, lx: dx * Math.cos(r) - dy * Math.sin(r), ly: dx * Math.sin(r) + dy * Math.cos(r) };
    }

    function hit(px, py) {
        for (let i = items.length - 1; i >= 0; i--) {
            const it = items[i];
            if (it.tile) continue;
            const { box, lx, ly } = local(it, px, py);
            if (Math.abs(lx) <= box.w / 2 + PAD && Math.abs(ly) <= box.h / 2 + PAD) return it;
        }
        return null;
    }

    function onHandle(px, py) {
        if (!selected || selected.tile) return false;
        const { box, lx, ly } = local(selected, px, py);
        const reach = handleSize();
        return Math.abs(Math.abs(lx) - box.w / 2 - PAD) <= reach && Math.abs(Math.abs(ly) - box.h / 2 - PAD) <= reach;
    }

    /** Snaps the selected layer to a corner, edge or the centre; col and row are 0, 1 or 2. */
    function place(col, row) {
        if (!selected || !img.naturalWidth) return;
        selected.tile = false;
        const W = canvas.width;
        const H = canvas.height;
        const box = measure(ctx, selected, W, W / img.naturalWidth);
        const r = selected.rotate * Math.PI / 180;
        const bw = Math.abs(box.w * Math.cos(r)) + Math.abs(box.h * Math.sin(r));
        const bh = Math.abs(box.w * Math.sin(r)) + Math.abs(box.h * Math.cos(r));
        const margin = Math.min(W, H) * 0.03;
        const at = (i, size, total) => [margin + size / 2, total / 2, total - margin - size / 2][i] / total;
        selected.x = at(col, bw, W);
        selected.y = at(row, bh, H);
        writeControls(selected);
        renderLayers();
        redraw();
    }

    function syncOutputs() {
        $('ovOpacityOut').value = f.opacity.value + '%';
        $('ovRotateOut').value = f.rotate.value + '°';
        $('ovImgSizeOut').value = f.imgSize.value + '%';
    }

    function writeControls(it) {
        if (it.type === 'text') {
            f.text.value = it.text;
            f.font.value = it.font;
            f.size.value = it.size;
            f.color.value = it.color;
            f.bold.checked = it.bold;
            f.italic.checked = it.italic;
            f.outline.checked = it.outline;
        } else {
            f.imgSize.value = Math.round(it.width * 100);
        }
        f.opacity.value = Math.round(it.opacity * 100);
        f.rotate.value = it.rotate;
        f.tile.checked = it.tile;
        syncOutputs();
    }

    function readControls(it) {
        if (it.type === 'text') {
            it.text = f.text.value || ' ';
            it.font = f.font.value;
            it.size = Math.max(6, Math.min(2000, +f.size.value || 12));
            it.color = f.color.value;
            it.bold = f.bold.checked;
            it.italic = f.italic.checked;
            it.outline = f.outline.checked;
        } else {
            it.width = f.imgSize.value / 100;
        }
        it.opacity = f.opacity.value / 100;
        it.rotate = +f.rotate.value;
        it.tile = f.tile.checked;
    }

    function textItem(extra = {}) {
        const it = { type: 'text', x: 0.5, y: 0.3 + (items.length % 5) * 0.1 };
        readControls(it);
        it.text = f.text.value.trim() || 'Your text';
        return Object.assign(it, extra);
    }

    function renderLayers() {
        $('layers').replaceChildren(...items.map((it) => {
            const li = document.createElement('li');
            li.className = it === selected ? 'selected' : '';
            const pick = document.createElement('button');
            pick.type = 'button';
            pick.className = 'pick';
            const kind = it.type === 'text' ? 'Text' : 'Image';
            const name = it.type === 'text' ? it.text.split('\n')[0].slice(0, 28) : it.name;
            pick.textContent = `${kind}: ${name}${it.tile ? ' (repeated)' : ''}`;
            pick.addEventListener('click', () => select(it));
            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'del';
            del.setAttribute('aria-label', `Remove ${kind.toLowerCase()} layer`);
            del.innerHTML = '<svg><use href="#i-x"/></svg>';
            del.addEventListener('click', () => remove(it));
            li.append(pick, del);
            return li;
        }));
    }

    function select(it) {
        selected = it;
        if (it) writeControls(it);
        const isImage = it?.type === 'image';
        $('ovTextFields').hidden = isImage;
        $('ovImageFields').hidden = !isImage;
        renderLayers();
        redraw();
    }

    function add(it) {
        items.push(it);
        select(it);
        if (it.type === 'text') document.fonts.load(fontOf(it, 1)).then(redraw);
    }

    function remove(it) {
        items.splice(items.indexOf(it), 1);
        select(items[items.length - 1] || null);
    }

    function clear(resetDefaults = false) {
        items.length = 0;
        drag = null;
        if (resetDefaults) pendingDefaults = true;
        select(null);
    }

    // Controls edit the selected layer, or set defaults for the next one.
    Object.values(f).forEach((el) => el.addEventListener('input', () => {
        syncOutputs();
        if (!selected) return;
        readControls(selected);
        if (selected.type === 'text') document.fonts.load(fontOf(selected, 1)).then(redraw);
        renderLayers();
        redraw();
    }));

    function setLook(opacity, rotate, tile) {
        f.opacity.value = opacity;
        f.rotate.value = rotate;
        f.tile.checked = tile;
        syncOutputs();
    }

    $('addText').addEventListener('click', () => {
        setLook(100, 0, false);
        add(textItem());
    });
    $('addWmText').addEventListener('click', () => {
        const text = f.text.value.trim();
        if (!text || text === 'Your text') f.text.value = 'DRAFT';
        setLook(25, -30, true);
        add(textItem());
        f.text.focus();
        f.text.select();
        $('status').textContent = 'Type your watermark text. Untick "Repeat" to show it once.';
    });

    function imageInput(id, look) {
        $(id).addEventListener('change', async (e) => {
            const file = e.target.files[0];
            e.target.value = '';
            if (!file) return;
            try {
                const bitmap = await createImageBitmap(file);
                add({ type: 'image', bitmap, name: file.name, rotate: 0, tile: false, ...look });
                $('status').textContent = 'Drag the image to move it, drag a corner to resize it.';
            } catch (err) {
                $('status').textContent = 'That image could not be read.';
            }
        });
    }
    imageInput('logoFile', { x: 0.5, y: 0.5, width: 0.3, opacity: 1 });
    imageInput('wmFile', { x: 0.82, y: 0.85, width: 0.25, opacity: 0.6 });

    document.querySelectorAll('#place button').forEach((b) => b.addEventListener('click', () => place(+b.dataset.x, +b.dataset.y)));

    canvas.addEventListener('pointerdown', (e) => {
        if (picker) {
            const r = canvas.getBoundingClientRect();
            picker((e.clientX - r.left) / r.width, (e.clientY - r.top) / r.height);
            e.preventDefault();
            return;
        }
        const [px, py] = pointer(e);
        if (onHandle(px, py)) {
            // The opposite corner stays put while the grabbed corner follows the pointer.
            const it = selected;
            const cx = it.x * canvas.width;
            const cy = it.y * canvas.height;
            const ox = 2 * cx - px;
            const oy = 2 * cy - py;
            drag = { it, resize: true, ox, oy, cx, cy, d0: Math.max(1, Math.hypot(px - ox, py - oy)), width: it.width, size: it.size };
        } else {
            const it = hit(px, py);
            select(it);
            if (!it) return;
            drag = { it, dx: px - it.x * canvas.width, dy: py - it.y * canvas.height };
        }
        canvas.setPointerCapture(e.pointerId);
        e.preventDefault();
    });
    canvas.addEventListener('pointermove', (e) => {
        if (picker) return;
        const [px, py] = pointer(e);
        if (!drag) {
            canvas.style.cursor = onHandle(px, py) ? 'nwse-resize' : hit(px, py) ? 'move' : 'default';
            return;
        }
        const it = drag.it;
        if (drag.resize) {
            let ratio = Math.hypot(px - drag.ox, py - drag.oy) / drag.d0;
            if (it.type === 'image') {
                it.width = Math.min(3, Math.max(0.02, drag.width * ratio));
                ratio = it.width / drag.width;
            } else {
                it.size = Math.min(2000, Math.max(6, Math.round(drag.size * ratio)));
                ratio = it.size / drag.size;
            }
            it.x = (drag.ox + (drag.cx - drag.ox) * ratio) / canvas.width;
            it.y = (drag.oy + (drag.cy - drag.oy) * ratio) / canvas.height;
            writeControls(it);
        } else {
            it.x = Math.min(1, Math.max(0, (px - drag.dx) / canvas.width));
            it.y = Math.min(1, Math.max(0, (py - drag.dy) / canvas.height));
        }
        redraw();
    });
    ['pointerup', 'pointercancel'].forEach((ev) => canvas.addEventListener(ev, () => { drag = null; }));

    document.addEventListener('keydown', (e) => {
        if ((e.key === 'Delete' || e.key === 'Backspace') && selected && !e.target.matches('input, textarea, select')) {
            e.preventDefault();
            remove(selected);
        }
    });

    img.addEventListener('load', () => {
        if (pendingDefaults && img.naturalWidth) {
            f.size.value = Math.max(12, Math.round(img.naturalWidth * 0.06));
            pendingDefaults = false;
        }
        fitCanvas();
    });
    new ResizeObserver(fitCanvas).observe(img);
    document.fonts.ready.then(redraw);
    syncOutputs();

    return {
        has: () => items.length > 0,
        draw: (c, W, H) => draw(c, W, H, 1, false),
        clear,
        setVisible: (on) => { canvas.style.visibility = on ? '' : 'hidden'; },
        /** fn(x, y) gets the clicked spot as 0–1 fractions of the picture; null turns picking off. */
        setPicker: (fn) => {
            picker = fn;
            canvas.style.cursor = fn ? 'crosshair' : 'default';
        },
    };
})();
