<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

$page_title = 'About Urban Escape';
$page_description = 'Learn about Urban Escape, a modern property and house management platform for Nairobi landlords, managers, and tenants.';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="flex-1 bg-gray-50">
    <section class="bg-primary text-white py-16">
        <div class="site-container">
            <div class="max-w-3xl">
                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-blue-100 mb-4">About Urban Escape</p>
                <h1 class="text-4xl md:text-5xl font-bold leading-tight">A practical property management system for modern rentals.</h1>
                <p class="mt-5 text-blue-100 text-lg leading-8 max-w-2xl">Urban Escape is designed to help property managers, landlords, and tenants manage listings, leases, maintenance, and communication through one clear digital workflow.</p>
            </div>
        </div>
    </section>

    <section class="py-16">
        <div class="site-container">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 items-center">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500 mb-3">Why it exists</p>
                    <h2 class="text-3xl font-bold text-gray-900 mb-4">Property work should feel organised, simple, and transparent.</h2>
                    <p class="text-gray-600 leading-7">Many rentals still rely on scattered communication, delayed updates, and incomplete records. Urban Escape brings essential property operations into one place so tenants can report issues, managers can monitor work, and landlords can stay informed with less friction.</p>
                </div>
                <div class="bg-white border border-gray-200 rounded-2xl p-7 shadow-sm">
                    <div class="grid grid-cols-2 gap-5 text-center">
                        <div class="bg-blue-50 rounded-xl p-5">
                            <div class="text-2xl font-bold text-primary">01</div>
                            <div class="mt-2 text-sm text-gray-600">Listings</div>
                        </div>
                        <div class="bg-amber-50 rounded-xl p-5">
                            <div class="text-2xl font-bold text-primary">02</div>
                            <div class="mt-2 text-sm text-gray-600">Tenancy</div>
                        </div>
                        <div class="bg-emerald-50 rounded-xl p-5">
                            <div class="text-2xl font-bold text-primary">03</div>
                            <div class="mt-2 text-sm text-gray-600">Maintenance</div>
                        </div>
                        <div class="bg-purple-50 rounded-xl p-5">
                            <div class="text-2xl font-bold text-primary">04</div>
                            <div class="mt-2 text-sm text-gray-600">Reports</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="bg-white py-16 border-t border-gray-200">
        <div class="site-container">
            <div class="max-w-2xl mb-10">
                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500 mb-3">Our mission</p>
                <h2 class="text-3xl font-bold text-gray-900">Built to support every stage of the rental experience.</h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <article class="bg-gray-50 rounded-2xl border border-gray-200 p-6">
                    <div class="text-primary mb-4"><i class="fa-solid fa-house text-2xl"></i></div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">For landlords and managers</h3>
                    <p class="text-gray-600 leading-7">Track portfolio activity, review properties, manage occupancy, and keep tenancy records organised from a single dashboard.</p>
                </article>
                <article class="bg-gray-50 rounded-2xl border border-gray-200 p-6">
                    <div class="text-primary mb-4"><i class="fa-solid fa-user text-2xl"></i></div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">For tenants</h3>
                    <p class="text-gray-600 leading-7">View personal details, submit maintenance requests, raise complaints, and understand the status of their housing-related needs.</p>
                </article>
                <article class="bg-gray-50 rounded-2xl border border-gray-200 p-6">
                    <div class="text-primary mb-4"><i class="fa-solid fa-screwdriver-wrench text-2xl"></i></div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">For support teams</h3>
                    <p class="text-gray-600 leading-7">Review request categories, assign follow-up work, and keep communication moving with property staff and residents.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="py-16">
        <div class="site-container">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 items-center">
                <div class="bg-white border border-gray-200 rounded-2xl p-7 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500 mb-3">Key features</p>
                    <ul class="space-y-4 text-gray-700">
                        <li class="flex gap-3"><span class="mt-1 text-primary"><i class="fa-solid fa-check"></i></span><span>Responsive property listings for browsing and search.</span></li>
                        <li class="flex gap-3"><span class="mt-1 text-primary"><i class="fa-solid fa-check"></i></span><span>Secure user login and role-based access for staff and tenants.</span></li>
                        <li class="flex gap-3"><span class="mt-1 text-primary"><i class="fa-solid fa-check"></i></span><span>Tenant maintenance and complaint submission workflows.</span></li>
                        <li class="flex gap-3"><span class="mt-1 text-primary"><i class="fa-solid fa-check"></i></span><span>Administrators can view, search, and manage people and requests.</span></li>
                    </ul>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500 mb-3">Benefits</p>
                    <h2 class="text-3xl font-bold text-gray-900 mb-4">Clear communication. Better records. Less friction.</h2>
                    <p class="text-gray-600 leading-7">Urban Escape is intended to make rental operations more transparent and easier to manage without creating extra admin friction. It keeps tenant issues visible, links requests to the right property, and gives teams a consistent place to act.</p>
                    <div class="mt-6 flex flex-wrap gap-3">
                        <a class="button button-primary" href="<?php echo BASE_URL; ?>/public/properties.php">View properties</a>
                        <a class="button button-outline" href="<?php echo BASE_URL; ?>/public/contact.php">Contact us</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
