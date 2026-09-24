<footer class="bg-white border-t border-gray-200 mt-auto py-6">
        <div class="max-w-7xl mx-auto px-4 text-center text-sm text-gray-500">
            &copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?>. All rights reserved. Designed for Nairobi Property Management.
        </div>
    </footer>
    <!-- Global JavaScript Helpers -->
    <script>
        // Toggle mobile sidebar
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) {
                sidebar.classList.toggle('-translate-x-full');
            }
        }
    </script>
</body>
</html>