import { describe, expect, it } from 'vitest';
import {
  canDisableCategory,
  canDisableLocation,
  canManageRolePermissions,
} from './managementGuards';

describe('management guards', () => {
  it('blocks location disable while it is the final branch or has live operations', () => {
    expect(canDisableLocation({
      is_last_active: true,
      open_order_count: 0,
      open_cash_session_count: 0,
    })).toBe(false);

    expect(canDisableLocation({
      is_last_active: false,
      open_order_count: 1,
      open_cash_session_count: 0,
    })).toBe(false);

    expect(canDisableLocation({
      is_last_active: false,
      open_order_count: 0,
      open_cash_session_count: 1,
    })).toBe(false);

    expect(canDisableLocation({
      is_last_active: false,
      open_order_count: 0,
      open_cash_session_count: 0,
    })).toBe(true);
  });

  it('blocks category disable while active products depend on it', () => {
    expect(canDisableCategory(2)).toBe(false);
    expect(canDisableCategory(0)).toBe(true);
  });

  it('prevents role management above the actor delegable permission set', () => {
    expect(canManageRolePermissions(
      ['orders.view', 'orders.update'],
      ['orders.view', 'orders.update', 'roles.manage'],
    )).toBe(true);

    expect(canManageRolePermissions(
      ['orders.view', 'fiscalization.manage'],
      ['orders.view', 'roles.manage'],
    )).toBe(false);
  });
});
