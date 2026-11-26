# P0-1 — ERD chi tiết + Aggregate Boundaries

> Nguồn: docs/foundation/02_DOMAIN_MODEL.md. Quy ước: PK `id CHAR(26)` ULID string (tương thích MySQL/SQLite, tránh auto-inc lộ số lượng đơn); tiền `INT minor`; `*_at DATETIME`; soft-delete `deleted_at NULL`.

## Aggregates (bounded contexts)
- **Catalog** (products, variants, categories, brands, images, attributes): không biết tồn kho. Aggregate root: `Product`.
- **Inventory** (items, ledger, reservations): nguồn sự thật tồn kho duy nhất. Root: `InventoryItem(variant_id)`.
- **Cart** (carts, lines, promotions): pricing snapshot, không trừ kho. Root: `Cart`.
- **Order** (orders, lines, payments, shipments, refunds, transitions): snapshot bất biến. Root: `Order`.
- **Customer** (customers, addresses): guest nullable. Root: `Customer`.
- **Marketing** (coupons, rules): chỉ đọc từ Cart/Checkout qua service, không FK ngược.
- **SEO** (seo_meta, redirects): key `(entity_type, entity_id)`, không FK cứng để tránh cascade xóa nhầm.

## DDL draft (MySQL 8, driver-aware notes inline)
```sql
CREATE TABLE brands (id CHAR(26) PRIMARY KEY, name VARCHAR(191) NOT NULL, slug VARCHAR(191) NOT NULL UNIQUE, created_at DATETIME NOT NULL) ENGINE=InnoDB;
CREATE TABLE categories (id CHAR(26) PRIMARY KEY, parent_id CHAR(26) NULL, name VARCHAR(191) NOT NULL, slug VARCHAR(191) NOT NULL UNIQUE, sort INT NOT NULL DEFAULT 0, INDEX idx_parent (parent_id)) ENGINE=InnoDB;
CREATE TABLE products (id CHAR(26) PRIMARY KEY, brand_id CHAR(26) NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL UNIQUE, status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME NULL, INDEX idx_status (status)) ENGINE=InnoDB;
CREATE TABLE product_variants (id CHAR(26) PRIMARY KEY, product_id CHAR(26) NOT NULL, sku VARCHAR(64) NOT NULL UNIQUE, price_minor INT NOT NULL, compare_price_minor INT NULL, tax_class VARCHAR(32) NOT NULL DEFAULT 'standard', weight_g INT NOT NULL DEFAULT 0, INDEX idx_product (product_id)) ENGINE=InnoDB;
CREATE TABLE product_images (id CHAR(26) PRIMARY KEY, product_id CHAR(26) NOT NULL, url VARCHAR(512) NOT NULL, alt VARCHAR(255) NOT NULL DEFAULT '', sort INT NOT NULL DEFAULT 0, INDEX idx_product (product_id)) ENGINE=InnoDB;
CREATE TABLE inventory_items (id CHAR(26) PRIMARY KEY, variant_id CHAR(26) NOT NULL UNIQUE, on_hand INT NOT NULL DEFAULT 0, reserved INT NOT NULL DEFAULT 0, low_threshold INT NOT NULL DEFAULT 0) ENGINE=InnoDB;
CREATE TABLE stock_ledger (id CHAR(26) PRIMARY KEY, variant_id CHAR(26) NOT NULL, delta INT NOT NULL, reason VARCHAR(64) NOT NULL, ref_type VARCHAR(32) NULL, ref_id CHAR(26) NULL, actor VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_variant_at (variant_id, created_at)) ENGINE=InnoDB;
CREATE TABLE reservations (id CHAR(26) PRIMARY KEY, variant_id CHAR(26) NOT NULL, cart_id CHAR(26) NULL, order_id CHAR(26) NULL, qty INT NOT NULL, state ENUM('held','released','committed','expired') NOT NULL DEFAULT 'held', expires_at DATETIME NOT NULL, INDEX idx_variant_state (variant_id, state)) ENGINE=InnoDB;
CREATE TABLE carts (id CHAR(26) PRIMARY KEY, customer_id CHAR(26) NULL, currency CHAR(3) NOT NULL DEFAULT 'VND', state ENUM('open','locked','converted','abandoned') NOT NULL DEFAULT 'open', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB;
CREATE TABLE cart_lines (id CHAR(26) PRIMARY KEY, cart_id CHAR(26) NOT NULL, variant_id CHAR(26) NOT NULL, qty INT NOT NULL, unit_price_minor INT NOT NULL, UNIQUE KEY uq_cart_variant (cart_id, variant_id)) ENGINE=InnoDB;
CREATE TABLE customers (id CHAR(26) PRIMARY KEY, email VARCHAR(191) NOT NULL UNIQUE, name VARCHAR(191) NOT NULL DEFAULT '', created_at DATETIME NOT NULL) ENGINE=InnoDB;
CREATE TABLE addresses (id CHAR(26) PRIMARY KEY, customer_id CHAR(26) NULL, label VARCHAR(64) NOT NULL DEFAULT 'home', line1 VARCHAR(255) NOT NULL, city VARCHAR(128) NOT NULL, phone VARCHAR(32) NOT NULL, INDEX idx_customer (customer_id)) ENGINE=InnoDB;
CREATE TABLE orders (id CHAR(26) PRIMARY KEY, number VARCHAR(32) NOT NULL UNIQUE, customer_id CHAR(26) NULL, guest_email VARCHAR(191) NULL, currency CHAR(3) NOT NULL DEFAULT 'VND', subtotal_minor INT NOT NULL, discount_minor INT NOT NULL DEFAULT 0, shipping_minor INT NOT NULL DEFAULT 0, tax_minor INT NOT NULL DEFAULT 0, grand_total_minor INT NOT NULL, state VARCHAR(32) NOT NULL DEFAULT 'pending', idempotency_key VARCHAR(64) NOT NULL UNIQUE, created_at DATETIME NOT NULL, INDEX idx_state_at (state, created_at)) ENGINE=InnoDB;
CREATE TABLE order_lines (id CHAR(26) PRIMARY KEY, order_id CHAR(26) NOT NULL, variant_id CHAR(26) NOT NULL, sku VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, qty INT NOT NULL, unit_price_minor INT NOT NULL, INDEX idx_order (order_id)) ENGINE=InnoDB;
CREATE TABLE payments (id CHAR(26) PRIMARY KEY, order_id CHAR(26) NOT NULL, provider VARCHAR(32) NOT NULL, amount_minor INT NOT NULL, state VARCHAR(32) NOT NULL DEFAULT 'pending', provider_ref VARCHAR(128) NULL, idempotency_key VARCHAR(64) NOT NULL UNIQUE, INDEX idx_order (order_id)) ENGINE=InnoDB;
CREATE TABLE shipments (id CHAR(26) PRIMARY KEY, order_id CHAR(26) NOT NULL, provider VARCHAR(32) NOT NULL, tracking VARCHAR(128) NULL, state VARCHAR(32) NOT NULL DEFAULT 'pending', INDEX idx_order (order_id)) ENGINE=InnoDB;
CREATE TABLE refunds (id CHAR(26) PRIMARY KEY, order_id CHAR(26) NOT NULL, payment_id CHAR(26) NOT NULL, amount_minor INT NOT NULL, reason VARCHAR(255) NOT NULL DEFAULT '', state VARCHAR(32) NOT NULL DEFAULT 'pending', INDEX idx_order (order_id)) ENGINE=InnoDB;
CREATE TABLE order_transitions (id CHAR(26) PRIMARY KEY, order_id CHAR(26) NOT NULL, `from` VARCHAR(32) NOT NULL, `to` VARCHAR(32) NOT NULL, actor VARCHAR(64) NOT NULL, reason VARCHAR(255) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, INDEX idx_order (order_id)) ENGINE=InnoDB;
CREATE TABLE coupons (id CHAR(26) PRIMARY KEY, code VARCHAR(64) NOT NULL UNIQUE, rules JSON NOT NULL, state ENUM('active','disabled') NOT NULL DEFAULT 'active') ENGINE=InnoDB;
CREATE TABLE seo_meta (entity_type VARCHAR(32) NOT NULL, entity_id CHAR(26) NOT NULL, title VARCHAR(191) NOT NULL, description VARCHAR(255) NOT NULL DEFAULT '', canonical VARCHAR(512) NULL, robots VARCHAR(64) NOT NULL DEFAULT 'index,follow', og_image VARCHAR(512) NULL, PRIMARY KEY (entity_type, entity_id)) ENGINE=InnoDB;
CREATE TABLE redirects (`from` VARCHAR(512) PRIMARY KEY, `to` VARCHAR(512) NOT NULL, code SMALLINT NOT NULL DEFAULT 301) ENGINE=InnoDB;
```
Notes: SQLite dev dùng `TEXT` thay ENUM; `JSON` → TEXT + validate app-level; TEXT cấm DEFAULT (driver-aware).
