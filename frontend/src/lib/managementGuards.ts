export type LocationDisableState = {
  is_last_active: boolean;
  open_order_count: number;
  open_cash_session_count: number;
};

export function canDisableLocation(location: LocationDisableState): boolean {
  return !location.is_last_active
    && location.open_order_count === 0
    && location.open_cash_session_count === 0;
}

export function canDisableCategory(activeProductCount: number): boolean {
  return activeProductCount === 0;
}

export function canManageRolePermissions(
  rolePermissions: string[],
  assignablePermissions: string[],
): boolean {
  const assignable = new Set(assignablePermissions);

  return rolePermissions.every(permission => assignable.has(permission));
}


export function canDisableVenueArea(activeTableCount: number): boolean {
  return activeTableCount === 0;
}

export function canDisableVenueTable(openOrderCount: number): boolean {
  return openOrderCount === 0;
}

export function canDisableCashRegister(openSessionCount: number): boolean {
  return openSessionCount === 0;
}
