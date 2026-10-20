# Tests

The project includes an automated integration suite plus the manual checks that
still need a real browser and Apache/XAMPP.

## 1. Automated integration suite

Prerequisites: MySQL/MariaDB is running and PHP can connect as the configured
test user. The suite never touches `retail_pos`; it uses the isolated
`retail_pos_test` database.

```bat
C:\xampp\php\php.exe tests\setup-test-db.php
C:\xampp\php\php.exe tests\run-tests.php
```

`run-tests.php` recreates the test database, starts its own PHP development
server on `127.0.0.1:8765`, runs 40 checks, and stops the server. It exits with
code `0` only when every check passes.

Connection overrides:

```bat
set TEST_DB_HOST=127.0.0.1
set TEST_DB_PORT=3306
set TEST_DB_USER=root
set TEST_DB_PASS=
set POS_TEST_PORT=8765
C:\xampp\php\php.exe tests\run-tests.php
```

Test configuration is in `tests/config.test.php` and is loaded with
`APP_CONFIG`, so `config/local.php` remains untouched. `setup-test-db.php`
refuses to drop a database whose name does not end in `_test`.

The automated suite covers:

- valid and invalid login, empty fields, CSRF rejection, logout, unknown
  sessions, deactivated accounts, cashier/admin role separation
- product create/edit, duplicate SKU, invalid price/stock values, low-stock
  filtering, deactivation and POS search visibility
- category create/edit/deactivate, duplicate-name protection and POS visibility
- user create/edit/deactivate, duplicate-username checks, password validation,
  password hashing and role enforcement
- inventory restocks, corrections, negative-stock protection and movement logs
- dashboard low-stock alerts, including products that are fully out of stock
- product search, category filtering and authentication on POS endpoints
- cash/card totals, insufficient cash, change due, payment records, stock
  deduction, unavailable products, stock overflow and invalid cart input
- idempotent duplicate checkout prevention and limited-stock serialization
- receipt access control, immutable sale-item snapshots and transaction detail
- daily revenue, date filtering, best sellers and voided/refunded sale exclusion
- discount/tax calculation and database-failure rollback
- two concurrent checkouts competing for limited stock

The suite leaves `retail_pos_test` in place for inspection; run
`setup-test-db.php` again to reset it.

## 2. Static checks

```bat
C:\xampp\php\php.exe -l index.php
C:\xampp\php\php.exe -l admin\dashboard.php
C:\xampp\php\php.exe -l cashier\pos.php
node --check assets\js\app.js
node --check assets\js\dashboard.js
node --check assets\js\pos.js
```

Lint every PHP file in `config/`, `includes/`, `admin/`, and `cashier/` when
making changes.

## 3. Manual browser checks

These cover interactions that the HTTP/API suite cannot exercise:

- Click products into the cart, change quantities, remove lines, and clear the
  cart. Confirm the displayed subtotal, discount, tax, and grand total update.
- Use search and category chips; confirm low/out-of-stock badges are accurate.
- Enter insufficient cash, exact cash, and overpayment; confirm the payment
  modal blocks the first and shows the correct change for the others.
- Complete one card and one e-wallet payment with a reference.
- Confirm the receipt modal opens, prints cleanly, and shows the same totals as
  the checkout response.
- Use the low-stock and out-of-stock filters on Products and Inventory.
- Open Reports, change the date range, and confirm summaries and the
  transaction list agree.
- Export a report CSV and confirm it opens correctly in a spreadsheet.
- Resize to a phone-sized viewport and confirm the POS layout remains usable.

## 4. Security spot checks

- Request `config/local.php`, `includes/functions.php`, and
  `database/schema.sql` through Apache; all must be denied by `.htaccess`.
- Confirm output containing `<script>` is escaped in product, category, and
  user fields.
- Confirm sessions use HttpOnly cookies and `SameSite=Lax`.
- Confirm all POST forms, including login/logout and checkout, include a CSRF
  token.
- Confirm a cashier cannot open `/admin/users.php`, `/admin/settings.php`, or
  `/admin/reports.php` (expect HTTP 403).
- Confirm an admin can open every admin page.
