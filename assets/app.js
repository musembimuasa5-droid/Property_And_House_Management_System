// Custom client-side scripts for modals or mobile sidebar toggles
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (sidebar) {
        sidebar.classList.toggle('-translate-x-full');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const currentPath = window.location.pathname.replace(/\/+$/, '') || '/';
    const sidebar = document.getElementById('sidebar');
    if (sidebar) {
        sidebar.querySelectorAll('a[href]').forEach((link) => {
            const linkPath = new URL(link.href, window.location.href).pathname.replace(/\/+$/, '') || '/';
            if (linkPath === currentPath) link.setAttribute('aria-current', 'page');
        });

        document.addEventListener('click', (event) => {
            const toggleButton = event.target.closest('[onclick="toggleSidebar()"]');
            if (window.innerWidth < 1024 && !sidebar.classList.contains('-translate-x-full') && !sidebar.contains(event.target) && !toggleButton) {
                sidebar.classList.add('-translate-x-full');
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') sidebar.classList.add('-translate-x-full');
        });
    }

    const publicNavigation = document.getElementById('public-navigation');
    if (publicNavigation) {
        publicNavigation.querySelectorAll('a[href]').forEach((link) => {
            const linkUrl = new URL(link.href, window.location.href);
            const linkPath = linkUrl.pathname.replace(/\/+$/, '') || '/';
            if (linkPath === currentPath && !link.hash) link.setAttribute('aria-current', 'page');
        });
    }

    const toggle = document.querySelector('.mobile-menu-toggle');
    const navigation = publicNavigation;
    if (!toggle || !navigation) return;

    toggle.addEventListener('click', () => {
        const isOpen = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', String(!isOpen));
        toggle.setAttribute('aria-label', isOpen ? 'Open navigation' : 'Close navigation');
        navigation.classList.toggle('is-open', !isOpen);
    });

    navigation.addEventListener('click', (event) => {
        if (event.target.closest('a')) {
            toggle.setAttribute('aria-expanded', 'false');
            toggle.setAttribute('aria-label', 'Open navigation');
            navigation.classList.remove('is-open');
        }
    });
});
