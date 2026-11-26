> SUPERSEDED by [CI_CD_RUNBOOK.md](./CI_CD_RUNBOOK.md) — kept for history.

# SIRO B2C Server Deployment Runbook

This runbook deploys the SIRO B2C application to the dedicated Linux server
using Docker Compose, a dedicated Linux user, MySQL, Redis, and the existing
host Nginx reverse proxy.

Never store SSH passwords, database passwords, JWT secrets, provider secrets,
or API tokens in this document, Git, release archives, or evidence artifacts.

## 1. Target Architecture

- Host user: `sirocommerce`
- Release root: `/opt/sirocommerce`
- Active release: `/opt/sirocommerce/current`
- Backend container: `sirocommerce-backend`, internal host port `8090`
- Storefront container: `sirocommerce-storefront`, internal host port `3100`
- Admin container: `sirocommerce-admin`, internal host port `3005`
- MySQL container: `sirocommerce-mysql`, private Docker network only
- Redis container: `sirocommerce-redis`, private Docker network only
- Public domain: `https://ecommerce.sirophp.com`
- Host reverse proxy: Nginx

Only local server resources are used. Payment, tax, shipping, search, and
messaging remain sandbox/native until live credentials and certification are
provided.

## 2. Prerequisites

On the server:

```bash
docker version
docker compose version
systemctl is-active docker
nginx -v
certbot --version
```

On the workstation:

- PHP 8.2+, Composer, Node/npm, and the repository dependencies.
- SSH key or temporary bootstrap access for the server.
- A release identifier, normally an immutable commit SHA or UTC timestamp.

Before deployment, run:

```powershell
composer test --working-dir backend
npm run typecheck
npm run test
composer check:api-contract --working-dir backend
composer check:api-handoff --working-dir backend
```

## 3. Create the Dedicated Linux User

Run as a sudo-capable administrator on the server:

```bash
id sirocommerce >/dev/null 2>&1 || \
  useradd --system --create-home --home-dir /home/sirocommerce \
  --shell /usr/sbin/nologin sirocommerce

usermod -aG docker sirocommerce
install -d -o sirocommerce -g sirocommerce -m 0755 \
  /opt/sirocommerce/releases \
  /opt/sirocommerce/shared \
  /opt/sirocommerce/shared/storage
```

Prefer SSH keys for the deploy user. Do not leave root-password-only access as
the long-term deployment method.

## 4. Create a Release Directory

Use a unique UTC release identifier:

```bash
RELEASE_ID=20260930093630
install -d -o sirocommerce -g sirocommerce -m 0755 \
  "/opt/sirocommerce/releases/$RELEASE_ID"
```

Create the release archive from the repository, excluding local dependencies,
credentials, local databases, and test artifacts:

```powershell
tar --exclude=node_modules --exclude='*/node_modules' `
    --exclude=vendor --exclude='*/vendor' `
    --exclude=.git --exclude='*/.git' `
    --exclude=.next --exclude='*/.next' `
    --exclude=backend/storage --exclude='*/test-results' `
    --exclude='*/coverage' `
    -czf "$env:TEMP\sirocommerce-$RELEASE_ID.tar.gz" .
```

Upload and extract:

```bash
scp "sirocommerce-$RELEASE_ID.tar.gz" <deploy-user>@<server>:/tmp/
ssh <deploy-user>@<server> "tar -xzf /tmp/sirocommerce-$RELEASE_ID.tar.gz \
  -C /opt/sirocommerce/releases/$RELEASE_ID"
ssh <deploy-user>@<server> "chown -R sirocommerce:sirocommerce \
  /opt/sirocommerce/releases/$RELEASE_ID"
```

Do not switch `current` until the release has built successfully.

## 5. Create Production Environment Secrets

Create `/opt/sirocommerce/releases/$RELEASE_ID/.env` on the server with mode
`0600`. Generate fresh values; do not reuse values from local development.

Required baseline values:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ecommerce.sirophp.com
APP_LOCALE=vi
APP_FALLBACK_LOCALE=en
JWT_SECRET=<at-least-32-random-characters>
FE_SHARED_SECRET=<random-frontend-trust-secret>

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=sirocommerce
DB_USERNAME=sirocommerce_app
DB_PASSWORD=<random-database-password>
DB_ROOT_PASSWORD=<random-database-root-password>

CORS_ALLOWED_ORIGINS=https://ecommerce.sirophp.com
CACHE_DRIVER=redis
REDIS_HOST=redis
REDIS_PORT=6379
QUEUE_DRIVER=redis

PAYMENT_MODE=sandbox
PAYMENT_CERTIFICATION_SANDBOX=1
PAYMENT_CERTIFICATION_LIVE=0
TRANSACTIONAL_MESSAGE_PROVIDER=native
TRANSACTIONAL_MESSAGE_MODE=sandbox
DHL_MODE=sandbox
TAX_PROVIDER=native
PROVIDER_LIVE_SMOKE=0
SEARCH_PROVIDER=native
```

Use a secret manager or protected server file for real deployments. Never put
the file in the release archive or commit it.

## 6. Build the Docker Images

The repository contains:

- `deploy/Dockerfile.backend`
- `deploy/Dockerfile.frontend`
- `deploy/docker-compose.yml`

Build with:

```bash
cd /opt/sirocommerce/releases/$RELEASE_ID
docker compose --env-file .env -f deploy/docker-compose.yml \
  build backend storefront admin
```

The backend image must include `pdo_mysql`, `mbstring`, and `redis`. The
frontend images must complete both Next.js builds without type errors.

## 7. Start the Data Dependencies

Start only the dedicated MySQL and Redis containers:

```bash
docker compose --env-file .env -f deploy/docker-compose.yml up -d mysql redis
docker compose --env-file .env -f deploy/docker-compose.yml ps
```

Wait until both services report healthy:

```bash
docker inspect --format '{{.State.Health.Status}}' sirocommerce-mysql
docker inspect --format '{{.State.Health.Status}}' sirocommerce-redis
```

## 8. Run Database Migrations

For migrations that create MySQL functions or triggers, enable the setting
inside the dedicated MySQL container only:

```bash
DB_ROOT_PASSWORD="$(sed -n 's/^DB_ROOT_PASSWORD=//p' .env)"
docker exec -e MYSQL_PWD="$DB_ROOT_PASSWORD" sirocommerce-mysql \
  mysql -uroot -e 'SET GLOBAL log_bin_trust_function_creators=1'
unset DB_ROOT_PASSWORD
```

Run migrations from the backend image:

```bash
docker compose --env-file .env -f deploy/docker-compose.yml \
  run --rm backend php backend/siro migrate
```

Confirm that migration output finishes with `Migration completed`. Never use
`migrate:fresh`, `migrate:reset`, or destructive commands on a live database.

## 9. Start the Application Containers

```bash
docker compose --env-file .env -f deploy/docker-compose.yml up -d \
  backend storefront admin
docker compose --env-file .env -f deploy/docker-compose.yml ps
```

All application ports must bind to `127.0.0.1`; they must not be published
directly to the Internet. Nginx is the only public entry point.

## 10. Configure Nginx

Install `deploy/nginx-ecommerce.conf` at:

```text
/etc/nginx/sites-available/ecommerce.sirophp.com
```

Enable it:

```bash
ln -sfn /etc/nginx/sites-available/ecommerce.sirophp.com \
  /etc/nginx/sites-enabled/ecommerce.sirophp.com
nginx -t
systemctl reload nginx
```

The Nginx `/api/` location must inject the private `X-Siro-FE` value from the
protected environment file. Never print that value to logs or command output.

## 11. Configure HTTPS

Create the HTTP ACME challenge location first, then issue a certificate:

```bash
install -d -m 0755 /var/www/html/.well-known/acme-challenge
certbot certonly --webroot -w /var/www/html \
  -d ecommerce.sirophp.com \
  --non-interactive --agree-tos --keep-until-expiring
```

The HTTPS Nginx server must use:

```text
/etc/letsencrypt/live/ecommerce.sirophp.com/fullchain.pem
/etc/letsencrypt/live/ecommerce.sirophp.com/privkey.pem
```

Then validate and reload:

```bash
nginx -t
systemctl reload nginx
```

## 12. Seed or Import Catalog Data

Do not seed demo or B2B data into production without approval. The deployed
catalog is empty until a controlled import or approved B2C seed is run.

Required B2C data dimensions:

- Store: `default`
- Channel: `web`
- Locale: `vi`
- Currency: `VND`
- Published products and variants
- Prices and inventory
- Categories/tags
- Product media

After import, verify:

```bash
curl -fsS https://ecommerce.sirophp.com/api/products
curl -fsS 'https://ecommerce.sirophp.com/api/products?search=<term>'
```

The response must contain a non-zero `total` and the expected scope.

## 13. Smoke Test the Live Deployment

```bash
curl -fsS https://ecommerce.sirophp.com/health/live
curl -fsS https://ecommerce.sirophp.com/api/products
curl -fsSI https://ecommerce.sirophp.com/
```

Check manually in a browser:

- Homepage loads without catalog error.
- Header/cart count matches actual cart contents.
- Product detail opens and can add to cart.
- Cart quote and checkout pages render.
- Mobile width `320px` has no horizontal overflow.
- Footer links and language/currency controls work.
- Console has no repeated `422` analytics errors.

`/health/live` proves process availability. `/health/ready` must be treated as
the production decision endpoint; a `503` means investigate dependencies,
provider readiness, search index, worker, outbox, or unresolved operational
state before declaring the release ready.

## 14. Admin Access

Admin is intentionally bound to `127.0.0.1:3005` until a separate approved
admin hostname and certificate are configured. For temporary operator access:

```bash
ssh -L 3005:127.0.0.1:3005 <deploy-user>@<server>
```

Then open `http://127.0.0.1:3005` locally. Do not expose port `3005` directly.

## 15. Release Switch and Rollback

Only after smoke tests pass:

```bash
ln -sfn /opt/sirocommerce/releases/$RELEASE_ID /opt/sirocommerce/current
docker compose --env-file /opt/sirocommerce/current/.env \
  -f /opt/sirocommerce/current/deploy/docker-compose.yml up -d \
  backend storefront admin
```

Keep the previous release directory. To roll back application code:

```bash
ln -sfn /opt/sirocommerce/releases/<previous-release> \
  /opt/sirocommerce/current
docker compose --env-file /opt/sirocommerce/current/.env \
  -f /opt/sirocommerce/current/deploy/docker-compose.yml up -d \
  backend storefront admin
```

Do not roll back database migrations by deleting migration records. Use the
approved backup/restore procedure for destructive schema changes.

## 16. Post-Deploy Evidence

Record, without secrets or customer data:

- Release ID and source commit.
- Docker image IDs.
- Migration result and count.
- Container health states.
- `/health/live` and `/health/ready` result.
- Catalog count and scope.
- Browser smoke result.
- Nginx certificate expiry.
- Known blocked external certifications.

Run the full gate from the release workstation after the live target exists:

```powershell
$env:BASE_URL = "https://ecommerce.sirophp.com"
$env:PLAYWRIGHT_BASE_URL = "https://ecommerce.sirophp.com"
npm run release:gate
```

Do not convert `BLOCKED_EXTERNAL` or `503 readiness` into a pass.

## 17. Current Live Audit Follow-Up

The first live audit found these issues requiring remediation before a public
launch claim:

- Catalog has zero products and the homepage shows a catalog-load error.
- Static cart sample conflicts with the live empty catalog.
- Mobile account/cart/quote pages overflow at 320px.
- Analytics tracking returns HTTP `422`.
- HTML security headers differ from API headers.
- `/robots.txt` and `/favicon.ico` return `404`.
- `/health/ready` returns `503`.

Fix and re-audit these items after the approved catalog seed/import.
