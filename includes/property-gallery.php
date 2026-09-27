<?php
$galleryPhotos = [
    ['assets/house1.jpg', 'Detached family home'],
    ['assets/house2.jpg', 'Residential neighbourhood'],
    ['assets/house3.jpg', 'Modern family house'],
    ['assets/house4.jpg', 'Gated residential homes'],
    ['assets/house5.jpg', 'Apartment community'],
];
?>
<section class="sample-gallery-section" aria-labelledby="sample-gallery-title">
  <div class="site-container">
    <div class="sample-gallery-heading"><div><p class="eyebrow">A feel for the homes</p><h2 id="sample-gallery-title">Explore places to call home</h2></div><p>Representative photos. Check individual listings for current availability and property details.</p></div>
    <div class="sample-gallery-grid">
      <?php foreach ($galleryPhotos as [$photo, $caption]): ?>
        <figure class="sample-gallery-item"><img src="<?php echo BASE_URL . '/' . e($photo); ?>" alt="<?php echo e($caption); ?>" loading="lazy"><figcaption><?php echo e($caption); ?></figcaption></figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
