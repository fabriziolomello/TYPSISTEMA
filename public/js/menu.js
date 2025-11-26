document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('btn-menu');
    const sidebar = document.getElementById('sidebar');

    if (!btn || !sidebar) return;

    btn.addEventListener('click', () => {
        sidebar.classList.toggle('open');
    });
});