(() => {
    'use strict';

    const $ = (id) => document.getElementById(id);
    const csrf = document.querySelector('meta[name="csrf"]').content;
    const types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp'];
    const iconPacks = ['favicon', 'pwa', 'android', 'ios', 'appicons'];
    const hints = {
        png: 'Keeps transparency.',
        webp: 'Keeps transparency. Smaller files than PNG.',
        jpg: 'Transparent areas become white.',
        gif: 'Transparent areas become white. 256 colours.',
        bmp: 'Transparent areas become white.',
        ico: 'One favicon.ico holding 16 to 256 px sizes. Keeps transparency.',
        favicon: 'favicon.ico, PNG favicons, Apple touch icon, web manifest, and the HTML tags to paste into your page head.',
        pwa: 'Standard and maskable 192 and 512 px icons, plus a manifest.webmanifest ready to edit.',
        android: 'Launcher icons for every screen density, round and adaptive layers, and the 512 px Play Store icon. Copy res/ into your app.',
        ios: 'AppIcon.appiconset with the 1024 px icon Xcode needs. iOS fills transparency with the icon background.',
        appicons: 'Favicon, PWA, Android and iOS sets together in one ZIP.',
    };
    const labels = {
        rotate_left: 'Rotate left',
        rotate_right: 'Rotate right',
        grayscale: 'Black and white',
        clean: 'Clean drawing',
        cutout: 'Remove background',
        colorkey: 'Remove colour',
    };
    const busyText = {
        rotate_left: 'Rotating…',
        rotate_right: 'Rotating…',
        grayscale: 'Converting to black and white…',
        clean: 'Cleaning drawing…',
        colorkey: 'Removing the colour…',
        cutout: 'Removing the background…',
        export: 'Preparing download…',
    };

    const img = $('img');
    const stage = $('stage');
    const brightness = $('brightness');
    const contrast = $('contrast');
    const sharpness = $('sharpness');
    const format = $('format');
    const controlSelector = '.block button:not(#theme), .block select, .block textarea, .block input:not(#file)';

    // Each step is { label, blob, url }; pos points at the step on screen.
    const state = { steps: [], pos: -1, name: 'picture', busy: false, keyManual: false };
    const current = () => state.steps[state.pos];
    const slidersMoved = () => brightness.value !== '100' || contrast.value !== '100' || sharpness.value !== '0';

    function status(text, kind = '') {
        const el = $('status');
        el.textContent = text;
        el.className = 'status ' + kind;
    }

    function setEnabled(on) {
        on = on && state.steps.length > 0;
        document.querySelectorAll(controlSelector).forEach((c) => { c.disabled = !on; });
        document.querySelectorAll('.file-btn').forEach((l) => l.classList.toggle('is-disabled', !on));
        if (!on) stopPicking();
        $('undo').disabled = !on || (state.pos <= 0 && !slidersMoved());
        $('redo').disabled = !on || state.pos >= state.steps.length - 1;
    }

    function filter() {
        const sharp = sharpness.value > 0 ? ' url(#sharpen)' : '';
        return `brightness(${brightness.value}%) contrast(${contrast.value}%)${sharp}`;
    }

    function applyFilter() {
        const a = sharpness.value / 100;
        $('sharpenKernel').setAttribute('kernelMatrix', `0 ${-a} 0 ${-a} ${1 + 4 * a} ${-a} 0 ${-a} 0`);
        $('bOut').value = brightness.value + '%';
        $('cOut').value = contrast.value + '%';
        $('sOut').value = sharpness.value + '%';
        img.style.filter = filter();
        if (!state.busy) setEnabled(true);
    }

    function resetSliders() {
        brightness.value = 100;
        contrast.value = 100;
        sharpness.value = 0;
        applyFilter();
    }

    /** Full-size picture with slider adjustments and text/watermark layers burned in. */
    function composite() {
        const c = document.createElement('canvas');
        c.width = img.naturalWidth;
        c.height = img.naturalHeight;
        const g = c.getContext('2d');
        g.filter = filter();
        g.drawImage(img, 0, 0);
        g.filter = 'none';
        Overlay.draw(g, c.width, c.height);
        return c;
    }

    // Colour sampling works on a reduced copy with the slider adjustments, matching what the server receives.
    let sample = null;
    function pixels() {
        const key = img.src + filter();
        if (sample?.key === key) return sample;
        const scale = Math.min(1, 1200 / Math.max(img.naturalWidth, img.naturalHeight));
        const w = Math.max(1, Math.round(img.naturalWidth * scale));
        const h = Math.max(1, Math.round(img.naturalHeight * scale));
        const g = Object.assign(document.createElement('canvas'), { width: w, height: h }).getContext('2d', { willReadFrequently: true });
        g.filter = filter();
        g.drawImage(img, 0, 0, w, h);
        sample = { key, w, h, data: g.getImageData(0, 0, w, h).data };
        return sample;
    }

    const hex = (r, g, b) => '#' + [r, g, b].map((v) => Math.round(v).toString(16).padStart(2, '0')).join('');

    function setKeyColor(value, hint) {
        $('keyColor').value = value;
        $('keyHex').value = value;
        $('keyHint').textContent = hint;
    }

    /** Most common opaque colour along the picture edges. */
    function autoColor(manual) {
        if (!img.naturalWidth) return;
        const { w, h, data } = pixels();
        const buckets = new Map();
        let total = 0;
        const visit = (x, y) => {
            const i = (y * w + x) * 4;
            if (data[i + 3] < 128) return;
            const k = (data[i] >> 4) << 8 | (data[i + 1] >> 4) << 4 | data[i + 2] >> 4;
            const b = buckets.get(k) || { n: 0, r: 0, g: 0, b: 0 };
            b.n++; b.r += data[i]; b.g += data[i + 1]; b.b += data[i + 2];
            buckets.set(k, b);
            total++;
        };
        for (let x = 0; x < w; x++) { visit(x, 0); visit(x, h - 1); }
        for (let y = 1; y < h - 1; y++) { visit(0, y); visit(w - 1, y); }
        if (!total) {
            $('keyHint').textContent = 'The edges are already transparent.';
            return;
        }
        const top = [...buckets.values()].sort((a, b) => b.n - a.n)[0];
        const value = hex(top.r / top.n, top.g / top.n, top.b / top.n);
        const share = Math.round(top.n / total * 100);
        setKeyColor(value, share >= 40
            ? `Detected ${value} on ${share}% of the edges. Press Remove this colour.`
            : `The edges are mixed; ${value} is only on ${share}% of them. Use Pick to choose the colour yourself.`);
        state.keyManual = false;
        if (manual) status(`Background colour detected: ${value}.`);
    }

    function stopPicking() {
        $('pickColor').setAttribute('aria-pressed', 'false');
        Overlay.setPicker(null);
    }

    function startPicking() {
        $('pickColor').setAttribute('aria-pressed', 'true');
        status('Click the colour to remove on the picture. Esc cancels.');
        Overlay.setPicker((fx, fy) => {
            const { w, h, data } = pixels();
            const cx = Math.min(w - 1, Math.floor(fx * w));
            const cy = Math.min(h - 1, Math.floor(fy * h));
            let r = 0, g = 0, b = 0, n = 0;
            for (let y = Math.max(0, cy - 1); y <= Math.min(h - 1, cy + 1); y++) {
                for (let x = Math.max(0, cx - 1); x <= Math.min(w - 1, cx + 1); x++) {
                    const i = (y * w + x) * 4;
                    r += data[i]; g += data[i + 1]; b += data[i + 2]; n++;
                }
            }
            const value = hex(r / n, g / n, b / n);
            setKeyColor(value, `Picked ${value}. Press Remove this colour.`);
            state.keyManual = true;
            stopPicking();
            status(`Picked ${value}.`);
        });
    }

    async function applyOverlays() {
        if (!Overlay.has()) {
            status('Add text or a watermark first.');
            return;
        }
        const blob = await new Promise((ok) => composite().toBlob(ok, 'image/png'));
        Overlay.clear();
        push('Text and watermark', blob);
        status('Text and watermarks applied. Undo removes them again.');
    }

    function renderHistory() {
        const list = $('history');
        list.replaceChildren(...state.steps.map((step, i) => {
            const li = document.createElement('li');
            li.className = i === state.pos ? 'current' : i > state.pos ? 'future' : '';
            const btn = document.createElement('button');
            btn.type = 'button';
            const thumb = document.createElement('img');
            thumb.src = step.url;
            thumb.alt = '';
            const label = document.createElement('span');
            label.textContent = step.label;
            const n = document.createElement('span');
            n.className = 'n';
            n.textContent = i === 0 ? '' : `Step ${i}`;
            btn.append(thumb, label, n);
            if (i === state.pos) btn.setAttribute('aria-current', 'step');
            btn.addEventListener('click', () => { go(i); status(`Showing: ${step.label}.`); });
            li.append(btn);
            return li;
        }));
        const cur = list.querySelector('.current');
        if (cur) list.scrollTop = cur.offsetTop - (list.clientHeight - cur.offsetHeight) / 2;
    }

    function go(i) {
        state.pos = i;
        resetSliders();
        img.src = current().url;
        $('drop').hidden = true;
        $('view').hidden = false;
        renderHistory();
        setEnabled(true);
    }

    function push(label, blob) {
        state.steps.splice(state.pos + 1).forEach((s) => URL.revokeObjectURL(s.url));
        state.steps.push({ label, blob, url: URL.createObjectURL(blob) });
        go(state.steps.length - 1);
    }

    function undo() {
        if (state.busy || !state.steps.length) return;
        if (slidersMoved()) {
            resetSliders();
            status('Slider changes undone.');
        } else if (state.pos > 0) {
            go(state.pos - 1);
            status(`Undone. Showing: ${current().label}.`);
        }
    }

    function redo() {
        if (state.busy || state.pos >= state.steps.length - 1) return;
        go(state.pos + 1);
        status(`Redone: ${current().label}.`);
    }

    img.addEventListener('load', () => {
        if (!current() || img.src !== current().url) return;
        const kb = Math.round(current().blob.size / 1024);
        $('meta').textContent = `${state.name} · ${img.naturalWidth} × ${img.naturalHeight} px · ${kb.toLocaleString()} KB`;
        if (!state.keyManual) autoColor(false);
    });

    function load(file) {
        if (!file || !types.includes(file.type)) {
            status('That file is not a JPG, PNG, WEBP, GIF or BMP image.', 'error');
            return;
        }
        state.steps.forEach((s) => URL.revokeObjectURL(s.url));
        state.steps = [];
        state.pos = -1;
        state.name = (file.name || 'picture').replace(/\.[^.]+$/, '').replace(/[^\w\- ]+/g, '').trim() || 'picture';
        state.keyManual = false;
        Overlay.clear(true);
        push('Original', file);
        status('Loaded. Adjust the sliders or pick a tool.');
    }

    function syncFormat() {
        const f = format.value;
        const isIcon = f === 'ico' || iconPacks.includes(f);
        $('formatHint').textContent = hints[f];
        $('widthField').hidden = isIcon;
        $('iconOpts').hidden = !isIcon;
    }

    async function send(op) {
        if (Overlay.has()) await applyOverlays();
        const body = new FormData();
        body.append('csrf', csrf);
        body.append('op', op);
        const ext = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'image/gif': 'gif', 'image/bmp': 'bmp' }[current().blob.type] || 'png';
        body.append('image', current().blob, `picture.${ext}`);
        body.append('brightness', brightness.value);
        body.append('contrast', contrast.value);
        body.append('sharpness', sharpness.value);
        if (op === 'export') {
            body.append('format', format.value);
            body.append('width', $('width').value);
            body.append('icon_bg', $('iconBg').value);
            body.append('icon_padding', $('iconPadding').value);
        }
        if (op === 'colorkey') {
            body.append('key_color', $('keyColor').value);
            body.append('key_tolerance', $('keyTolerance').value);
            body.append('key_edges', $('keyEdges').checked ? '1' : '0');
        }

        const source = state.steps[0];
        state.busy = true;
        setEnabled(false);
        status(busyText[op], 'busy');
        try {
            const res = await fetch('process.php', { method: 'POST', body });
            if (!res.ok) {
                const text = await res.text();
                let message = `Server error ${res.status}.`;
                try {
                    const err = JSON.parse(text);
                    if (err && err.error) message = err.error;
                } catch {
                    if (res.status === 403) message = 'The web server refused the upload (403).';
                }
                throw new Error(message);
            }
            const blob = await res.blob();
            if (source !== state.steps[0]) return;
            if (op === 'export') {
                const f = format.value;
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = iconPacks.includes(f) ? `${state.name}-${f}-icons.zip` : `${state.name}.${f}`;
                a.click();
                setTimeout(() => URL.revokeObjectURL(a.href), 10000);
                status(`Downloaded ${a.download}.`);
            } else {
                const adjusted = slidersMoved() ? ' (with adjustments)' : '';
                push(labels[op] + adjusted, blob);
                status('Done. Undo or click a history step to go back.');
            }
        } catch (e) {
            status(e.message || 'Something went wrong.', 'error');
        } finally {
            state.busy = false;
            setEnabled(true);
        }
    }

    async function copyImage() {
        const canvas = composite();
        const png = new Promise((ok) => canvas.toBlob(ok, 'image/png'));
        try {
            // The clipboard call must start inside the click, so it gets the pending PNG, not the finished one.
            await navigator.clipboard.write([new ClipboardItem({ 'image/png': png })]);
            status('Copied. Paste into Visio, Word or PowerPoint.');
        } catch (e) {
            console.warn('Copy image failed:', e);
            status('The browser blocked clipboard access. Use Download instead.', 'error');
        }
    }

    $('file').addEventListener('change', (e) => { load(e.target.files[0]); e.target.value = ''; });
    brightness.addEventListener('input', applyFilter);
    contrast.addEventListener('input', applyFilter);
    sharpness.addEventListener('input', applyFilter);
    $('applyOverlays').addEventListener('click', applyOverlays);
    $('iconPadding').addEventListener('input', (e) => { $('pOut').value = e.target.value + '%'; });
    $('keyTolerance').addEventListener('input', (e) => { $('tOut').value = e.target.value + '%'; });
    $('keyColor').addEventListener('input', (e) => {
        setKeyColor(e.target.value, `Using ${e.target.value}. Press Remove this colour.`);
        state.keyManual = true;
    });
    $('autoColor').addEventListener('click', () => autoColor(true));
    $('pickColor').addEventListener('click', () => {
        if ($('pickColor').getAttribute('aria-pressed') === 'true') stopPicking();
        else startPicking();
    });
    $('resetAdjust').addEventListener('click', resetSliders);
    document.querySelectorAll('.tool').forEach((b) => b.addEventListener('click', () => send(b.dataset.op)));
    $('download').addEventListener('click', () => send('export'));
    $('copy').addEventListener('click', copyImage);
    $('undo').addEventListener('click', undo);
    $('redo').addEventListener('click', redo);
    format.addEventListener('change', syncFormat);
    syncFormat();

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && $('pickColor').getAttribute('aria-pressed') === 'true') {
            stopPicking();
            status('Colour picking cancelled.');
            return;
        }
        if (!(e.ctrlKey || e.metaKey) || e.target.matches('input[type=number], input[type=text], textarea')) return;
        const key = e.key.toLowerCase();
        if (key === 'z' && !e.shiftKey) { e.preventDefault(); undo(); }
        else if (key === 'y' || (key === 'z' && e.shiftKey)) { e.preventDefault(); redo(); }
    });

    const compare = $('compare');
    const showOriginal = () => {
        if (!state.steps.length) return;
        img.src = state.steps[0].url;
        img.style.filter = 'none';
        Overlay.setVisible(false);
    };
    const showCurrent = () => {
        if (!current()) return;
        img.src = current().url;
        img.style.filter = filter();
        Overlay.setVisible(true);
    };
    compare.addEventListener('pointerdown', showOriginal);
    ['pointerup', 'pointerleave', 'blur'].forEach((ev) => compare.addEventListener(ev, showCurrent));
    compare.addEventListener('keydown', (e) => { if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); showOriginal(); } });
    compare.addEventListener('keyup', showCurrent);

    document.addEventListener('dragover', (e) => { e.preventDefault(); stage.classList.add('over'); });
    document.addEventListener('dragleave', (e) => { if (!e.relatedTarget) stage.classList.remove('over'); });
    document.addEventListener('drop', (e) => {
        e.preventDefault();
        stage.classList.remove('over');
        load(e.dataTransfer.files[0]);
    });
    document.addEventListener('paste', (e) => {
        const item = [...e.clipboardData.items].find((i) => i.type.startsWith('image/'));
        if (item) load(item.getAsFile());
    });
})();
