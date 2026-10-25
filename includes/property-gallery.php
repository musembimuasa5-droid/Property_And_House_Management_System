<?php
$galleryPhotos = [
    ['assets/house1.jpg', 'Illustrative home exterior'],
    ['assets/house2.jpg', 'Illustrative residential neighbourhood'],
    ['assets/house3.jpg', 'Illustrative family-home exterior'],
    ['assets/house4.jpg', 'Illustrative residential community'],
    ['assets/house5.jpg', 'Illustrative apartment exterior'],
    ['assets/modern-family-home.webp', 'Contemporary family home'],
    ['assets/modern-residential-building.webp', 'Modern residential building'],
    ['assets/airy-apartment-living-room.webp', 'Comfortable apartment living room'],
    ['assets/bright-open-plan-lounge.webp', 'Bright open-plan lounge'],
    ['assets/modern-home-interior.webp', 'Contemporary home interior'],
];
?>
<section class="sample-gallery-section" aria-labelledby="sample-gallery-title">
  <div class="site-container">
    <div class="sample-gallery-heading"><div><p class="eyebrow">A feel for the homes</p><h2 id="sample-gallery-title">A Glimpse of Nairobi Living</h2></div><p>Representative photos. Check individual listings for current availability and property details.</p></div>
    <div class="sample-gallery-grid">
      <?php foreach ($galleryPhotos as [$photo, $caption]): ?>
        <figure class="sample-gallery-item"><img src="<?php echo BASE_URL . '/' . e($photo); ?>" alt="Illustrative <?php echo e(strtolower($caption)); ?>" width="840" height="560" loading="lazy" decoding="async"><figcaption><?php echo e($caption); ?></figcaption></figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
