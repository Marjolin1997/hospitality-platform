# Platform Architecture

## Scope

Hospitality Platform is a multi-tenant SaaS and operational platform focused first on bars, cafés, coffee shops, lounges, and similar hospitality businesses. The architecture must support a small single-location café without making the product complicated, while remaining capable of supporting larger businesses with multiple locations, warehouses, registers, and teams.

## Tenant hierarchy

```text
Platform
└── Business (tenant)
    ├── Location / Branch
    │   ├── Floors / Areas
    │   ├── Tables
    │   ├── Registers
    │   ├── Bar / Kitchen stations
    │   └── Warehouses
    ├── Users
    ├── Roles & Permissions
    ├── Product catalog / Menu
    ├── Orders & Payments
    ├── Inventory & Purchasing
    ├── Finance
    └── Fiscalization configuration
```

A user may belong to one or more businesses. Membership, role and permission assignment is business-scoped. Location access can be further restricted within a business.

## Backend boundaries

Laravel is organized by domain rather than by a large collection of unrelated controllers and models.

- Identity — authentication, user profile, sessions
- Tenancy — businesses, locations, memberships
- Authorization — roles, permissions, policies
- Catalog — categories, products, modifiers, recipes, tax profiles
- Sales — tables, orders, order items, discounts, payments
- Fulfillment — bar/kitchen tickets and preparation status
- Inventory — warehouses, stock items, stock ledger, transfers, adjustments
- Purchasing — suppliers, purchase orders, goods receipts
- Finance — cash sessions, expenses, financial summaries
- Invoicing — invoice lifecycle and immutable invoice snapshots
- Fiscalization — provider/government adapter, submissions, retries, responses
- Reporting — read models and aggregates
- Audit — sensitive action history

Controllers remain thin. Business rules live in application/domain services and actions. External systems are accessed through explicit interfaces/adapters.

## Frontend applications

The React application has two interaction modes sharing the same design system and API client:

### Operations / POS

Touch-friendly and optimized for speed. Core flow:

```text
Select location/register
      ↓
Select table / takeaway
      ↓
Add menu items
      ↓
Send to Bar/Kitchen
      ↓
Prepare / Serve
      ↓
Payment
      ↓
Invoice / Fiscalization
      ↓
Close order
```

### Management

Desktop/tablet-oriented administration for dashboard, products, inventory, purchases, staff, finance, invoices, reports and settings.

## Critical data rules

### Money

Never use binary floating-point values for money. Backend monetary columns use fixed precision decimal values (or integer minor units where the domain permits). Frontend calculations use decimal-safe handling and backend-calculated authoritative totals.

### Orders

Order items keep sale-time snapshots of product name, SKU, price, VAT/tax information and relevant modifiers. Historical orders must not change when the current catalog changes.

### Inventory

Inventory is ledger-based. Every quantity change produces a stock movement with source, reason, quantity, actor and timestamp. Current stock is a derived/materialized balance, not the only source of truth.

### Financial and fiscal records

Successful fiscal records and finalized financial documents are immutable. Corrections use explicit reversal/correction/credit workflows rather than destructive edits.

### External operations

Payment and fiscalization submission commands use idempotency keys. Fiscalization is asynchronous where appropriate and keeps request/response/error history for support and audit.

## Multi-tenancy

Initial strategy: shared MySQL database with explicit `business_id` on tenant-owned aggregate roots and business-scoped relationships. A resolved Tenant Context is required for protected API routes. Query scopes, policies and service-layer assertions provide defense in depth.

A client-supplied `business_id` alone is never trusted as authorization.

## API

All application endpoints are versioned under `/api/v1`.

Standard concerns:
- authentication
- tenant context
- authorization policies
- validation via Form Requests / DTOs
- predictable JSON resources
- pagination/filtering/sorting conventions
- idempotency for critical writes
- audit metadata
- rate limiting
- consistent error envelopes

## Reliability

Redis is used for cache, queues and distributed coordination where required. Queue workers handle non-interactive jobs such as fiscal submission, notifications, report aggregation and integration retries. Laravel scheduler handles recurring maintenance and synchronization tasks.

An outbox-style boundary should be used for important integration events so a committed business transaction is not lost between the database and an external call.

## Security baseline

- secure session/token authentication
- tenant isolation
- granular authorization
- password hashing using Laravel-supported secure defaults
- secrets only through environment/secret management
- request validation
- rate limiting
- CSRF protection when cookie-based SPA authentication is used
- audit logs for security, fiscal and financial operations
- least-privilege database/container configuration
- no sensitive payloads in ordinary application logs

## Fiscalization boundary

Fiscalization rules and government API details must be implemented only from verified current Albanian official specifications. The core Sales/Invoicing domain produces a normalized fiscal document; an Albania-specific adapter maps that document to the required fiscal protocol. This prevents government integration details from contaminating the POS/order domain and makes the integration testable and replaceable.
