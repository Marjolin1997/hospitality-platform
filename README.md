# Hospitality Platform

A professional multi-tenant business management and POS platform designed primarily for bars, cafés, coffee shops, lounges, and similar hospitality businesses.

## Product vision

The platform gives each business a secure workspace for daily operations: POS and orders, tables, staff and permissions, bar/kitchen workflow, products and menus, inventory and warehouses, purchasing, invoices, revenue and expenses, fiscalization integration, reporting, and auditability.

## Primary users

- Business owner / administrator
- Manager
- Waiter
- Bartender / bar staff
- Cashier
- Finance / accountant
- Inventory / warehouse staff
- Custom roles with granular permissions

## Architecture direction

- Backend: PHP / Laravel REST API
- Frontend: React 19 + TypeScript
- Database: MySQL
- Cache / queues: Redis
- Reverse proxy: Nginx
- Local and production-oriented containerization: Docker Compose
- API namespace: `/api/v1`
- Multi-tenancy: business-scoped data isolation from day one
- Authorization: granular permissions and policies, not hard-coded role checks
- Fiscalization: isolated integration boundary with idempotency, retries, immutable audit history, and no guessed government API behavior

## Core domains

1. Identity, businesses, locations, users, roles and permissions
2. POS, tables, orders, order items and payments
3. Menu, products, categories, modifiers and recipes
4. Bar / kitchen preparation workflow
5. Inventory, warehouses, stock movements and purchasing
6. Finance, cash sessions, revenue, expenses and supplier documents
7. Fiscal and non-fiscal invoices
8. Fiscalization integration and audit trail
9. Reports and analytics
10. Settings, notifications and system audit logs

## Engineering principles

- Tenant isolation is mandatory on tenant-owned resources.
- Monetary values are never represented using floating-point arithmetic.
- Completed fiscal/financial records are not silently edited or deleted.
- Inventory uses an auditable stock-movement ledger.
- Sale items preserve historical snapshots of names, prices and tax data.
- External fiscal/payment operations use idempotency and safe retry semantics.
- Sensitive business actions are audit logged.
- API, domain logic and external integrations remain separated.
- Automated tests and CI are part of the platform foundation.

## Status

Greenfield foundation. Architecture and implementation are being built incrementally with production-quality boundaries from the start.

## Local demo workspace

The repository includes an idempotent, local/testing-only demo workspace that exercises the operational menus with realistic data without contacting a real fiscal provider.

Prepare it from the project root:

```bash
docker compose up -d
docker compose exec backend php artisan demo:prepare
```

`demo:prepare` runs pending migrations, seeds the demo workspace, clears cached framework state, creates the public storage link when needed, and finishes with `demo:check`.

Demo owner credentials:

- Email: `demo.owner@hospitality.local`
- Password: `Demo#Hospitality2026!`
- Business: `Hospitality Demo Lab`
- Primary location: `Alexanderplatz Café`

The demo includes staff/roles/invitations, multiple locations, products and preparation stations, venue tables, cash registers and shifts, inventory, transfers and counts, suppliers and purchase orders, expenses, reports, and invoices in several fiscal states.

The seeded fiscal documents are **local test fixtures only**. QR payloads are prefixed with `DEMO|LOCAL_ONLY`; they are rendered by the invoice/receipt UI so print and QR layouts can be reviewed without sending anything to DPT.

Useful diagnostics:

```bash
docker compose exec backend php artisan migrate:status
docker compose exec backend php artisan demo:check
docker compose exec backend ./bin/test
docker compose exec frontend npm run build
docker compose exec frontend npm run lint
docker compose exec frontend npm run test
```

`demo:check` reports pending migrations, owner permission coverage, Redis queue/cache/session connections and DB sizes, menu API/data prerequisites, invoice QR/fiscal-state coverage, staff onboarding data, and fiscal identity readiness. An empty Redis cache or session DB is valid when that database is idle.

After login, the main review URLs include `/dashboard`, `/pos`, `/cash-register`, `/bar`, `/products`, `/inventory`, `/purchasing`, `/finance`, `/reports`, `/invoices`, `/venue-setup`, `/staff`, and `/settings#fiscal-identity`.

