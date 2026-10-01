// Live branding preview in the control panel: size sliders, logo background, name and tagline.
(() => {
    const preview = document.getElementById('brandPreview');
    if (!preview) return;
    const $ = (id) => document.getElementById(id);

    document.querySelectorAll('input[data-var]').forEach((input) => input.addEventListener('input', () => {
        preview.style.setProperty(input.dataset.var, input.value + 'px');
        input.closest('label').querySelector('output').value = input.value + ' px';
    }));

    $('logoPlate')?.addEventListener('change', (e) => $('logoPreview').classList.toggle('plain', !e.target.checked));
    $('appName').addEventListener('input', (e) => { $('namePreview').textContent = e.target.value || ' '; });
    $('tagline').addEventListener('input', (e) => {
        $('taglinePreview').textContent = e.target.value;
        $('taglinePreview').hidden = e.target.value.trim() === '';
    });
})();

// Keep the GitHub token link pointed at the owner typed in the repository field.
(() => {
    const repo = document.querySelector('input[name="repo"]');
    const link = document.getElementById('githubToken');
    if (!repo || !link) return;
    const url = new URL(link.href);
    repo.addEventListener('input', () => {
        const owner = repo.value.split('/')[0];
        if (/^[A-Za-z0-9-]+$/.test(owner)) url.searchParams.set('target_name', owner);
        link.href = url.toString();
    });
})();
