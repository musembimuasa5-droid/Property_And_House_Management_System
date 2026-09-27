// Custom client-side scripts for modals or mobile sidebar toggles
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (sidebar) {
        sidebar.classList.toggle('-translate-x-full');
    }
}