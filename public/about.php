<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

$page_title = 'About Us';
$page_description = 'Learn how the Property and House Management System supports property discovery and the day-to-day coordination of rental homes.';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main id="main-content" class="about-page">
    <section class="about-hero">
        <div class="site-container about-hero-layout">
            <div class="about-hero-copy">
                <p class="about-eyebrow">A clearer way to manage rentals</p>
                <h1>Discover our vision for <span>better property management.</span></h1>
                <p>The Property and House Management System brings property discovery and rental operations into one organized place, helping residents and property teams stay connected to the information and requests that matter.</p>
                <div class="about-actions">
                    <a class="about-button about-button-primary" href="<?php echo BASE_URL; ?>/public/properties.php">Explore Available Properties <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                    <a class="about-button about-button-quiet" href="<?php echo BASE_URL; ?>/public/contact.php">Contact us</a>
                </div>
            </div>
            <figure class="about-hero-image">
                <img src="<?php echo BASE_URL; ?>/assets/modern-residential-building.webp" alt="Illustrative modern residential building; not a specific property listing" width="840" height="560" fetchpriority="high">
                <figcaption><i class="fa-solid fa-house" aria-hidden="true"></i><span>Designed around the details of renting and caring for a home</span></figcaption>
            </figure>
        </div>
    </section>

    <section class="about-purpose-section">
        <div class="site-container about-purpose-layout">
            <div class="about-section-intro">
                <p class="about-eyebrow">Our purpose</p>
                <h2>Less scattered information. <span>More coordinated property work.</span></h2>
                <p>Rental information can be spread across conversations, paper records, and separate follow-ups. That makes it harder to find a home, see what needs attention, or keep the right people up to date.</p>
                <p>This platform is designed to bring those everyday tasks together: browse property and unit information, send a viewing request, keep tenancy and rent records organized, and track maintenance concerns through role-based workflows.</p>
            </div>
            <div class="about-purpose-panel">
                <div class="about-purpose-item">
                    <span><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></span>
                    <div><h3>Find clear listing details</h3><p>Explore available properties using location, home-type, and rent filters.</p></div>
                </div>
                <div class="about-purpose-item">
                    <span><i class="fa-solid fa-comments" aria-hidden="true"></i></span>
                    <div><h3>Keep requests visible</h3><p>Send viewing enquiries and follow maintenance or property concerns through the system.</p></div>
                </div>
                <div class="about-purpose-item">
                    <span><i class="fa-solid fa-folder-open" aria-hidden="true"></i></span>
                    <div><h3>Organize rental records</h3><p>Give authorized users a central place for property, tenant, lease, and rent information.</p></div>
                </div>
            </div>
        </div>
    </section>

    <section class="about-mission-section">
        <div class="site-container">
            <div class="about-section-heading">
                <p class="about-eyebrow">What guides the platform</p>
                <h2>Purposeful tools for a <span>better rental experience.</span></h2>
            </div>
            <div class="about-mission-grid">
                <article class="about-statement-card">
                    <span class="about-card-icon"><i class="fa-solid fa-eye" aria-hidden="true"></i></span>
                    <p class="about-card-kicker">Our Vision</p>
                    <h3>A trusted, innovative property management platform.</h3>
                    <p>To become a trusted and innovative property management platform that transforms how people discover, rent and manage properties through accessible technology, transparency and reliable services.</p>
                </article>
                <article class="about-statement-card about-statement-card-accent">
                    <span class="about-card-icon"><i class="fa-solid fa-compass" aria-hidden="true"></i></span>
                    <p class="about-card-kicker">Our Mission</p>
                    <h3>Connect people through a reliable, user-friendly platform.</h3>
                    <p>To simplify property discovery and management by connecting property owners, managers and tenants through a reliable, user-friendly digital platform that promotes transparency, efficiency and better property management experiences.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="about-values-section">
        <div class="site-container">
            <div class="about-section-heading">
                <p class="about-eyebrow">The principles behind our work</p>
                <h2>Our Core Values</h2>
            </div>
            <ul class="about-values-grid">
                <li><span><i class="fa-solid fa-eye" aria-hidden="true"></i></span><strong>Transparency</strong></li>
                <li><span><i class="fa-solid fa-handshake-angle" aria-hidden="true"></i></span><strong>Trust</strong></li>
                <li><span><i class="fa-solid fa-lightbulb" aria-hidden="true"></i></span><strong>Innovation</strong></li>
                <li><span><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i></span><strong>Accountability</strong></li>
                <li><span><i class="fa-solid fa-heart" aria-hidden="true"></i></span><strong>Customer Satisfaction</strong></li>
            </ul>
        </div>
    </section>

    <section class="about-services-section">
        <div class="site-container">
            <div class="about-section-heading">
                <p class="about-eyebrow">What We Do</p>
                <h2>Practical support for <span>property operations.</span></h2>
                <p>Features are available according to the user’s role and the information configured in the system.</p>
            </div>
            <div class="about-service-grid">
                <article class="about-service-card">
                    <span><i class="fa-solid fa-building" aria-hidden="true"></i></span>
                    <h3>Properties and units</h3>
                    <p>Authorized teams can organize property records, unit details, rent information, and occupancy status.</p>
                </article>
                <article class="about-service-card">
                    <span><i class="fa-solid fa-map-location-dot" aria-hidden="true"></i></span>
                    <h3>Property discovery</h3>
                    <p>Prospective tenants can browse active listings and filter available units by location, type, and monthly rent.</p>
                </article>
                <article class="about-service-card">
                    <span><i class="fa-regular fa-calendar-check" aria-hidden="true"></i></span>
                    <h3>Viewing requests</h3>
                    <p>Visitors can send a preferred date and time for a viewing; requests are recorded for management follow-up.</p>
                </article>
                <article class="about-service-card">
                    <span><i class="fa-solid fa-file-contract" aria-hidden="true"></i></span>
                    <h3>Tenant support</h3>
                    <p>Tenant tools provide access to rent information, receipts, notices, and ways to raise property concerns.</p>
                </article>
                <article class="about-service-card">
                    <span><i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i></span>
                    <h3>Maintenance coordination</h3>
                    <p>Tenants can submit maintenance concerns, while authorized staff can review and coordinate follow-up.</p>
                </article>
                <article class="about-service-card">
                    <span><i class="fa-solid fa-people-roof" aria-hidden="true"></i></span>
                    <h3>Property administration</h3>
                    <p>Role-based management tools help authorized teams organize property, tenancy, and operational records.</p>
                </article>
            </div>
            <p class="about-capability-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> The system displays information and actions available in the current application setup; it does not imply that unconfigured services or listings are live.</p>
        </div>
    </section>

    <section class="about-people-section">
        <div class="site-container">
            <div class="about-section-heading">
                <p class="about-eyebrow">Who we serve</p>
                <h2>Who We Serve: <span>different day-to-day needs.</span></h2>
            </div>
            <div class="about-people-grid">
                <article class="about-person-card">
                    <span><i class="fa-solid fa-user" aria-hidden="true"></i></span>
                    <h3>Tenants</h3>
                    <p>Browse available rental homes, request a viewing, and use tenant tools for rent information and maintenance concerns where access is provided.</p>
                </article>
                <article class="about-person-card">
                    <span><i class="fa-solid fa-key" aria-hidden="true"></i></span>
                    <h3>Landlords</h3>
                    <p>Review property, occupancy, tenancy, and rental activity through the authorized management views available to their account.</p>
                </article>
                <article class="about-person-card">
                    <span><i class="fa-solid fa-user-tie" aria-hidden="true"></i></span>
                    <h3>Property managers</h3>
                    <p>Coordinate property operations, tenant needs, and relevant records through the management tools available to their account.</p>
                </article>
                <article class="about-person-card">
                    <span><i class="fa-solid fa-helmet-safety" aria-hidden="true"></i></span>
                    <h3>Caretakers</h3>
                    <p>Access assigned property information and help coordinate resident requests and day-to-day property follow-up.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="about-benefits-section">
        <div class="site-container about-benefits-layout">
            <div>
                <p class="about-eyebrow">Our Commitment</p>
                <h2>Making property management <span>easier and more accessible.</span></h2>
                <p class="about-benefits-intro">We are committed to making property management easier, more organized and accessible through technology.</p>
            </div>
            <ul class="about-benefits-list">
                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Property and unit information is grouped in a consistent place.</span></li>
                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>House seekers can filter listings instead of relying only on unstructured enquiries.</span></li>
                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Viewing and maintenance requests can be recorded for follow-up.</span></li>
                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Access to tenant and operational records follows the application’s role-based workflows.</span></li>
            </ul>
        </div>
    </section>

    <section class="about-cta-section">
        <div class="site-container about-cta-content">
            <div>
                <p class="about-eyebrow">Take the next step</p>
                <h2>Looking for a home or want to learn more?</h2>
                <p>Explore the current listings, get in touch with a question, or sign in to the tools available to your account.</p>
            </div>
            <div class="about-actions">
                <a class="about-button about-button-primary" href="<?php echo BASE_URL; ?>/public/properties.php">Explore Available Properties</a>
                <a class="about-button about-button-light" href="<?php echo BASE_URL; ?>/public/contact.php">Contact us</a>
                <a class="about-signin-link" href="<?php echo BASE_URL; ?>/public/login.php">Log in <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
