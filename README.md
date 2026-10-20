# Group 6 — Retail Point-of-Sale System

A web-based Point-of-Sale system for a small retail store. Cashiers process
purchases quickly while administrators manage products, inventory, users, and
sales reports. Every sale recalculates totals on the server, records the
payment, generates a receipt, deducts stock, and is stored for reporting.

Built with **PHP 8**, **MySQL/MariaDB**, and vanilla **HTML/CSS/JavaScript**
(no build step, no CDN required — works offline in XAMPP).

---

## Features

**Cashier / Point of Sale**
- Fast product search by name, SKU, or barcode (type a code and press Enter)
- Category filter and product grid with live stock availability
- Shopping cart with quantity controls, remove, and clear
- Server-side totals with optional configurable discount and tax
- Payment methods: cash, card, e-wallet — with tendered amount and change
- Professional printable receipt and receipt preview

**Administrator**
- Dashboard with KPI cards, 7-day sales chart, best sellers, recent sales,
  and low-stock alerts
- Product management: add, edit, deactivate/activate, search, stock adjustment
- Category management
- User management: create/edit accounts, roles, activate/deactivate, reset password
- Sales history with date/cashier/status filters, transaction details, and void
  (void restores stock and writes an inventory movement)
- Date-filtered reports (revenue trend, top products, sales by category, sales by
  cashier, **payment-method breakdown**, and per-transaction item drill-down) with
  CSV export; failed/voided sales are excluded from every success metric
- Configurable store information, currency, tax, discount, and low-stock defaults

**Security**
- Passwords hashed with `password_hash()` / verified with `password_verify()`,
  rehashed on login when the algorithm changes
- Session-based authentication with `session_regenerate_id()`, strict-mode
  session IDs, HttpOnly/SameSite cookies and a Secure flag on HTTPS
- CSRF protection on every state-changing request (forms and JSON API)
- Server-side role enforcement (hiding menus is never enough)
- Prepared statements everywhere (no SQL injection)
- Output escaped with `htmlspecialchars()`; JSON config encoded with hex flags
- Security headers (nosniff, SAMEORIGIN, CSP frame-ancestors, Referrer-Policy)
- CSV exports protected against spreadsheet formula injection
- Database credentials stored in the ignored `config/local.php`; detailed errors
  only when `APP_DEBUG` is on, otherwise safe messages are shown and real causes
  are written to the server log
- Inventory and money handled with DB transactions, row locks and `DECIMAL`

---

## Technology stack

| Layer     | Technology                          |
|-----------|-------------------------------------|
| Frontend  | HTML5, CSS3 (design system), vanilla JS |
| Backend   | PHP 8+ (PDO with `pdo_mysql`)       |
| Database  | MySQL / MariaDB (utf8mb4)           |
| Server    | Apache via XAMPP                    |
| Editor    | Visual Studio Code                  |

---

## Requirements

| Component | Minimum | Tested in this project |
|-----------|---------|------------------------|
| Windows | Windows 10 (64-bit) | Windows 10/11 |
| XAMPP | PHP 8.0+, Apache 2.4+, MySQL 8.0+ or MariaDB 10.4+ | PHP 8.2.12, Apache 2.4.58, MariaDB 10.4.32 |
| PHP extensions | `pdo_mysql`, `mbstring`, `openssl` | Included with XAMPP |
| VS Code | Current stable | Optional editor; no build step needed |
| Browser | Chrome, Edge, or Firefox | Current versions |

Check the PHP version and database driver from PowerShell or Command Prompt:

```bat
C:\xampp\php\php.exe -v
C:\xampp\php\php.exe -m | findstr /I "pdo_mysql mbstring openssl"
C:\xampp\mysql\bin\mysql.exe --version
```

The application runs entirely on the local machine. It has no paid-hosting,
cloud-database, CDN, API-key, or external-service requirement; CSS, JavaScript,
icons, and fonts are served from `assets/`. Internet access is only needed to
download XAMPP/VS Code the first time.

---

## Windows 10 + XAMPP + VS Code setup

### 1. Install XAMPP and VS Code

Install XAMPP from Apache Friends using the default location, `C:\xampp`.
Install VS Code if you want syntax highlighting, an integrated terminal, and
optional PHP tooling. The application does **not** require a VS Code extension,
Node.js, Composer, or a front-end build process.

Recommended VS Code extensions (optional): **PHP Intelephense** for editing and
**PHP Debug** if you later configure Xdebug. Neither is required to run the app.

### 2. Start Apache and MySQL

1. Open **XAMPP Control Panel** (`C:\xampp\xampp-control.exe`).
2. Click **Start** next to **Apache**. Wait for the module to turn green.
3. Click **Start** next to **MySQL**. Wait for it to turn green.
4. If a port-conflict warning appears, see [Troubleshooting](#9-run-tests-and-troubleshoot).

Apache normally listens on port `80` and MySQL on `3306`. You can verify them in
the XAMPP Control Panel or by opening <http://localhost> (Apache) and
`http://localhost/phpmyadmin` (phpMyAdmin).

### 3. Put the project in `htdocs` and open it in VS Code

Copy or move the project so that `index.php` is directly inside a folder under
`C:\xampp\htdocs`, for example:

```text
C:\xampp\htdocs\retail-pos\index.php
C:\xampp\htdocs\retail-pos\login.php
C:\xampp\htdocs\retail-pos\database\schema.sql
```

Do not create a second nested folder such as
`C:\xampp\htdocs\retail-pos\retail-pos\`. The folder name determines the URL:
`retail-pos` is available at `http://localhost/retail-pos/`.

In VS Code choose **File → Open Folder** and select
`C:\xampp\htdocs\retail-pos`. Open the integrated terminal with
**Terminal → New Terminal**. If VS Code cannot find PHP, add this to your local
`.vscode/settings.json`:

```json
{
  "php.validate.executablePath": "C:\\xampp\\php\\php.exe"
}
```

The app auto-detects its base path, so it also works from the web root or a
different folder name. If you keep the project outside `htdocs`, configure an
Apache virtual host or alias; a simple `htdocs` copy is easiest for Windows 10.

### 4. Create the database in phpMyAdmin

1. Start Apache and MySQL, then open <http://localhost/phpmyadmin>.
2. Click **Import**. Do **not** select a database first; `schema.sql` creates it.
3. Choose `C:\xampp\htdocs\retail-pos\database\schema.sql` and click **Import**.
   This creates the `retail_pos` database, tables, keys, and constraints.
4. Select **retail_pos** in the left sidebar, open **Import** again, and import
   `C:\xampp\htdocs\retail-pos\database\seed.sql`.
5. Import in that order: the seed needs the tables created by the schema.

The seed is optional sample/demo data. It creates the two demo accounts,
categories, products, settings, and opening-stock records used by the guided
setup below. For a clean installation with no demo accounts or products, import
only `schema.sql` and create the administrator manually in step 6.

Command-line alternative from **Command Prompt**:

```bat
cd /d C:\xampp\htdocs\retail-pos
C:\xampp\mysql\bin\mysql.exe -u root -p < database\schema.sql
C:\xampp\mysql\bin\mysql.exe -u root -p < database\seed.sql
```

PowerShell treats `<` differently; use `cmd /c` if your VS Code terminal is
PowerShell:

```bat
cmd /c "C:\xampp\mysql\bin\mysql.exe -u root -p < C:\xampp\htdocs\retail-pos\database\schema.sql"
cmd /c "C:\xampp\mysql\bin\mysql.exe -u root -p < C:\xampp\htdocs\retail-pos\database\seed.sql"
```

> Upgrading a database created with the older `database/migrations/001` to `005`
> files? Run `database/migrations/006_part11_schema_rename.sql` **once**; it
> renames the legacy columns and backfills the payment/inventory audit fields.
> New installs do not need it. `database/` is the canonical setup for fresh
> installs; `database/migrations/` is kept for reference and upgrades.

### 5. Configure the database connection safely

Database credentials are kept outside source control. Copy the template:

```bat
cd /d C:\xampp\htdocs\retail-pos
copy config\local.example.php config\local.php
```

Edit `config/local.php`. The password must be the password for the database user
you actually use. **Do not assume every XAMPP installation has a blank root
password**: some installations set one during setup, and a blank value will then
fail with `Access denied`.

For local development, either use your own root password or, preferably, create
a dedicated application user. Run this once in phpMyAdmin's **SQL** tab (replace
the placeholder with a long, unique password):

```sql
CREATE USER 'retail_pos_app'@'localhost' IDENTIFIED BY 'replace-with-a-long-random-password';
CREATE USER 'retail_pos_app'@'127.0.0.1' IDENTIFIED BY 'replace-with-a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON retail_pos.* TO 'retail_pos_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON retail_pos.* TO 'retail_pos_app'@'127.0.0.1';
FLUSH PRIVILEGES;
```

The app only needs those four data permissions. Use `root` (or another account
with `CREATE`/`DROP`) only for importing the schema and creating the database
user. If you use a password-managed root account instead of a dedicated user,
put that password in `DB_PASS`; never leave a password in a file that will be
committed or shared.

Example `config/local.php` using the dedicated user:

```php
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'retail_pos');
define('DB_USER', 'retail_pos_app');
define('DB_PASS', 'the-same-long-random-password'); // never commit this file
define('APP_DEBUG', true);                           // local development only
```

`config/local.php` is ignored by git. Environment variables (`DB_HOST`,
`DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `APP_DEBUG`) can override the file.
Set `APP_DEBUG` to `false` whenever the site is not being actively developed;
then users see safe messages while details go to the PHP error log.

Do not expose the local XAMPP site to the internet, do not forward ports
`80`/`3306`, and change every demo password before showing the system to anyone
outside your machine.

### 6. Create the initial administrator securely

**Guided local/demo setup (recommended):**

1. Open <http://localhost/retail-pos/> and sign in with the seeded demo
   administrator (`admin` / `Admin@12345`). Change it immediately; these
   credentials are public in this README and are not suitable for real use.
2. Open **Users → Add user**. Create a new account with a unique username, your
   own name, role **Administrator**, and a strong password of at least 12
   characters (prefer a password-manager-generated passphrase). Save it.
3. Sign out and sign in with the new administrator account to verify it works.
4. Sign back in as the original demo admin if needed, then edit or deactivate
   the `admin` demo account. Also change or deactivate the `cashier` demo account,
   or create a real cashier account instead.
5. The application prevents you from deactivating the last active administrator,
   so keep at least one working admin account.

**Clean installation without seed/demo accounts:** import only `schema.sql`,
generate a bcrypt hash locally, and insert one administrator in phpMyAdmin. Run
this in a private terminal and enter the password when prompted:

```bat
C:\xampp\php\php.exe -r "echo password_hash(readline('New admin password: '), PASSWORD_DEFAULT), PHP_EOL;"
```

Then use phpMyAdmin → `retail_pos` → **SQL**:

```sql
INSERT INTO users (name, username, password_hash, role, status)
VALUES ('Store Administrator', 'owner', 'PASTE_THE_BCRYPT_HASH_HERE', 'admin', 1);
```

Never store the plaintext password in SQL, the README, a screenshot, or source
control. Keep the generated hash and the password separate. If you use this
clean route, open **Settings** after login to set the store name and create your
own categories and products.

### 7. Open the local application

The default URL is:

**<http://localhost/retail-pos/>**

If Apache uses another port (for example `8080`), use
`http://localhost:8080/retail-pos/`. If the folder has a different name, use that
name in the URL, for example `http://localhost/RetailPOS/`. A trailing slash is
recommended.

### 8. Sign in as administrator and cashier

After the guided seed setup:

| Role | Username | Password | Use |
|------|----------|----------|-----|
| Administrator | your new admin | your own strong password | Full management, reports, users, settings |
| Cashier | `cashier` until changed | `Cashier@123` | POS and own receipts only |

The demo administrator is `admin` / `Admin@12345` only until you replace it.
Replace both demo passwords immediately after the first login. Cashiers cannot
open admin pages even if they type the URL directly.

### 9. Run tests and troubleshoot

The automated integration suite exercises authentication, products, inventory,
checkout, stock protection, receipts, reports, and rollback. It uses an isolated
`retail_pos_test` database and never modifies `retail_pos`:

```bat
cd /d C:\xampp\htdocs\retail-pos
C:\xampp\php\php.exe tests\setup-test-db.php
C:\xampp\php\php.exe tests\run-tests.php
```

MySQL must be running. If your MySQL server has a password, provide the test
connection values first (PowerShell shown; use `set` in Command Prompt):

```powershell
$env:TEST_DB_USER = "root"
$env:TEST_DB_PASS = "your-mysql-root-password"
C:\xampp\php\php.exe tests\run-tests.php
```

The test database setup needs an account allowed to create/drop
`retail_pos_test`; the normal application user only has data permissions. The
suite starts its own PHP server on `127.0.0.1:8765`; set `POS_TEST_PORT` if that
port is busy. See [`tests/README.md`](tests/README.md) for the full automated and
manual checklist.

Common fixes:

| Problem | What to check |
|---------|----------------|
| `Access denied for user` | The `DB_USER`/`DB_PASS` in `config/local.php` do not match MySQL. Confirm the user exists for both `localhost` and `127.0.0.1`, or use your root password. |
| `Unknown database 'retail_pos'` | Import `database/schema.sql` first, then `database/seed.sql`. |
| `Database connection failed` | MySQL is stopped, port `3306` is occupied, or credentials are wrong. Start MySQL in XAMPP and check the PHP error log. |
| `could not find driver` / missing `pdo_mysql` | You are using the wrong PHP build. Use `C:\xampp\php\php.exe`, not another PHP installation. |
| 404 or “requested URL not found” | The project folder name and URL must match. Visit `http://localhost/retail-pos/` when `index.php` is in `C:\xampp\htdocs\retail-pos`. |
| Apache will not start / port 80 in use | Stop IIS, Skype, or another Apache, or change Apache's port in XAMPP and use `http://localhost:8080/...`. |
| MySQL will not start / port 3306 in use | Stop another MySQL/MariaDB service in Windows Services, or change the XAMPP MySQL port and update `DB_PORT`. |
| Blank page or HTTP 500 | Set `APP_DEBUG` to `true` locally, retry, then inspect `C:\xampp\apache\logs\error.log`. Set it back to `false` afterward. |
| “Invalid or expired CSRF token” | Refresh the page and submit again; do not reuse an old tab after signing out. |
| phpMyAdmin import fails | Import `schema.sql` before `seed.sql`, import into the server (not into an unrelated database), and confirm the files were copied intact. |
| Test suite fails to start | Start MySQL, free port `8765`, and pass `TEST_DB_PASS` if your MySQL account has a password. |

For a local demo, keep Apache and MySQL running, open
`http://localhost/retail-pos/`, and use the account you created. No paid hosting
or external service is required.

---

## Project structure

```
retail-pos/
|-- index.php                  Role-aware entry point
|-- login.php                  Sign-in page
|-- logout.php                 CSRF-protected sign-out
|-- README.md
|-- .gitignore
|-- .htaccess                  Apache hardening
|-- config/
|   |-- app.php                App settings + local config loader
|   |-- database.php           DB defaults + PDO singleton
|   |-- local.example.php      Template for local.php
|   `-- local.php              Real credentials (git-ignored)
|-- includes/
|   |-- bootstrap.php          Session, base URL, security headers, includes
|   |-- auth.php               Login, roles and permission guards
|   |-- csrf.php               CSRF token helpers
|   |-- functions.php          URL, money, request, validation and pagination helpers
|   |-- settings.php           Key/value application settings
|   |-- sales.php              Totals, checkout, void and inventory movements
|   |-- header.php             Page head, top bar and layout start
|   |-- sidebar.php            Sidebar navigation
|   |-- footer.php             Layout end and shared scripts
|   |-- flash.php              Flash messages
|   |-- forbidden.php          403 page
|   |-- icons.php              Inline SVG icon set
|   `-- receipt_body.php       Shared receipt markup
|-- admin/
|   |-- dashboard.php          Admin overview (KPIs, chart, alerts)
|   |-- users.php              User list + activate/deactivate
|   |-- user_form.php          Add / edit user
|   |-- categories.php         Category management
|   |-- products.php           Product list + deactivate
|   |-- product_form.php       Add / edit product
|   |-- inventory.php          Stock adjustments and movement history
|   |-- sales.php              Sales history (role-aware)
|   |-- sale_view.php          Transaction detail + void
|   |-- reports.php            Date-filtered reports + CSV export
|   `-- settings.php           Store, tax, discount configuration
|-- cashier/
|   |-- pos.php                Point-of-sale screen
|   |-- product_search.php     Product search JSON endpoint
|   |-- checkout.php           Server-side checkout JSON endpoint
|   `-- receipt.php            Printable receipt + embeddable fragment
|-- assets/
|   |-- css/
|   |   |-- style.css          Core design system
|   |   |-- dashboard.css      Dashboard and report extras
|   |   |-- pos.css            POS screen
|   |   |-- receipt.css        Receipt + thermal print layout
|   |   `-- login.css          Login screen
|   |-- js/
|   |   |-- app.js             Shared UI behaviour
|   |   |-- dashboard.js       Dependency-free chart renderer
|   |   `-- pos.js             POS cart, payment and receipt logic
|   `-- img/favicon.svg
|-- database/
|   |-- schema.sql             Canonical schema for a fresh install
|   |-- seed.sql               Settings, demo users, categories and products
|   `-- migrations/            Legacy/upgrade SQL kept for reference
`-- tests/
    |-- run-tests.php          Automated PART 14 integration suite
    |-- setup-test-db.php      Rebuilds the isolated retail_pos_test database
    |-- concurrency_worker.php Concurrent checkout test worker
    |-- config.test.php        APP_CONFIG test-only database settings
    `-- README.md              Automated + manual test checklist
```

---

## Usage

### Administrator workflow

1. Sign in and open **Dashboard** for today's sales, transaction count,
   best sellers, and low-stock alerts.
2. Open **Settings** to set the store profile, currency symbol, tax, discount,
   and default low-stock threshold.
3. Open **Categories** and create the groups used by the catalogue.
4. Open **Products → Add product** to create items with a unique SKU, selling
   price, opening stock, and low-stock threshold. Edit or deactivate products
   as the catalogue changes.
5. Open **Inventory** after deliveries or stock counts to record restocks and
   corrections. Every change is written to the movement log with the acting
   user.
6. Open **Users** to create cashier or administrator accounts, reset passwords,
   and activate/deactivate staff. The last active administrator cannot be
   removed or deactivated.
7. Open **Sales** or **Reports** to review transactions, filter by date,
   cashier, status, or receipt number, inspect line items, export CSV, and void
   an incorrect completed sale. Voiding restores stock and marks the payment
   refunded.
8. Back up the `retail_pos` database regularly through phpMyAdmin **Export** or
   `mysqldump`; backups are not scheduled by the application.

### Cashier workflow

1. Sign in with a cashier account. Cashiers are sent to the POS screen and
   cannot open administrator pages.
2. Search by product name or SKU, or use the category chips. Product cards show
   current stock and low/out-of-stock status.
3. Add products to the cart, adjust quantities, remove lines, or clear the cart.
4. Choose **Cash**, **Card**, or **E-Wallet**. For cash, enter the tendered
   amount and confirm the displayed change. For card/e-wallet, optionally enter
   a reference number.
5. Complete the sale. The server recalculates totals, records the payment,
   deducts stock, and returns the receipt only after the transaction commits.
6. Print or view the receipt. Cashiers can access only their own sales and
   receipts.

### Daily operating sequence

- Set up categories, products, and opening stock before the first sale.
- Record supplier deliveries through **Inventory** rather than editing stock
  silently.
- Use the POS for all sales so receipts, payments, and stock movements stay in
  sync.
- Make corrections by voiding the completed sale (administrator only), not by
  deleting rows in the database.
- Check the dashboard and reports at the end of the day, then export a backup.

---

## Limitations

This is a local, single-store POS project, not a hosted enterprise platform.

- No multi-branch, multi-register, tenant, or offline-synchronization support.
- No payment-gateway or card-terminal integration. Card/e-wallet references are
  entered manually and recorded for audit purposes only.
- No barcode-scanner SDK. USB keyboard-wedge scanners work because SKU search
  accepts typed input, but no hardware is configured by the app.
- No supplier, purchase-order, customer-account, loyalty, layaway, partial
  refund, or exchange workflows. Sales can only be fully voided.
- Tax and discount settings are global; there are no per-product tax rules or
  multi-currency conversions.
- The on-screen reports show the latest 200 transactions for the selected range.
  Use CSV export for further analysis.
- XAMPP/Apache is suitable for local development and classroom demonstration,
  not public internet hosting. A public deployment needs HTTPS, hardened server
  permissions, off-site backups, and a proper production database account.
- The seed accounts (`admin` / `Admin@12345`, `cashier` / `Cashier@123`) are
  public demo credentials and must be replaced before use.
- Automated tests cover HTTP, API, database, security, and concurrency behavior.
  Browser-only visuals, printing, CSV rendering in a spreadsheet, and Apache
  `.htaccess` behavior remain part of the manual checklist.

---

## Database design

All tables use **InnoDB** (transactions, row locks, foreign keys), `utf8mb4`, and
store money as `DECIMAL` (never `FLOAT`).

| Table | Purpose | Key columns |
|-------|---------|-------------|
| `users` | Staff accounts | `username` unique, `email` unique, `role`, `status`, `password_hash` |
| `categories` | Product groups | `name` unique, `status` |
| `products` | Catalogue and current stock | `sku` unique, `price`, `stock_quantity`, `low_stock_threshold`, `status` |
| `sales` | Sale header | `receipt_number` unique, `idempotency_key` unique, `cashier_id`, `total_amount`, `status` |
| `sale_items` | Line items (immutable snapshot) | `product_name_snapshot`, `sku_snapshot`, `unit_price`, `line_total` |
| `payments` | One payment per sale | `sale_id` unique, `payment_method`, `amount_paid`, `payment_status` |
| `inventory_movements` | Append-only stock ledger | `product_id`, `user_id`, `sale_id`, signed `quantity_change`, `stock_before/after`, `reference_type`, `reference_id` |
| `settings` | Store / tax / discount configuration | `setting_key` (primary key) |

Design decisions:

- **Snapshot line items.** `sale_items` copies the product name, SKU, and unit
  price at the moment of sale, so renaming, re-pricing, or deactivating a product
  never changes an old receipt.
- **Referential integrity.** Deleting a sale cascades to its items and payment;
  deleting a product sets `sale_items.product_id` to `NULL` but keeps the
  snapshot. Products that appear in the stock ledger are protected by `RESTRICT`
  and are deactivated by the app instead of deleted.
- **Auditability.** Voiding a sale sets the sale to `voided`, marks its payment
  `refunded`, and writes a positive `adjustment` movement with
  `reference_type = 'sale'` and the receipt number as `reference_id`.
- **Idempotency.** `sales.idempotency_key` is unique, so a retried checkout
  returns the original sale. `uq_inventory_sale_product_type` makes a second
  stock deduction for the same sale/product/type impossible.
- **Data checks.** `CHECK` constraints reject negative prices, stock, tendered
  amounts, and quantities; `ENUM`s constrain roles, statuses, payment methods,
  and movement/reference types.

---

## How checkout works (defence notes)

1. The browser sends **only** product IDs, quantities, payment method, an
   optional discount percentage, and a unique **idempotency key** to
   `cashier/checkout.php`.
2. The server validates the cashier's session and cart, opens a transaction,
   and locks the products with `SELECT … FOR UPDATE`.
3. Current prices and stock are reloaded from the database.
4. All totals are recalculated **in integer cents** (decimal-safe) —
   `subtotal → discount → tax → total`.
5. Payment is validated (method whitelist; cash tendered ≥ total; change
   computed as `tendered − total`).
6. The `sales`, `sale_items`, and `payments` rows are inserted, stock is deducted
   with a conditional guard (`... WHERE stock_quantity >= ?`), and an
   `inventory_movements` record is written for each line.
7. A unique receipt number (`RCP-YYYYMMDD-NNNN`) is generated; the unique index
   on `receipt_number` guarantees no duplicates, and the insert retries the
   sequence if two checkouts collide.
8. Everything commits in a single transaction. If any step fails, the whole
   transaction is rolled back — so a payment is never recorded without its sale,
   items, and stock movements.
9. The receipt is only shown **after** the server confirms the commit.

**Duplicate-submission protection:** the POS sends an idempotency key per sale.
`sales.idempotency_key` has a unique index, so if the same request is retried
(double-click, network retry, page reload) the server returns the original sale
instead of creating a second one. The button is also disabled during processing.

Prices or totals sent by the browser are never trusted.

Voiding a sale (admin) reverses the process and restores stock with a movement
record, keeping the audit trail intact. Products referenced by sales are never
deleted — they are **deactivated**.

---

## Inventory management

- Stock is deducted inside the checkout transaction, never before. The
  `products.stock_quantity` column is `UNSIGNED` with a `CHECK (stock_quantity >= 0)`
  constraint; the deduction uses `UPDATE ... WHERE stock_quantity >= ?` on a row
  locked with `SELECT … FOR UPDATE`, so concurrent checkouts cannot oversell.
- If any step of a checkout fails, the transaction rolls back, so stock, payments,
  and movements stay consistent.
- Every change writes an `inventory_movements` row containing the product, acting
  user (`user_id`), movement type (`initial`, `restock`, `sale`, `adjustment`),
  signed quantity change, stock before/after, a **reference type and reference ID**
  (receipt number, supplier DR, ticket), optional notes, and a timestamp.
- Administrators record authorized restocks and count corrections on the
  **Inventory** page. Corrections are row-locked and rejected if they would make
  stock negative, and the full movement log is filterable by product/reference/type.
- Low-stock alerts appear on the **Dashboard** and the **Products** page. A product
  is low when `stock_quantity <= low_stock_threshold` and out of stock at zero.
- A unique index on `(sale_id, product_id, movement_type)` makes a second deduction
  for the same sale/product impossible at the database level, reinforcing the
  checkout idempotency key.

---

## Roles and permissions

| Capability                       | Cashier | Administrator |
|----------------------------------|:-------:|:-------------:|
| Use the Point of Sale            | ✔       | ✔             |
| View/print own receipts          | ✔       | ✔             |
| View all sales / others' sales   | ✘       | ✔             |
| Manage products and categories   | ✘       | ✔             |
| View inventory / movement log    | ✘       | ✔             |
| Record stock adjustments         | ✘       | ✔             |
| Manage users                     | ✘       | ✔             |
| Reports, settings, dashboard     | ✘       | ✔             |
| Void a sale                      | ✘       | ✔             |

Permissions are enforced on the server (`require_admin()`), so a cashier cannot
reach administrative pages even by typing the URL directly.

---

## Design system

The core stylesheet (`assets/css/style.css`) defines reusable CSS variables for
colors, spacing, radii, shadows, and typography. Page-specific rules live in
`dashboard.css`, `pos.css`, `receipt.css`, and `login.css`:

- Deep navy / charcoal surfaces, white cards, restrained emerald-teal accent
- Clear success / warning / danger / info status colors
- Consistent buttons, forms, tables, cards, badges, modals, alerts, empty and
  loading states
- Responsive collapsible sidebar and mobile layout
- Accessible contrast, visible focus rings, and reduced-motion support
- A built-in dark theme is available via `data-theme="dark"` on `<html>`

Charts are drawn with a small dependency-free canvas library (`assets/js/dashboard.js`).

---

## Testing

See [`tests/README.md`](tests/README.md) for the automated integration suite
(`php tests/run-tests.php`), PHP/JS syntax checks, and the manual browser
authentication, POS, inventory, sales, and security checklist. The automated
suite uses the isolated `retail_pos_test` database and never modifies
`retail_pos`.

**Last verification:** 40/40 automated integration checks passed, all PHP files
passed `php -l`, and all JavaScript files passed `node --check`.

## Final acceptance checklist

| Requirement | Evidence |
|-------------|----------|
| Admin/cashier login with correct permissions | Authentication and RBAC checks; server-side `require_admin()` / `require_login()` guards |
| Admins manage products, categories, users, inventory | CRUD/deactivation and validation checks for all four areas |
| Cashiers search products, manage cart, process sales | POS search/API checks, cart client code, and checkout tests |
| Server-side subtotal/total calculation | Cash, card, discount, tax, change, and rounding checks |
| Consistent payment and sale recording | Transaction, payment-method, tendered, change, and reference checks |
| View and print receipts | Receipt, embedded receipt, snapshot, and unauthorized-access checks; print CSS and manual print check |
| Stock deducted only after successful checkout | Stock/movement assertions plus transaction rollback test |
| Overselling and duplicate sales prevented | Row-locked limited-stock, concurrent-checkout, and idempotency tests |
| Low-stock alerts | Dashboard alert panel and low-stock filter checks |
| Dashboard/report statistics use database data | Daily revenue, best sellers, date filters, transaction details, and void exclusion checks |
| Failed transactions do not corrupt data | Forced database-failure rollback and concurrent-shortage checks |
| Professional, responsive, consistent UI | Shared design system, responsive CSS, focus/reduced-motion/ARIA review, and manual browser checklist |
| Runs locally on documented Windows/XAMPP setup | Local config smoke test plus Windows/XAMPP/VS Code installation guide |
| README covers setup, configuration, usage, testing, limitations | Installation, database, credential, account, usage, testing, and limitations sections above |

---

## Group members

Group 6 — Retail Point-of-Sale System (college project).

---

*For the project defence: the money math, the checkout transaction, role
enforcement, and CSRF handling are the key technical talking points — all
implemented and testable end to end.*
