# 🛍️ Aleltu Multi-Branch POS & Inventory Management System

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![MariaDB](https://img.shields.io/badge/MariaDB-10.4%2B-003545?logo=mariadb&logoColor=white)](https://mariadb.org)
[![Offline-First](https://img.shields.io/badge/Offline--First-PWA%20%2B%20IndexedDB-10b981)](https://developer.mozilla.org)
[![Ethiopian Calendar](https://img.shields.io/badge/Calendar-Ethiopian%20JDN%20Engine-8b5cf6)](config.php)
[![License](https://img.shields.io/badge/License-Proprietary-red)](#license)

**Aleltu POS** is a modern, full-featured, offline-first multi-branch Point of Sale (POS), Inventory Control, and Financial Reporting web application engineered specifically for Ethiopian businesses. It features seamless offline transaction queueing, real-time background sync, dynamic stock tracking, automatic Ethiopian date conversion (with leap-year Pagume 5/6 support), and role-based access control.

---

## 🌟 Key Features

### 1. 📴 Offline-First POS Engine (PWA + IndexedDB)
* **Zero Interruption:** Cashiers and sellers can continue selling seamlessly even during complete internet outages.
* **Service Worker Caching:** Application shell and assets are cached locally for offline load speeds.
* **Background Sync Engine:** Queued sales are stored in client-side IndexedDB and automatically synchronized in atomic batches to the MariaDB server once internet connectivity is restored.
* **Conflict Resolution & Audit Trail:** Over-sale handling with server-side inventory locking and dedicated cancellation logging (`conflict_center.php`).

### 2. 📅 Native Ethiopian Calendar Engine (JDN Precision)
* **Accurate Julian Day Number (JDN) Algorithm:** Built-in exact calculation for all 13 Ethiopian months (መስከረም – ጳጉሜ).
* **Leap Year Support:** Correctly handles ጳጉሜ 6 (Pagume 6) in Ethiopian leap years (e.g. 2019 ዓ.ም / 2027 G.C.).
* **Bilingual Date Display:** Ethiopian calendar dates shown alongside Gregorian timestamps across POS receipts, Excel sheets, and audit reports.

### 3. 👥 Multi-Branch & Role-Based Access Control (RBAC)
* **Super Admin:** Multi-branch oversight, master financial analytics, user and branch management.
* **Branch Admin:** Branch-specific stock management, cashier monitoring, daily cash settlement, and edit history.
* **Manager & Cashier:** Cash registration, daily withdrawal management, and transaction audits.
* **Seller / POS Operator:** Fast touch POS interface, receipt generation, stock reception, and offline selling.
* **6-Digit User PINs:** Secure one-click 6-digit random PIN generation with automatic `bcrypt` hashing.

### 4. 📦 Stock & Inventory Control
* **Dynamic Stock Inflow Tracking (`admin_view_stock.php`):** Tracks batch receipts, source suppliers, unit costs, and current stock pools.
* **Product-Level Offline Permissions (`offline_controller.php`):** Admins can toggle whether specific items are eligible for offline sales and specify max quantity limits.
* **Edit History Audit (`edit_history.php`):** Complete change logs for product name or price alterations with previous vs new values and editor identity.

### 5. 📊 Financial Reporting & Excel Exports
* **One-Click Excel Reports:** Styled XLSX and UTF-8 BOM CSV exports for sales, expenses, withdrawals, and stock movements.
* **Real-Time Dashboards:** Daily, weekly, monthly profit metrics, top-selling items, payment method breakdowns (Cash, Telebirr, CBE, Bank).

---

## 🏗️ Technology Stack

| Layer | Technologies Used |
|---|---|
| **Backend** | PHP 8.x (Native, Prepared Statements, Strict Modes) |
| **Database** | MySQL / MariaDB 10.4+ (`utf8mb4_unicode_ci`) |
| **Frontend** | HTML5, Modern Vanilla CSS3, JavaScript (ES6+) |
| **Offline Storage** | Service Workers, Cache API, IndexedDB |
| **Spreadsheet Engine** | PhpSpreadsheet (`\PhpOffice\PhpSpreadsheet`) & UTF-8 BOM CSV |
| **Icons & Fonts** | FontAwesome 6, Google Fonts (Noto Sans Ethiopic, Inter) |

---

## 🚀 Installation & Local Setup

### Prerequisites
* **XAMPP / WAMP / LAMP** with PHP 8.0+ and MariaDB/MySQL.
* **Composer** (optional, for PhpSpreadsheet dependencies).

### Step-by-Step Setup

1. **Clone the Repository:**
   ```bash
   git clone https://github.com/shetesfa/aleltu-pos-backend.git
   ```

2. **Move to Web Directory:**
   Place the project folder inside your web server directory (e.g. `C:\xampp\htdocs\aleltu`).

3. **Database Configuration:**
   * Open phpMyAdmin (`http://localhost/phpmyadmin`).
   * Create a new database named `aleltu` with collation `utf8mb4_unicode_ci`.
   * Import the database backup file `aleltu now real database.sql`.

4. **Verify Database Connection (`config.php`):**
   ```php
   $host = 'localhost';
   $username = 'root';
   $password = '';
   $database = 'aleltu';
   ```

5. **Launch Application:**
   * Open your web browser and navigate to `http://localhost/aleltu`.
   * Log in with your Super Admin or Admin credentials.

---

## 📁 Project Directory Structure

```
aleltu/
├── api/                        # REST API endpoints
│   ├── sync/batch.php          # Offline IndexedDB transaction sync processor
│   └── reports/alerts.php      # Low stock and system alert handler
├── assets/
│   ├── css/                    # Custom stylesheets
│   └── js/                     # Offline engine, sync manager, device identification
│       ├── sync-engine.js      # Background batch sync coordinator
│       ├── indexeddb-manager.js# Local IndexedDB schema and operations
│       ├── device-manager.js   # Unique device UUID manager
│       └── offline-ux.js       # Offline status pill and user notifications
├── image/                      # System logos and receipt branding
├── config.php                  # Database connection, CSRF tokens, Ethiopian JDN calendar
├── index.php                   # Authentication login portal with auto-upgrade bcrypt
├── seller_pos.php              # Cashier POS workspace with offline capability
├── admin_dashboard.php         # Branch administrator dashboard
├── admin_view_stock.php        # Stock inventory and dynamic supplier tracking
├── super_admin.php             # Master multi-branch administration center
├── manage_users.php            # User management and instant 6-digit PIN generator
├── register_user.php           # User creation form with role selector
├── change_password.php         # Secure PIN and password update screen
├── offline_controller.php      # Product offline permissions and quantity limits
├── conflict_center.php         # Cancelled offline sales and sync conflict logs
├── edit_history.php            # Product audit trail and price diff viewer
├── export_report_excel.php     # Excel report generation engine
├── service-worker.js           # PWA service worker for asset caching and offline routing
└── README.md                   # Project documentation
```

---

## 🔒 Security Hardening Highlights

* **Prepared Statements:** 100% of parameterized SQL operations prevent SQL Injection vulnerabilities.
* **CSRF Protection:** Form tokens with strict session validation on all critical state-changing actions.
* **Password Hashing:** Standard `PASSWORD_DEFAULT` (`bcrypt`) password storage with legacy migration support.
* **Role Verification:** Strict RBAC middleware prevents unauthorized access to admin workflows.
* **Database Charset:** Enforced `utf8mb4` character set to guarantee Amharic data integrity.

---

## 📄 License & Confidentiality

This project is proprietary and confidential. Unauthorized copying, distribution, modification, or deployment without explicit authorization from the copyright holder is strictly prohibited.

© 2026 **Aleltu POS**. All rights reserved.
