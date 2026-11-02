<?php
$isPublicPage = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/public/') !== false || basename($_SERVER['SCRIPT_NAME'] ?? '') === 'index.php';
?>
<?php if ($isPublicPage): ?>
<footer class="public-footer">
  <div class="site-container footer-grid">
    <div class="footer-brand"><a class="brand footer-logo" href="<?php echo BASE_URL; ?>/"><img src="<?php echo BASE_URL; ?>/assets/property-management-logo.png" alt="" class="brand-logo"><span>Property and House<br>Management System</span></a><p>A more considered way to find a rental home and manage the details that make it feel like yours.</p><p class="footer-location"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> Nairobi, Kenya</p></div>
    <div><h2>Explore</h2><a href="<?php echo BASE_URL; ?>/">Home</a><a href="<?php echo BASE_URL; ?>/public/properties.php">Explore houses</a><a href="<?php echo BASE_URL; ?>/public/about.php">About us</a><a href="<?php echo BASE_URL; ?>/public/contact.php">Contact</a></div>
    <div><h2>Property portal</h2><a href="<?php echo BASE_URL; ?>/public/login.php">Manager sign in</a><a href="<?php echo BASE_URL; ?>/public/login.php">Tenant sign in</a><a href="<?php echo BASE_URL; ?>/public/register.php">Tenant registration</a></div>
    <div><h2>Property types</h2><a href="<?php echo BASE_URL; ?>/public/properties.php?house_type=Bedsitter">Bedsitters &amp; studios</a><a href="<?php echo BASE_URL; ?>/public/properties.php?house_type=1%20Bedroom">1 bedroom homes</a><a href="<?php echo BASE_URL; ?>/public/properties.php?house_type=2%20Bedroom">2 bedroom homes</a><a href="<?php echo BASE_URL; ?>/public/properties.php?house_type=Maisonette">Maisonettes</a></div>
    <div><h2>Nairobi neighbourhoods</h2><a href="<?php echo BASE_URL; ?>/public/properties.php?location=Kasarani">Kasarani</a><a href="<?php echo BASE_URL; ?>/public/properties.php?location=Kilimani">Kilimani</a><a href="<?php echo BASE_URL; ?>/public/properties.php?location=Westlands">Westlands</a><a href="<?php echo BASE_URL; ?>/public/properties.php?location=Roysambu">Roysambu</a></div>
    <div class="footer-contact"><h2>How we help</h2><a href="<?php echo BASE_URL; ?>/public/about.php">Property management</a><a href="<?php echo BASE_URL; ?>/public/properties.php">Rent &amp; payments</a><a href="<?php echo BASE_URL; ?>/tenant/maintenance.php">Maintenance requests</a><p class="footer-contact-prompt">Questions about a home or the platform?</p><a href="<?php echo BASE_URL; ?>/public/contact.php"><i class="fa-regular fa-envelope" aria-hidden="true"></i> Contact our team</a><span>hello@example.com <small>Contact placeholder</small></span><div class="footer-socials" aria-label="Social media"><span aria-label="Instagram"><i class="fa-brands fa-instagram" aria-hidden="true"></i></span><span aria-label="Facebook"><i class="fa-brands fa-facebook-f" aria-hidden="true"></i></span><span aria-label="LinkedIn"><i class="fa-brands fa-linkedin-in" aria-hidden="true"></i></span></div></div>
  </div>
  <div class="footer-bottom"><div class="site-container">&copy; <?php echo date('Y'); ?> <?php echo e(APP_NAME); ?>. All rights reserved.</div></div>
</footer>
<?php else: ?>
<footer class="app-footer mt-auto"><div class="max-w-7xl mx-auto px-4 text-center">&copy; <?php echo date('Y'); ?> <?php echo e(APP_NAME); ?>. All rights reserved.</div></footer>
<?php endif; ?>
<script src="<?php echo BASE_URL; ?>/assets/app.js?v=<?php echo (int)filemtime(__DIR__ . '/../assets/app.js'); ?>" defer></script>
</body>
</html>
