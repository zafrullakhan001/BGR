// Loaded in <head> so the saved theme applies before the page paints.
(() => {
    const root = document.documentElement;
    let saved = null;
    try { saved = localStorage.getItem('theme'); } catch (e) { /* storage blocked */ }
    root.dataset.theme = saved || (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');

    document.addEventListener('DOMContentLoaded', () => {
        const btn = document.getElementById('theme');
        const label = () => btn.setAttribute('aria-label', root.dataset.theme === 'dark' ? 'Switch to day mode' : 'Switch to night mode');
        label();
        btn.addEventListener('click', () => {
            root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
            try { localStorage.setItem('theme', root.dataset.theme); } catch (e) { /* storage blocked */ }
            label();
        });
    });
})();
