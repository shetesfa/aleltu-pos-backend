<div align="center">

  <img src="image/photo_2026-01-12_07-44-10.jpg" alt="Aleltu Logo" width="130" style="border-radius: 24px; box-shadow: 0 10px 30px rgba(67, 97, 238, 0.25); margin-bottom: 12px;" />

  # 🛍️ ALELTU POS & INVENTORY CONTROL
  ### *Enterprise Multi-Branch Offline-First Point of Sale & Inventory Engine*

  <p align="center">
    <strong>የተሟላ የሽያጭ፣ የስቶክ ቁጥጥር እና የፋይናንስ ሪፖርት ማኔጅመንት ሲስተም</strong>
  </p>

  <p align="center">
    <a href="#-key-features"><img src="https://img.shields.io/badge/Architecture-Offline--First%20PWA-4361ee?style=for-the-badge&logo=pwa&logoColor=white" alt="Offline First" /></a>
    <a href="#-technology-stack"><img src="https://img.shields.io/badge/PHP-8.1%2B%20Native-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8" /></a>
    <a href="#-technology-stack"><img src="https://img.shields.io/badge/Database-MariaDB%20%2F%20MySQL-003545?style=for-the-badge&logo=mariadb&logoColor=white" alt="MariaDB" /></a>
    <a href="#-ethiopian-calendar-engine"><img src="https://img.shields.io/badge/Calendar-Ethiopian%20JDN%20Leap%20Engine-10b981?style=for-the-badge" alt="Ethiopian Calendar" /></a>
    <a href="#-security-hardening"><img src="https://img.shields.io/badge/Security-RBAC%20%2B%20CSRF%20%2B%20BCrypt-f59e0b?style=for-the-badge&logo=auth0&logoColor=white" alt="Security" /></a>
  </p>

</div>

---

## 📖 Overview

**Aleltu POS** is an enterprise-grade, high-performance web and PWA Point of Sale ecosystem designed specifically for multi-branch retail and wholesale operations. Engineered with an **Offline-First** core, it guarantees 100% uninterrupted cashier workflow during network dropouts, synchronizing sales automatically in atomic batches once online.

It features native support for the **Ethiopian Calendar (JDN engine)**, dynamic supplier and stock inflow tracking, 6-digit cryptographic PIN authentication, and styled Excel exports with UTF-8 BOM encoding for clean Amharic spreadsheets.

---

## ✨ Key Features & Capabilities

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                            ALELTU POS ECOSYSTEM                             │
├──────────────────────┬──────────────────────┬───────────────────────────────┤
│  📴 Offline Engine    │  📅 Ethiopian Math   │  📦 Dynamic Inventory         │
│  • PWA Service Worker│  • 13 Months JDN     │  • Real-time stock pools      │
│  • IndexedDB Queue   │  • Leap Pagume 5 & 6 │  • Dynamic supplier tracking  │
│  • Auto Batch Sync   │  • Bilingual receipts│  • Product-level offline caps │
├──────────────────────┼──────────────────────┼───────────────────────────────┤
│  👥 Multi-Branch RBAC│  📊 Smart Analytics  │  🔐 Security Hardened         │
│  • Super Admin Hub   │  • One-click Excel   │  • Prepared Statements        │
│  • Branch Admins     │  • Daily Settlements │  • 6-digit PIN Bcrypt Hash    │
│  • Cashiers & Sellers│  • Profit Analytics  │  • Strict CSRF Validation     │
└──────────────────────┴──────────────────────┴───────────────────────────────┘
```

### 1. 📴 Offline-First Transaction Engine
* **Zero Downtime:** Cashiers continue making sales, generating digital receipts, and calculating change offline.
* **Client Storage (IndexedDB):** Transactions, item inventories, and local configurations are stored safely in browser storage.
* **Intelligent Background Sync:** Detects connection recovery and sends batch payloads via `/api/sync/batch.php` with server row-locking to eliminate phantom stock duplication.
* **Audit Center (`conflict_center.php`):** Tracks and audits seller-cancelled offline sales and synchronizations.

### 2. 📅 Precision Ethiopian Calendar Engine
* **Pure JDN Calculation:** Converts Gregorian timestamps to precise Ethiopian dates without third-party API dependencies.
* **Leap Year Support:** Fully calculates **ጳጉሜ 6** in leap cycles (e.g. 2019 ዓ.ም / 2027 G.C.).
* **Bilingual Display:** Receipts, daily settlement views, and report tables display both Ethiopian and Gregorian dates seamlessly.

### 3. 📦 Stock & Multi-Branch Management
* **Stock Inflow Tracker (`admin_view_stock.php`):** Detailed breakdown of stock additions (የስቶክ ገቢ ዙር ብዛት), batch units, and supplier sources.
* **Offline Permissions (`offline_controller.php`):** Toggle offline selling eligibility and max quantity ceilings per individual product.
* **Change Log Trail (`edit_history.php`):** Audit trail of price changes, product renaming, and modifications with user timestamps.

### 4. 👥 Access Hierarchy & Role Permissions

| Role | Access Scope | Key Capabilities |
|---|---|---|
| **👑 Super Admin** | All Branches | Full system control, branch provisioning, master analytics, user management. |
| **🛡️ Branch Admin** | Specific Branch | Local stock management, cashier monitoring, offline rule configuration. |
| **💼 Manager / Cashier** | Specific Branch | Cash settlement, daily expense/withdrawal approvals, transaction reports. |
| **🛒 Seller / POS Operator** | POS Terminal | Touch POS interface, receipt generation, stock intake confirmation. |

---

## 🛠️ Technology Stack

* **Core Backend:** PHP 8.1+ (Native, Strict Typing, Prepared Statements, Zero Bloat)
* **Database Engine:** MySQL / MariaDB 10.4+ (`utf8mb4_unicode_ci`)
* **Client Architecture:** Progressive Web App (PWA), Service Worker, IndexedDB, Vanilla ES6+
* **Report Generators:** PhpSpreadsheet (`\PhpOffice\PhpSpreadsheet`) & UTF-8 BOM CSV
* **UI & Typography:** Modern Vanilla CSS3 (Mobile-First Responsive Grid), Google Fonts (*Noto Sans Ethiopic*, *Inter*), FontAwesome 6

---

## 🚀 Quick Start & Installation

### 1. Clone the Repository
```bash
git clone https://github.com/shetesfa/aleltu-pos-backend.git
```

### 2. Directory Placement
Copy or move the repository files to your local server document root:
* **XAMPP (Windows):** `C:\xampp\htdocs\aleltu`
* **Linux (Apache/Nginx):** `/var/www/html/aleltu`

### 3. Database Initialization
1. Open phpMyAdmin (`http://localhost/phpmyadmin`) or MySQL CLI.
2. Create a database named `aleltu` with character set `utf8mb4` and collation `utf8mb4_unicode_ci`:
   ```sql
   CREATE DATABASE aleltu CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
3. Import the clean production schema file:
   ```bash
   mysql -u root -p aleltu < "aleltu now real database.sql"
   ```

### 4. Configure Connection (`config.php`)
Open `config.php` and verify your local database credentials:
```php
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'aleltu';
```

### 5. Launch & Login
Open your browser and navigate to:
```
http://localhost/aleltu
```

---

## 📁 System Architecture & Directory Map

```
aleltu/
├── api/
│   ├── sync/batch.php          # Atomic offline transaction batch synchronizer
│   └── reports/alerts.php      # Real-time low-stock alert dispatcher
├── assets/
│   ├── css/                    # Modular responsive stylesheets
│   └── js/
│       ├── sync-engine.js      # Background IndexedDB sync coordinator
│       ├── indexeddb-manager.js# Local IndexedDB schema handler
│       ├── device-manager.js   # Unique device UUID generator
│       └── offline-ux.js       # Dynamic online/offline indicator pill
├── image/                      # High-resolution logos & PWA icons
├── config.php                  # Database connection, CSRF tokens, Ethiopian JDN math
├── index.php                   # Secure authentication portal with auto-upgrade bcrypt
├── seller_pos.php              # Modern cashier POS terminal with offline mode
├── admin_dashboard.php         # Branch administrator operational hub
├── admin_view_stock.php        # Real-time stock inventory & dynamic supplier logs
├── super_admin.php             # Master multi-branch enterprise administration
├── manage_users.php            # User access management & 6-digit PIN generator
├── register_user.php           # User creation form with instant PIN copy
├── change_password.php         # Password & PIN update portal
├── offline_controller.php      # Granular product offline rules & quantity limits
├── conflict_center.php         # Cancelled offline sales & conflict resolution hub
├── edit_history.php            # Product audit trail and price diff log
├── export_report_excel.php     # Styled financial spreadsheet export engine
├── service-worker.js           # PWA caching and offline asset delivery
└── README.md                   # System documentation
```

---

## 🔒 Security & Quality Assurance

* 🛡️ **SQL Injection Immunity:** Every single database interaction is parameterized using PDO or MySQLi Prepared Statements.
* 🔑 **Cryptographic Password Storage:** Uses `password_hash()` (`bcrypt`) with silent automatic upgrades for legacy credentials.
* 🛡️ **Cross-Site Request Forgery (CSRF):** Non-idempotent HTTP actions strictly validate session cryptographic tokens.
* 🌐 **UTF-8 Multi-Byte Reliability:** Full Unicode `utf8mb4` support across PHP and MariaDB ensures zero character corruption for Amharic Fidel text.

---

## 📄 License & Intellectual Property

This software and its source code are **Proprietary & Confidential**.  
Unauthorized reproduction, distribution, reverse engineering, or commercial deployment without explicit written permission from the copyright owner is strictly prohibited.

© 2026 **Aleltu POS System**. All rights reserved.
