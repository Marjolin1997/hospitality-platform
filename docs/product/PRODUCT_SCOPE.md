# Product Scope — Bars & Cafés

## Product goal

Make everyday operation of a bar or café fast for staff and transparent for the owner. Routine POS actions should require very few interactions, while management, financial and inventory actions remain controlled and auditable.

## Business types — first-class

- Bar
- Café / coffee shop
- Lounge
- Pub
- Small restaurant / food-and-beverage venue

The first UX is optimized for bars and cafés. Restaurant-oriented capabilities are supported where they naturally overlap, without making restaurant complexity the default experience.

## Roles

Roles are templates over granular permissions; businesses may create custom roles.

### Owner / Business Admin
Full business configuration, staff, locations, finance, fiscal settings and reporting.

### Manager
Daily operational management, products, staff supervision, order overrides, inventory and permitted reports.

### Waiter
Tables, orders, serving workflow and permitted payment actions.

### Bartender / Bar Staff
Bar preparation queue, item preparation status, relevant stock visibility and permitted operational actions.

### Cashier
Payments, cash register/session, invoices and permitted corrections/refunds.

### Finance / Accountant
Invoices, revenue, expenses, fiscal records, exports and financial reports.

### Inventory / Warehouse
Receiving, transfers, counts, adjustments and stock reports.

## Permission examples

```text
orders.view
orders.create
orders.update
orders.send_to_station
orders.cancel
orders.apply_discount
orders.override_price
payments.collect
payments.refund
cash_sessions.open
cash_sessions.close
products.view
products.manage
inventory.view
inventory.receive
inventory.transfer
inventory.adjust
expenses.view
expenses.create
expenses.approve
invoices.view
invoices.issue
fiscalization.view
fiscalization.retry
reports.operational.view
reports.financial.view
users.view
users.manage
roles.manage
business.settings.manage
```

## MVP operational flow

### Opening
1. Staff member signs in.
2. Select/resolve business location.
3. Cashier opens register/cash session when required.
4. POS shows table/quick-sale workspace.

### Sale
1. Waiter selects table or quick/takeaway sale.
2. Products are added from touch-friendly categories/search/favorites.
3. Modifiers/notes are captured where required.
4. Items are routed to Bar/Kitchen stations.
5. Station staff accept/prepare/complete items.
6. Waiter sees preparation state and serves order.
7. Payment can be full or, later, split according to enabled business rules.
8. Invoice type is determined through explicit workflow/configuration.
9. Fiscal documents pass through the fiscalization boundary.
10. Order closes only under valid payment/document rules.

### Inventory effect
Products can optionally have recipes. A sold beverage/product can consume recipe ingredients from the configured warehouse according to the selected stock policy. All consumption creates traceable movements.

### Closing
Cashier closes the cash session with expected vs counted values and variance. Managers/owners can inspect daily sales, payment methods, voids, discounts, expenses and stock alerts.

## UX priorities

- Touch targets suitable for tablets/POS terminals
- Very fast product selection
- Minimal modal stacking
- Persistent order context
- Clear table and preparation states
- Permission-aware actions instead of confusing disabled functionality
- Strong confirmations for destructive/financial actions
- Useful empty/loading/error/offline states
- Keyboard support for management screens
- Accessible contrast/focus semantics
- Responsive layouts for desktop and tablet; essential operational views usable on mobile

## Modules

### Dashboard
Daily sales, open orders, average ticket, payment mix, cash state, low-stock alerts and operational exceptions.

### POS & Tables
Floors/areas, tables, quick sale, order cart, discounts, notes, item routing, payments, receipt/invoice completion.

### Bar / Kitchen Display
Station-specific ticket queue, elapsed time, priority/state, item-level preparation and completion.

### Menu
Categories, products, sizes/variants, modifiers, pricing, tax profile, station routing, availability and recipes.

### Inventory
Warehouses, ingredients/items, stock balance, movement ledger, receiving, transfer, adjustment, counts and low-stock thresholds.

### Purchasing
Suppliers, purchase documents/orders, goods receipt and cost history.

### Finance
Cash sessions, revenue views, expenses, payment reconciliation and controlled exports.

### Invoices & Fiscalization
Fiscal and non-fiscal document flows, statuses, errors/retries, immutable history and fiscal audit information.

### Staff & Access
Users, invitations, memberships, role templates, custom roles, permission matrix and location restrictions.

### Reports
Sales, products, categories, staff activity, payments, discounts/voids, inventory and financial reporting.

### Settings
Business profile, locations, taxes, currencies, invoice/fiscal configuration, stations, printers/integrations and notification preferences.

## Explicit non-goals for foundation

The initial foundation will not invent Albanian fiscal protocol fields, certificates, endpoints or declaration rules. Those are added only after official specification verification. Advanced features such as loyalty, reservations, delivery marketplaces and payroll should not distort the core architecture before POS, inventory, finance and fiscal flows are stable.
