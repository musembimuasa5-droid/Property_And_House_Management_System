<?php
$isPublicPage = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/public/') !== false || basename($_SERVER['SCRIPT_NAME'] ?? '') === 'index.php';
?>
<?php if ($isPublicPage): ?>
<footer class="public-footer">
  <div class="site-container footer-grid">
    <div class="footer-brand"><a class="brand footer-logo" href="<?php echo BASE_URL; ?>/"><span class="brand-icon" aria-hidden="true">⌂</span><span><?php echo e(APP_NAME); ?></span></a><p>Property search and tenancy management for Nairobi.</p><p class="footer-location">Nairobi, Kenya</p></div>
    <div><h2>Explore</h2><a href="<?php echo BASE_URL; ?>/">Home</a><a href="<?php echo BASE_URL; ?>/public/properties.php">Available properties</a><a href="<?php echo BASE_URL; ?>/#services">Services</a><a href="<?php echo BASE_URL; ?>/public/contact.php">Contact</a></div>
    <div><h2>Property portal</h2><a href="<?php echo BASE_URL; ?>/public/login.php">Manager sign in</a><a href="<?php echo BASE_URL; ?>/public/login.php">Tenant sign in</a><a href="<?php echo BASE_URL; ?>/public/register.php">Tenant registration</a></div>
    <div><h2>How we help</h2><a href="<?php echo BASE_URL; ?>/#about">Property management</a><a href="<?php echo BASE_URL; ?>/#services">Rent &amp; payments</a><a href="<?php echo BASE_URL; ?>/#services">Maintenance requests</a></div>
  </div>
  <div class="footer-bottom"><div class="site-container">&copy; <?php echo date('Y'); ?> <?php echo e(APP_NAME); ?>. All rights reserved.</div></div>
</footer>
<?php else: ?>
<footer class="bg-white border-t border-gray-200 mt-auto py-6"><div class="max-w-7xl mx-auto px-4 text-center text-sm text-gray-500">&copy; <?php echo date('Y'); ?> <?php echo e(APP_NAME); ?>. All rights reserved.</div></footer>
<?php endif; ?>
<script src="<?php echo BASE_URL; ?>/assets/app.js" defer></script>
</body>
</html>
