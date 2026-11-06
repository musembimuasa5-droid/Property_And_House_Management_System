<?php
$isPublicPage = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/public/') !== false || basename($_SERVER['SCRIPT_NAME'] ?? '') === 'index.php';
?>
<?php if ($isPublicPage): ?>
<footer class="public-footer">
  <div class="site-container footer-grid">
    <div class="footer-brand"><a class="brand footer-logo" href="<?php echo BASE_URL; ?>/"><img class="brand-logo" src="<?php echo BASE_URL; ?>/assets/houselogo.avif" alt=""><span><?php echo e(APP_NAME); ?></span></a><p>Property search and tenancy management for Nairobi.</p><p class="footer-location">Nairobi, Kenya</p></div>
    <div><h2>Explore</h2><a href="<?php echo BASE_URL; ?>/">Home</a><a href="<?php echo BASE_URL; ?>/public/properties.php">Available properties</a><a href="<?php echo BASE_URL; ?>/#services">Services</a><a href="<?php echo BASE_URL; ?>/public/contact.php">Contact</a></div>
    <div><h2>Property portal</h2><a href="<?php echo BASE_URL; ?>/public/login.php">Sign in</a><a href="<?php echo BASE_URL; ?>/public/register.php">Create a tenant account</a><a href="<?php echo BASE_URL; ?>/public/contact.php">Contact management</a></div>
    <div><h2>How we help</h2><a href="<?php echo BASE_URL; ?>/#about">How it works</a><a href="<?php echo BASE_URL; ?>/#services">Rent &amp; payments</a><a href="<?php echo BASE_URL; ?>/#services">Maintenance requests</a></div>
  </div>
  <div class="footer-bottom"><div class="site-container">&copy; <?php echo date('Y'); ?> <?php echo e(APP_NAME); ?>. All rights reserved.</div></div>
</footer>
<?php else: ?>
<footer class="app-footer mt-auto"><div class="max-w-7xl mx-auto px-4 text-center">&copy; <?php echo date('Y'); ?> <?php echo e(APP_NAME); ?>. All rights reserved.</div></footer>
<?php if (!empty($app_shell_open)) echo '</div></div>'; ?>
<?php endif; ?>
<script src="<?php echo BASE_URL; ?>/assets/app.js" defer></script>
</body>
</html>
