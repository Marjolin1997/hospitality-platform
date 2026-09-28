export const moduleAccess = {
  dashboard: [],
  pos: ['orders.view', 'orders.create'],
  cashRegister: ['cash_sessions.view', 'cash_sessions.open', 'cash_sessions.close', 'cash_movements.create', 'payments.collect'],
  barQueue: ['orders.view'],
  products: ['products.view', 'products.manage', 'stations.view', 'stations.manage'],
  inventory: ['inventory.view', 'inventory.receive', 'inventory.transfer', 'inventory.adjust'],
  purchasing: ['purchasing.view', 'purchasing.manage', 'inventory.receive'],
  finance: ['finance.view', 'expenses.view', 'expenses.create', 'expenses.approve'],
  reports: ['reports.operational.view', 'reports.financial.view'],
  invoices: ['invoices.view', 'invoices.issue'],
  venueSetup: ['venue.manage', 'cash_registers.manage'],
  staff: ['users.view', 'users.manage', 'roles.manage'],
  settings: ['business.settings.manage', 'fiscalization.view', 'fiscalization.manage', 'fiscalization.activate_production'],
} as const;

export type ModuleAccessKey = keyof typeof moduleAccess;
