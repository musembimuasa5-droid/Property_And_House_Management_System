// Mobile sidebar: opened by [data-sidebar-toggle], closed by [data-sidebar-close], the backdrop or Escape.
function setSidebar(open) {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    sidebar.classList.toggle('is-open', open);
    document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => button.setAttribute('aria-expanded', String(open)));
    const backdrop = document.querySelector('.sidebar-backdrop');
    if (backdrop) backdrop.hidden = !open;
    if (open) sidebar.querySelector('a, button')?.focus();
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (sidebar) setSidebar(!sidebar.classList.contains('is-open'));
}

document.addEventListener('click', (event) => {
    if (event.target.closest('[data-sidebar-toggle]')) toggleSidebar();
    else if (event.target.closest('[data-sidebar-close]')) setSidebar(false);
});

// Top-bar menus are <details>: keep one open at a time and close them on outside click or Escape.
document.addEventListener('toggle', (event) => {
    const menu = event.target;
    if (!(menu instanceof HTMLDetailsElement) || !menu.matches('.topbar-menu') || !menu.open) return;
    document.querySelectorAll('.topbar-menu[open]').forEach((other) => { if (other !== menu) other.open = false; });
}, true);

document.addEventListener('click', (event) => {
    document.querySelectorAll('.topbar-menu[open]').forEach((menu) => { if (!menu.contains(event.target)) menu.open = false; });
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const openMenu = document.querySelector('.topbar-menu[open]');
    if (openMenu) {
        openMenu.open = false;
        openMenu.querySelector('summary')?.focus();
        return;
    }
    const sidebar = document.getElementById('sidebar');
    if (sidebar?.classList.contains('is-open')) {
        setSidebar(false);
        document.querySelector('[data-sidebar-toggle]')?.focus();
    }
});

// Ask before destructive form submissions such as deletes (forms marked data-confirm).
document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-confirm]');
    if (form && !window.confirm(form.dataset.confirm)) event.preventDefault();
});

document.addEventListener('click', (event) => {
    if (event.target.closest('[data-print]')) window.print();
});

// Chart tooltips: one per chart, shown on hover and on keyboard focus.
// Values are also in each chart's table view, so the tooltip never gates data.
function initChartTooltips() {
    document.querySelectorAll('.viz').forEach((chart) => {
        const area = chart.querySelector('.viz-area');
        const tip = chart.querySelector('.viz-tooltip');
        if (!area || !tip) return;
        const show = (target) => {
            const key = document.createElement('i');
            key.className = 'viz-slot-' + target.dataset.slot;
            const value = document.createElement('strong');
            value.textContent = target.dataset.value;
            const label = document.createElement('span');
            label.append(key, document.createTextNode(target.dataset.label));
            tip.replaceChildren(value, label);
            const bar = target.querySelector('.viz-bar').getBoundingClientRect();
            const box = area.getBoundingClientRect();
            const half = tip.offsetWidth / 2 || 72;
            tip.style.left = Math.min(Math.max(bar.left + bar.width / 2 - box.left, half), box.width - half) + 'px';
            tip.style.top = Math.max(bar.top - box.top, 40) + 'px';
            tip.hidden = false;
        };
        const hide = () => { tip.hidden = true; };
        area.addEventListener('pointerover', (event) => {
            const target = event.target.closest('.viz-hit');
            if (target) show(target);
        });
        area.addEventListener('pointerleave', hide);
        area.addEventListener('focusin', (event) => {
            const target = event.target.closest('.viz-hit');
            if (target) show(target);
        });
        area.addEventListener('focusout', hide);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-login-form]').forEach((form) => {
        form.addEventListener('submit', () => {
            const submitButton = form.querySelector('button[type="submit"]');
            if (!(submitButton instanceof HTMLButtonElement)) return;
            submitButton.disabled = true;
            const label = submitButton.querySelector('span');
            if (label) label.textContent = 'Signing in...';
        });
    });

    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        const passwordInput = document.getElementById(button.getAttribute('aria-controls'));
        if (!passwordInput) return;

        button.addEventListener('click', () => {
            const isVisible = passwordInput.type === 'text';
            passwordInput.type = isVisible ? 'password' : 'text';
            button.setAttribute('aria-pressed', String(!isVisible));
            button.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
            button.setAttribute('title', isVisible ? 'Show password' : 'Hide password');

            const icon = button.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-eye', isVisible);
                icon.classList.toggle('fa-eye-slash', !isVisible);
            }
        });
    });

    const dialog = document.getElementById('viewing-dialog');
    const form = document.getElementById('viewing-form');
    const success = document.getElementById('viewing-success');
    const propertyName = document.getElementById('viewing-property-name');
    const propertyLocation = document.getElementById('viewing-property-location');
    const propertyImage = document.getElementById('viewing-image');
    if (dialog instanceof HTMLDialogElement && form instanceof HTMLFormElement && success && propertyName && propertyLocation && propertyImage instanceof HTMLImageElement) {
        let opener = null;
        const dateInput = form.querySelector('input[name="date"]');
        if (dateInput) {
            const localDate = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
            dateInput.min = localDate;
        }

        document.querySelectorAll('[data-viewing-property]').forEach((button) => {
            button.addEventListener('click', () => {
                opener = button;
                propertyName.textContent = button.dataset.viewingProperty || 'Selected home';
                propertyLocation.textContent = button.dataset.viewingLocation || '';
                propertyImage.src = button.dataset.viewingImage || '';
                propertyImage.alt = 'Home exterior for ' + propertyName.textContent;
                form.reset();
                form.hidden = false;
                success.hidden = true;
                dialog.showModal();
            });
        });

        dialog.querySelectorAll('[data-close-viewing]').forEach((button) => {
            button.addEventListener('click', () => dialog.close());
        });

        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });

        dialog.addEventListener('close', () => {
            if (opener instanceof HTMLElement) opener.focus();
        });

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            form.hidden = true;
            success.hidden = false;
            const doneButton = success.querySelector('button');
            if (doneButton) doneButton.focus();
        });
    }

    initChartTooltips();
    const currentPath = window.location.pathname.replace(/\/+$/, '') || '/';
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

    // Public mobile menu: the toggle, a link, a tap outside or Escape all keep state and labels in sync.
    const setMenu = (open) => {
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
        navigation.classList.toggle('is-open', open);
    };
    const isOpen = () => toggle.getAttribute('aria-expanded') === 'true';

    toggle.addEventListener('click', () => setMenu(!isOpen()));
    navigation.addEventListener('click', (event) => {
        if (event.target.closest('a')) setMenu(false);
    });
    document.addEventListener('click', (event) => {
        if (isOpen() && !navigation.contains(event.target) && !toggle.contains(event.target)) setMenu(false);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isOpen()) {
            setMenu(false);
            toggle.focus();
        }
    });
});
