# Nairobi Property & House Management SaaS

A comprehensive, database-driven Property and House Management SaaS application tailored for the Kenyan real estate market (covering Nairobi estates like Kasarani, Roysambu, Westlands, Kilimani, and Lang'ata). Built with native PHP (PDO), Tailwind CSS, MySQL (InnoDB), and fully optimized for local XAMPP environments.

---

## Features

- **Multi-Role Access Control (RBAC):** Super Admin, Property Manager, Accountant, Caretaker, and Tenant portals.
- **Property & Unit Management:** Track multi-story buildings, apartments, bedsitters, commercial shops, and vacancy statuses.
- **Tenant & Lease Tracking:** Manage lease agreements, deposits, monthly billings, and security details.
- **Automated Financials & M-Pesa Integration:** Record rent payments, utility bills (water and electricity), and generate unique printable receipts.
- **Maintenance & Complaints Desk:** Tenants can log plumbing, electrical, or structural issues with priority tags for management review.

---

## Installation & Setup Guide

1. **Clone or Place Project:**
   Move the `Nairobi_Property_And_House_Management_SaaS` folder into your XAMPP `htdocs` directory (`C:\xampp\htdocs\` on Windows).

2. **Start Apache & MySQL:**
   Open your XAMPP Control Panel and start **Apache** and **MySQL**.

3. **Create Database:**
   Open phpMyAdmin (`http://localhost/phpmyadmin`) and create a new database named `nairobi_property_db`.

4. **Import Schema & Seed Data:**
   Import `database/schema.sql` followed by `database/seed.sql` into your newly created database.

5. **Configure Environment:**
   Copy `.env.example` to `.env` and update your database credentials if different from the default XAMPP settings (`root` with no password).

6. **Access the Application:**
   Navigate to your browser:
   `http://localhost/Nairobi_Property_And_House_Management_SaaS/`

---

## Default Login Credentials (from Seed Data)

- **Super Admin:** `admin@nairobiproperty.co.ke` / `Password123`
- **Tenant Portal:** `john.kamau@gmail.com` / `Password123`