<?php
declare(strict_types=1);

session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$csrf = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES);
$v = static fn (string $f): int => filemtime(__DIR__ . '/assets/' . $f);
$fonts = ['Plus Jakarta Sans', 'Oswald', 'Playfair Display', 'Caveat', 'Arial', 'Segoe UI', 'Calibri', 'Verdana',
    'Georgia', 'Times New Roman', 'Courier New', 'Impact'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf" content="<?= $csrf ?>">
    <title>Picture desk</title>
    <script src="assets/theme.js?v=<?= $v('theme.js') ?>"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400;1,700&family=Oswald:wght@400;700&family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;700&display=swap">
    <link rel="stylesheet" href="assets/app.css?v=<?= $v('app.css') ?>">
</head>
<body>
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
    <defs>
        <filter id="sharpen" color-interpolation-filters="sRGB">
            <feConvolveMatrix id="sharpenKernel" order="3" kernelMatrix="0 0 0 0 1 0 0 0 0" preserveAlpha="true" edgeMode="duplicate"/>
        </filter>
        <symbol id="i-image" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/></symbol>
        <symbol id="i-upload" viewBox="0 0 24 24"><path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></symbol>
        <symbol id="i-undo" viewBox="0 0 24 24"><path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/></symbol>
        <symbol id="i-redo" viewBox="0 0 24 24"><path d="m15 14 5-5-5-5"/><path d="M20 9H10a6 6 0 0 0 0 12h3"/></symbol>
        <symbol id="i-sliders" viewBox="0 0 24 24"><path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/></symbol>
        <symbol id="i-wand" viewBox="0 0 24 24"><path d="M15 4V2M15 10V8M11 6h2M17 6h2M3 21 15 9"/></symbol>
        <symbol id="i-rotl" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></symbol>
        <symbol id="i-rotr" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></symbol>
        <symbol id="i-pencil" viewBox="0 0 24 24"><path d="M3 21l3.5-1 11-11-2.5-2.5-11 11z"/><path d="M14 6l2.5 2.5"/></symbol>
        <symbol id="i-scissors" viewBox="0 0 24 24"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M20 4 8.1 15.9M14.5 14.5 20 20M8.1 8.1 12 12"/></symbol>
        <symbol id="i-type" viewBox="0 0 24 24"><path d="M4 7V4h16v3M9 20h6M12 4v16"/></symbol>
        <symbol id="i-stamp" viewBox="0 0 24 24"><path d="M5 21h14M6 17h12v-3a2 2 0 0 0-2-2h-1.5l.8-4.5a3.3 3.3 0 1 0-6.6 0L9.5 12H8a2 2 0 0 0-2 2z"/></symbol>
        <symbol id="i-check" viewBox="0 0 24 24"><path d="m5 12 5 5L20 7"/></symbol>
        <symbol id="i-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></symbol>
        <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
        <symbol id="i-box" viewBox="0 0 24 24"><path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/></symbol>
        <symbol id="i-download" viewBox="0 0 24 24"><path d="M12 4v12M7 11l5 5 5-5"/><path d="M4 20h16"/></symbol>
        <symbol id="i-copy" viewBox="0 0 24 24"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/></symbol>
        <symbol id="i-split" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M12 3v18"/></symbol>
        <symbol id="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></symbol>
        <symbol id="i-moon" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></symbol>
        <symbol id="i-dropper" viewBox="0 0 24 24"><path d="m14 6 4 4M3 21l2-.5L16 9.5 14.5 8 3.5 19z"/><path d="m13 5 2.6-2.6a2 2 0 0 1 2.8 0l3.2 3.2a2 2 0 0 1 0 2.8L19 11"/></symbol>
        <symbol id="i-logo" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><rect x="12" y="12" width="6" height="6" rx="1"/><path d="M7 7h6M7 10h3"/></symbol>
    </defs>
</svg>

<div class="desk">
    <aside class="block">
        <header class="title">
            <div>
                <h1>Picture desk</h1>
                <p>Clean up photos, drawings and app icons.</p>
            </div>
            <button type="button" class="theme-toggle" id="theme" aria-label="Switch to day mode" title="Day / night">
                <svg class="moon"><use href="#i-moon"/></svg><svg class="sun"><use href="#i-sun"/></svg>
            </button>
        </header>

        <section class="cell sec-image">
            <h2><span class="chip"><svg><use href="#i-image"/></svg></span>Image</h2>
            <label class="btn wide" for="file"><svg><use href="#i-upload"/></svg>Choose image</label>
            <input id="file" type="file" accept="image/jpeg,image/png,image/webp,image/gif,image/bmp" hidden>
            <p class="meta" id="meta">Drop, browse, or paste with Ctrl+V.</p>
        </section>

        <section class="cell sec-adjust">
            <h2><span class="chip"><svg><use href="#i-sliders"/></svg></span>Adjust</h2>
            <label class="slider bright">
                <span>Brightness <output id="bOut">100%</output></span>
                <input type="range" id="brightness" min="0" max="200" value="100" aria-label="Brightness" disabled>
            </label>
            <label class="slider contrast">
                <span>Contrast <output id="cOut">100%</output></span>
                <input type="range" id="contrast" min="0" max="200" value="100" aria-label="Contrast" disabled>
            </label>
            <label class="slider sharp">
                <span>Sharpness <output id="sOut">0%</output></span>
                <input type="range" id="sharpness" min="0" max="100" value="0" aria-label="Sharpness" disabled>
            </label>
            <div class="row">
                <button type="button" class="btn" id="resetAdjust" disabled>Reset sliders</button>
                <button type="button" class="btn" id="compare" disabled title="Hold to see the original"><svg><use href="#i-split"/></svg>Hold to compare</button>
            </div>
        </section>

        <section class="cell sec-tools">
            <h2><span class="chip"><svg><use href="#i-wand"/></svg></span>Tools</h2>
            <div class="grid2">
                <button type="button" class="btn tool" data-op="rotate_left" disabled><svg><use href="#i-rotl"/></svg>Rotate left</button>
                <button type="button" class="btn tool" data-op="rotate_right" disabled><svg><use href="#i-rotr"/></svg>Rotate right</button>
                <button type="button" class="btn tool wide-cell" data-op="clean" disabled title="Scans and photos of plans: white paper, dark lines"><svg><use href="#i-pencil"/></svg>Clean drawing</button>
                <button type="button" class="btn tool magic" data-op="cutout" disabled title="Makes the colour around the edges transparent"><svg><use href="#i-scissors"/></svg>Remove background</button>
            </div>

            <div class="subpanel">
                <h3>Remove a background colour</h3>
                <div class="row key-row">
                    <label class="field color narrow"><span>Colour <output id="keyHex">#ffffff</output></span>
                        <input type="color" id="keyColor" value="#ffffff" disabled>
                    </label>
                    <button type="button" class="btn" id="pickColor" aria-pressed="false" disabled title="Then click the picture"><svg><use href="#i-dropper"/></svg>Pick</button>
                    <button type="button" class="btn" id="autoColor" disabled title="Most common colour along the edges"><svg><use href="#i-wand"/></svg>Auto</button>
                </div>
                <label class="slider tol">
                    <span>Tolerance <output id="tOut">20%</output></span>
                    <input type="range" id="keyTolerance" min="0" max="100" value="20" aria-label="Colour tolerance" disabled>
                </label>
                <label class="check"><input type="checkbox" id="keyEdges" checked disabled> Only areas touching the picture edges</label>
                <button type="button" class="btn tool magic" data-op="colorkey" disabled><svg><use href="#i-dropper"/></svg>Remove this colour</button>
                <p class="meta" id="keyHint">The colour is detected from the picture edges. Use Pick to click a colour on the picture instead.</p>
            </div>
        </section>

        <section class="cell sec-text">
            <h2><span class="chip"><svg><use href="#i-type"/></svg></span>Text, logo &amp; watermark</h2>
            <div class="grid2">
                <button type="button" class="btn" id="addText" disabled><svg><use href="#i-type"/></svg>Add text</button>
                <label class="btn file-btn" title="Place a logo or any second picture on top"><svg><use href="#i-logo"/></svg>Add logo
                    <input type="file" id="logoFile" accept="image/png,image/jpeg,image/webp,image/gif" hidden disabled>
                </label>
                <button type="button" class="btn" id="addWmText" disabled title="Faint text repeated across the whole picture"><svg><use href="#i-stamp"/></svg>Watermark text</button>
                <label class="btn file-btn" title="Faint logo in the corner, or repeated across the picture"><svg><use href="#i-stamp"/></svg>Watermark logo
                    <input type="file" id="wmFile" accept="image/png,image/jpeg,image/webp,image/gif" hidden disabled>
                </label>
            </div>

            <ul class="layers" id="layers" aria-label="Text, logo and watermark layers"></ul>

            <div class="ov-fields" id="ovTextFields">
                <label class="field"><span>Text</span>
                    <textarea id="ovText" rows="2" placeholder="Type your text" disabled>Your text</textarea>
                </label>
                <div class="row">
                    <label class="field grow"><span>Font</span>
                        <select id="ovFont" disabled>
                            <?php foreach ($fonts as $font): ?>
                                <option style="font-family:'<?= htmlspecialchars($font, ENT_QUOTES) ?>'"><?= htmlspecialchars($font) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field narrow"><span>Size (px)</span>
                        <input type="number" id="ovSize" min="6" max="2000" value="64" disabled>
                    </label>
                </div>
                <div class="row style-row">
                    <label class="field narrow color"><span>Colour</span>
                        <input type="color" id="ovColor" value="#ffffff" disabled>
                    </label>
                    <label class="toggle" title="Bold"><input type="checkbox" id="ovBold" aria-label="Bold" disabled><b>B</b></label>
                    <label class="toggle" title="Italic"><input type="checkbox" id="ovItalic" aria-label="Italic" disabled><i>I</i></label>
                    <label class="toggle" title="Dark outline for readability"><input type="checkbox" id="ovShadow" disabled><span>Outline</span></label>
                </div>
            </div>
            <div class="ov-fields" id="ovImageFields" hidden>
                <label class="slider pad">
                    <span>Image size <output id="ovImgSizeOut">25%</output></span>
                    <input type="range" id="ovImgSize" min="2" max="150" value="25" aria-label="Image size" disabled>
                </label>
            </div>
            <label class="slider opacity">
                <span>Opacity <output id="ovOpacityOut">100%</output></span>
                <input type="range" id="ovOpacity" min="5" max="100" value="100" aria-label="Opacity" disabled>
            </label>
            <label class="slider rotate">
                <span>Rotation <output id="ovRotateOut">0°</output></span>
                <input type="range" id="ovRotate" min="-180" max="180" value="0" aria-label="Rotation" disabled>
            </label>
            <label class="check"><input type="checkbox" id="ovTile" disabled> Repeat across the whole picture</label>
            <div class="place-row">
                <span class="meta">Place</span>
                <div class="place" id="place" aria-label="Place the selected layer">
                    <?php foreach ([['top left', 0, 0], ['top', 1, 0], ['top right', 2, 0], ['left', 0, 1], ['centre', 1, 1],
                        ['right', 2, 1], ['bottom left', 0, 2], ['bottom', 1, 2], ['bottom right', 2, 2]] as [$name, $x, $y]): ?>
                        <button type="button" data-x="<?= $x ?>" data-y="<?= $y ?>" aria-label="<?= ucfirst($name) ?>" title="<?= ucfirst($name) ?>" disabled></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="row">
                <button type="button" class="btn primary" id="applyOverlays" disabled><svg><use href="#i-check"/></svg>Apply to picture</button>
            </div>
            <p class="meta">Drag a layer to move it, drag its corner handles to resize it. Tools and downloads apply layers automatically.</p>
        </section>

        <section class="cell sec-history">
            <h2><span class="chip"><svg><use href="#i-clock"/></svg></span>History</h2>
            <div class="row">
                <button type="button" class="btn" id="undo" disabled title="Ctrl+Z"><svg><use href="#i-undo"/></svg>Undo</button>
                <button type="button" class="btn" id="redo" disabled title="Ctrl+Y"><svg><use href="#i-redo"/></svg>Redo</button>
            </div>
            <ol class="history" id="history" aria-label="Edit history"></ol>
            <p class="meta">Click any step to jump back to it. Ctrl+Z undoes, Ctrl+Y redoes.</p>
        </section>

        <section class="cell sec-export">
            <h2><span class="chip"><svg><use href="#i-box"/></svg></span>Export</h2>
            <div class="row">
                <label class="field">
                    <span>Format</span>
                    <select id="format" disabled>
                        <optgroup label="Pictures">
                            <option value="png">PNG</option>
                            <option value="jpg">JPG</option>
                            <option value="webp">WEBP</option>
                            <option value="gif">GIF</option>
                            <option value="bmp">BMP</option>
                            <option value="ico">ICO (favicon file)</option>
                        </optgroup>
                        <optgroup label="Icon packs (ZIP)">
                            <option value="favicon">Website favicon set</option>
                            <option value="pwa">PWA icons</option>
                            <option value="android">Android app icons</option>
                            <option value="ios">iOS app icon</option>
                            <option value="appicons">All app icons</option>
                        </optgroup>
                    </select>
                </label>
                <label class="field" id="widthField">
                    <span>Width (px)</span>
                    <input type="number" id="width" min="16" max="12000" placeholder="Original" disabled>
                </label>
            </div>
            <div class="icon-opts" id="iconOpts" hidden>
                <label class="field color">
                    <span>Icon background</span>
                    <input type="color" id="iconBg" value="#ffffff" disabled>
                </label>
                <label class="slider pad">
                    <span>Padding <output id="pOut">8%</output></span>
                    <input type="range" id="iconPadding" min="0" max="30" value="8" aria-label="Icon padding" disabled>
                </label>
            </div>
            <p class="meta" id="formatHint">Keeps transparency.</p>
            <div class="row">
                <button type="button" class="btn primary" id="download" disabled><svg><use href="#i-download"/></svg>Download</button>
                <button type="button" class="btn" id="copy" disabled title="Paste straight into Visio or Word"><svg><use href="#i-copy"/></svg>Copy image</button>
            </div>
        </section>

        <footer class="status" id="status" role="status" aria-live="polite">Ready.</footer>
    </aside>

    <main class="stage" id="stage">
        <label class="drop" id="drop" for="file">
            <span class="drop-icon"><svg><use href="#i-upload"/></svg></span>
            <strong>Drop a picture here</strong>
            <span>JPG, PNG, WEBP, GIF or BMP, up to 30 MB. Or paste with Ctrl+V.</span>
        </label>
        <figure class="view" id="view" hidden>
            <img id="img" alt="Working image">
            <canvas id="overlay" aria-label="Text and watermark layer"></canvas>
        </figure>
    </main>
</div>
<script src="assets/overlay.js?v=<?= $v('overlay.js') ?>"></script>
<script src="assets/app.js?v=<?= $v('app.js') ?>"></script>
</body>
</html>
