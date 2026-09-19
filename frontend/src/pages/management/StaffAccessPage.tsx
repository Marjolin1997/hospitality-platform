import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  AlertTriangle,
  Check,
  Copy,
  History,
  Mail,
  Pencil,
  Plus,
  RefreshCw,
  Search,
  ShieldCheck,
  Trash2,
  UserPlus,
  Users,
  X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { useAuth } from '../../features/auth/AuthProvider';
import { api } from '../../lib/api';
import { canManageRolePermissions } from '../../lib/managementGuards';

type Staff = {
  id: number;
  name: string;
  email: string;
  status: string;
  role_id: string | null;
  role_name: string | null;
};

type Role = { id: string; name: string; slug: string };

type StaffResponse = {
  staff: Staff[];
  roles: Role[];
  assignable_role_ids: string[];
};

type PermissionItem = {
  key: string;
  group: string;
  description: string | null;
};

type ManagedRole = {
  id: string;
  name: string;
  slug: string;
  is_system: boolean;
  member_count: number;
  permissions: PermissionItem[];
};

type RoleManagementResponse = {
  roles: ManagedRole[];
  permissions: PermissionItem[];
};

type RoleDraft = {
  id?: string;
  name: string;
  permissions: string[];
};

type StaffInvitation = {
  id: string;
  email: string;
  role_id: string | null;
  role_name: string;
  role_name_snapshot: string;
  role_is_current: boolean;
  status: 'pending' | 'accepted' | 'revoked' | 'expired';
  expires_at: string;
  expired_at: string | null;
  reissue_count: number;
  last_reissued_at: string | null;
  accepted_at: string | null;
  revoked_at: string | null;
  created_at: string;
  invited_by_name: string;
  accepted_by_name: string | null;
};

type InvitationDraft = {
  email: string;
  role_id: string;
  expires_in_days: number;
};

type InvitationEvent = {
  id: string;
  event: string;
  previous_status: string | null;
  new_status: string;
  metadata: Record<string, unknown> | null;
  occurred_at: string;
  actor_name: string | null;
};

type CreatedInvitation = {
  id: string;
  email: string;
  role_id: string;
  role_name: string;
  status: string;
  expires_at: string;
  invitation_url: string;
};

function apiMessage(error: unknown): string {
  const response = (error as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } })?.response;
  const errors = response?.data?.errors;

  if (errors) {
    const first = Object.values(errors).flat()[0];
    if (typeof first === 'string') return first;
  }

  return response?.data?.message ?? 'The request could not be completed.';
}

function Loading() {
  return <div className="panel management-state">Loading workspace data…</div>;
}

function ErrorState() {
  return (
    <div className="panel management-state error">
      <AlertTriangle size={20} />
      We could not load staff access. Check your access and try again.
    </div>
  );
}

function Empty({ children }: { children: string }) {
  return <div className="management-empty">{children}</div>;
}

function invitationStatusClass(status: StaffInvitation['status']) {
  if (status === 'accepted') return 'success';
  if (status === 'pending') return 'warning';
  if (status === 'revoked') return 'danger';
  return 'muted';
}

export function StaffAccessPage() {
  const { activeBusiness, can, refreshUser } = useAuth();
  const qc = useQueryClient();

  const [section, setSection] = useState<'team' | 'invitations' | 'roles'>('team');
  const [search, setSearch] = useState('');
  const [roleFilter, setRoleFilter] = useState('all');
  const [statusFilter, setStatusFilter] = useState('all');
  const [deactivating, setDeactivating] = useState<Staff | null>(null);

  const [roleEditor, setRoleEditor] = useState<RoleDraft | null>(null);
  const [deletingRole, setDeletingRole] = useState<ManagedRole | null>(null);

  const [inviteSearch, setInviteSearch] = useState('');
  const [inviteStatus, setInviteStatus] = useState('all');
  const [inviteEditor, setInviteEditor] = useState(false);
  const [inviteDraft, setInviteDraft] = useState<InvitationDraft>({
    email: '',
    role_id: '',
    expires_in_days: 7,
  });
  const [createdInvitation, setCreatedInvitation] = useState<CreatedInvitation | null>(null);
  const [invitationOutcome, setInvitationOutcome] = useState<'created' | 'reissued'>('created');
  const [inviteCopied, setInviteCopied] = useState(false);
  const [copyError, setCopyError] = useState('');
  const [revokingInvitation, setRevokingInvitation] = useState<StaffInvitation | null>(null);
  const [reissuingInvitation, setReissuingInvitation] = useState<StaffInvitation | null>(null);
  const [reissueDays, setReissueDays] = useState(7);
  const [historyInvitation, setHistoryInvitation] = useState<StaffInvitation | null>(null);

  useEffect(() => {
    if (section === 'invitations' && !can('users.manage')) setSection('team');
    if (section === 'roles' && !can('roles.manage')) setSection('team');
  }, [can, section]);

  const staffQuery = useQuery({
    queryKey: ['staff', activeBusiness?.id],
    enabled: Boolean(activeBusiness),
    queryFn: () => api.get<{ data: StaffResponse }>('/staff').then(response => response.data.data),
  });

  const rolesQuery = useQuery({
    queryKey: ['roles', activeBusiness?.id],
    enabled: Boolean(activeBusiness) && can('roles.manage'),
    queryFn: () => api.get<{ data: RoleManagementResponse }>('/roles').then(response => response.data.data),
  });

  const invitationsQuery = useQuery({
    queryKey: ['staff-invitations', activeBusiness?.id],
    enabled: Boolean(activeBusiness) && can('users.manage'),
    queryFn: () => api.get<{ data: StaffInvitation[] }>('/staff-invitations').then(response => response.data.data),
  });

  const invitationHistoryQuery = useQuery({
    queryKey: ['staff-invitation-events', activeBusiness?.id, historyInvitation?.id],
    enabled: Boolean(activeBusiness) && can('users.manage') && Boolean(historyInvitation),
    queryFn: () => api
      .get<{ data: InvitationEvent[] }>(`/staff-invitations/${historyInvitation!.id}/events`)
      .then(response => response.data.data),
  });

  const updateMembership = useMutation({
    mutationFn: ({
      member,
      role_id,
      status,
    }: {
      member: Staff;
      role_id: string;
      status: string;
    }) => api.patch(`/staff/${member.id}`, { role_id, status }),
    onSuccess: async () => {
      setDeactivating(null);
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['staff', activeBusiness?.id] }),
        qc.invalidateQueries({ queryKey: ['roles', activeBusiness?.id] }),
      ]);
      await refreshUser();
    },
  });

  const saveRole = useMutation({
    mutationFn: (draft: RoleDraft) => (
      draft.id
        ? api.put(`/roles/${draft.id}`, {
            name: draft.name.trim(),
            permissions: draft.permissions,
          })
        : api.post('/roles', {
            name: draft.name.trim(),
            permissions: draft.permissions,
          })
    ),
    onSuccess: async () => {
      setRoleEditor(null);
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['roles', activeBusiness?.id] }),
        qc.invalidateQueries({ queryKey: ['staff', activeBusiness?.id] }),
        qc.invalidateQueries({ queryKey: ['staff-invitations', activeBusiness?.id] }),
      ]);
      await refreshUser();
    },
  });

  const deleteRole = useMutation({
    mutationFn: (role: ManagedRole) => api.delete(`/roles/${role.id}`),
    onSuccess: async () => {
      setDeletingRole(null);
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['roles', activeBusiness?.id] }),
        qc.invalidateQueries({ queryKey: ['staff', activeBusiness?.id] }),
        qc.invalidateQueries({ queryKey: ['staff-invitations', activeBusiness?.id] }),
      ]);
    },
  });

  const createInvitation = useMutation({
    mutationFn: (draft: InvitationDraft) => api
      .post<{ data: CreatedInvitation }>('/staff-invitations', {
        email: draft.email.trim().toLowerCase(),
        role_id: draft.role_id,
        expires_in_days: draft.expires_in_days,
      })
      .then(response => response.data.data),
    onSuccess: async invitation => {
      setInvitationOutcome('created');
      setCreatedInvitation(invitation);
      setInviteCopied(false);
      setCopyError('');
      await qc.invalidateQueries({ queryKey: ['staff-invitations', activeBusiness?.id] });
    },
  });

  const revokeInvitation = useMutation({
    mutationFn: (invitation: StaffInvitation) => api.post(
      `/staff-invitations/${invitation.id}/revoke`,
    ),
    onSuccess: async () => {
      setRevokingInvitation(null);
      await qc.invalidateQueries({ queryKey: ['staff-invitations', activeBusiness?.id] });
    },
  });

  const reissueInvitation = useMutation({
    mutationFn: ({ invitation, expiresInDays }: { invitation: StaffInvitation; expiresInDays: number }) => api
      .post<{ data: CreatedInvitation }>(`/staff-invitations/${invitation.id}/reissue`, {
        expires_in_days: expiresInDays,
      })
      .then(response => response.data.data),
    onSuccess: async invitation => {
      setReissuingInvitation(null);
      setInvitationOutcome('reissued');
      setCreatedInvitation(invitation);
      setInviteCopied(false);
      setCopyError('');
      setInviteEditor(true);
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['staff-invitations', activeBusiness?.id] }),
        qc.invalidateQueries({ queryKey: ['staff-invitation-events', activeBusiness?.id, invitation.id] }),
      ]);
    },
  });

  if (staffQuery.isLoading) return <Loading />;
  if (staffQuery.isError) return <ErrorState />;

  const data = staffQuery.data;
  const staff = data.staff;
  const assignableRoleIds = new Set(data.assignable_role_ids);
  const assignableRoles = data.roles.filter(role => assignableRoleIds.has(role.id));

  const activeMembers = staff.filter(member => member.status === 'active').length;
  const ownerRole = data.roles.find(role => role.slug === 'owner');
  const activeOwners = ownerRole
    ? staff.filter(member => member.role_id === ownerRole.id && member.status === 'active').length
    : 0;

  const term = search.trim().toLowerCase();
  const filteredStaff = staff.filter(member => (
    (roleFilter === 'all' || (roleFilter === 'none' ? !member.role_id : member.role_id === roleFilter))
    && (statusFilter === 'all' || member.status === statusFilter)
    && (!term || [member.name, member.email, member.role_name ?? '']
      .some(value => value.toLowerCase().includes(term)))
  ));

  const managedRoles = rolesQuery.data?.roles ?? [];
  const customRoles = managedRoles.filter(role => !role.is_system);

  const permissionGroups = (rolesQuery.data?.permissions ?? [])
    .reduce<Record<string, PermissionItem[]>>((groups, permission) => {
      (groups[permission.group] ??= []).push(permission);
      return groups;
    }, {});

  const invitations = invitationsQuery.data ?? [];
  const pendingInvitations = invitations.filter(invitation => invitation.status === 'pending');
  const inviteTerm = inviteSearch.trim().toLowerCase();
  const filteredInvitations = invitations.filter(invitation => (
    (inviteStatus === 'all' || invitation.status === inviteStatus)
    && (!inviteTerm || [
      invitation.email,
      invitation.role_name,
      invitation.invited_by_name,
      invitation.accepted_by_name ?? '',
    ].some(value => value.toLowerCase().includes(inviteTerm)))
  ));

  const openRole = (role?: ManagedRole) => {
    saveRole.reset();
    setRoleEditor(role
      ? {
          id: role.id,
          name: role.name,
          permissions: role.permissions.map(permission => permission.key),
        }
      : { name: '', permissions: [] });
  };

  const togglePermission = (key: string) => {
    setRoleEditor(current => current
      ? {
          ...current,
          permissions: current.permissions.includes(key)
            ? current.permissions.filter(value => value !== key)
            : [...current.permissions, key],
        }
      : current);
  };

  const toggleGroup = (permissions: PermissionItem[]) => {
    setRoleEditor(current => {
      if (!current) return current;

      const keys = permissions.map(permission => permission.key);
      const selected = keys.every(key => current.permissions.includes(key));

      return {
        ...current,
        permissions: selected
          ? current.permissions.filter(key => !keys.includes(key))
          : Array.from(new Set([...current.permissions, ...keys])),
      };
    });
  };

  const openInvitation = () => {
    createInvitation.reset();
    setInvitationOutcome('created');
    setCreatedInvitation(null);
    setInviteCopied(false);
    setCopyError('');
    setInviteDraft({
      email: '',
      role_id: assignableRoles[0]?.id ?? '',
      expires_in_days: 7,
    });
    setInviteEditor(true);
  };

  const closeInvitation = () => {
    if (createInvitation.isPending) return;
    setInviteEditor(false);
    setCreatedInvitation(null);
    setInviteCopied(false);
    setCopyError('');
  };

  const copyInvitation = async () => {
    if (!createdInvitation) return;

    try {
      await navigator.clipboard.writeText(createdInvitation.invitation_url);
      setInviteCopied(true);
      setCopyError('');
    } catch {
      setInviteCopied(false);
      setCopyError('Clipboard access was blocked. Select the link and copy it manually.');
    }
  };

  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">ACCESS CONTROL</span>
          <h1>Staff & Roles</h1>
          <p>Tenant-scoped membership, secure onboarding, owner continuity and least-privilege role design.</p>
        </div>
        {can('users.manage') && (
          <button
            type="button"
            className="primary-button"
            disabled={assignableRoles.length === 0}
            onClick={openInvitation}
            title={assignableRoles.length === 0 ? 'Your role cannot delegate any available staff role.' : undefined}
          >
            <UserPlus size={16} />
            Invite staff
          </button>
        )}
      </div>

      <div className="metric-grid management-metrics staff-metrics">
        <div className="metric-card">
          <span>Team members</span>
          <strong>{staff.length}</strong>
          <small>People linked to this business</small>
        </div>
        <div className="metric-card">
          <span>Active access</span>
          <strong>{activeMembers}</strong>
          <small>{staff.length - activeMembers} inactive membership{staff.length - activeMembers === 1 ? '' : 's'}</small>
        </div>
        <div className="metric-card">
          <span>Pending invites</span>
          <strong>{can('users.manage') ? pendingInvitations.length : '—'}</strong>
          <small>{can('users.manage') ? 'Awaiting secure acceptance' : 'Requires staff management access'}</small>
        </div>
        <div className="metric-card">
          <span>Available roles</span>
          <strong>{rolesQuery.data?.roles.length ?? data.roles.length}</strong>
          <small>{can('roles.manage')
            ? `${customRoles.length} custom · ${activeOwners} active owner`
            : `${activeOwners} active owner${activeOwners === 1 ? '' : 's'}`}
          </small>
        </div>
      </div>

      {(can('users.manage') || can('roles.manage')) && (
        <div className="management-tabs" role="tablist" aria-label="Staff access views">
          <button
            type="button"
            role="tab"
            aria-selected={section === 'team'}
            className={section === 'team' ? 'active' : ''}
            onClick={() => setSection('team')}
          >
            <Users size={15} />
            Team access
          </button>
          {can('users.manage') && (
            <button
              type="button"
              role="tab"
              aria-selected={section === 'invitations'}
              className={section === 'invitations' ? 'active' : ''}
              onClick={() => setSection('invitations')}
            >
              <Mail size={15} />
              Invitations
              {pendingInvitations.length > 0 && <span className="tab-count">{pendingInvitations.length}</span>}
            </button>
          )}
          {can('roles.manage') && (
            <button
              type="button"
              role="tab"
              aria-selected={section === 'roles'}
              className={section === 'roles' ? 'active' : ''}
              onClick={() => setSection('roles')}
            >
              <ShieldCheck size={15} />
              Roles & permissions
            </button>
          )}
        </div>
      )}

      {section === 'team' && (
        <div className="panel management-panel">
          <div className="panel-heading catalog-toolbar">
            <div>
              <h2>Access matrix</h2>
              <p>
                {can('users.manage')
                  ? 'Role and status changes are applied immediately and recorded in the membership audit trail.'
                  : 'You have read-only access to staff roles and membership status.'}
              </p>
            </div>
            <div className="toolbar-actions staff-toolbar">
              <label className="search-box compact-search">
                <Search size={16} />
                <input
                  aria-label="Search staff"
                  value={search}
                  onChange={event => setSearch(event.target.value)}
                  placeholder="Search name, email or role…"
                />
                {search && (
                  <button
                    type="button"
                    className="search-clear"
                    aria-label="Clear staff search"
                    onClick={() => setSearch('')}
                  >
                    <X size={14} />
                  </button>
                )}
              </label>
              <select
                aria-label="Filter staff role"
                value={roleFilter}
                onChange={event => setRoleFilter(event.target.value)}
              >
                <option value="all">All roles</option>
                <option value="none">No role</option>
                {data.roles.map(role => <option key={role.id} value={role.id}>{role.name}</option>)}
              </select>
              <select
                aria-label="Filter access status"
                value={statusFilter}
                onChange={event => setStatusFilter(event.target.value)}
              >
                <option value="all">All access</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
              <span className="toolbar-result-count">{filteredStaff.length} of {staff.length}</span>
            </div>
          </div>

          {can('users.manage') && (
            <div className="owner-continuity-note">
              <strong>Owner continuity & delegation guard</strong>
              <span>
                The final active owner cannot be removed, and you cannot assign or modify roles above your own permission level.
              </span>
            </div>
          )}

          {updateMembership.isError && <p className="error-state">{apiMessage(updateMembership.error)}</p>}

          {filteredStaff.length === 0 ? (
            <Empty>{staff.length ? 'No staff members match the current search and filters.' : 'No staff memberships are available.'}</Empty>
          ) : (
            <div className="data-table-wrap">
              <table className="data-table staff-table">
                <thead>
                  <tr>
                    <th>Staff member</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Access status</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredStaff.map(member => {
                    const manageable = !member.role_id || assignableRoleIds.has(member.role_id);

                    return (
                      <tr key={member.id}>
                        <td>
                          <div className="staff-identity">
                            <span className="staff-avatar">
                              {member.name.split(' ').map(part => part[0]).slice(0, 2).join('').toUpperCase()}
                            </span>
                            <div>
                              <strong>{member.name}</strong>
                              <small>{member.role_name ?? 'No role assigned'}</small>
                            </div>
                          </div>
                        </td>
                        <td>{member.email}</td>
                        <td>
                          {can('users.manage') && manageable ? (
                            <select
                              aria-label={`Role for ${member.name}`}
                              className="table-select"
                              disabled={updateMembership.isPending}
                              value={member.role_id ?? ''}
                              onChange={event => {
                                if (event.target.value) {
                                  updateMembership.mutate({
                                    member,
                                    role_id: event.target.value,
                                    status: member.status,
                                  });
                                }
                              }}
                            >
                              {!member.role_id && <option value="">No role</option>}
                              {assignableRoles.map(role => (
                                <option key={role.id} value={role.id}>{role.name}</option>
                              ))}
                            </select>
                          ) : (
                            <div className="restricted-access-cell">
                              <span>{member.role_name ?? 'No role'}</span>
                              {can('users.manage') && !manageable && (
                                <small>Above your delegation level</small>
                              )}
                            </div>
                          )}
                        </td>
                        <td>
                          {can('users.manage') && manageable && member.role_id ? (
                            <select
                              aria-label={`Access status for ${member.name}`}
                              className="table-select status-select"
                              disabled={updateMembership.isPending}
                              value={member.status}
                              onChange={event => (
                                event.target.value === 'inactive'
                                  ? setDeactivating(member)
                                  : updateMembership.mutate({
                                      member,
                                      role_id: member.role_id!,
                                      status: 'active',
                                    })
                              )}
                            >
                              <option value="active">Active</option>
                              <option value="inactive">Inactive</option>
                            </select>
                          ) : (
                            <span className={`status-badge ${member.status === 'active' ? 'success' : 'muted'}`}>
                              {member.status}
                            </span>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {section === 'invitations' && can('users.manage') && (
        <div className="panel management-panel invitation-management-panel">
          <div className="panel-heading catalog-toolbar">
            <div>
              <h2>Staff invitations</h2>
              <p>
                Invite links are bearer credentials. The raw token is shown only once after creation and is never stored in the database.
              </p>
            </div>
            <div className="toolbar-actions invitation-toolbar">
              <label className="search-box compact-search">
                <Search size={16} />
                <input
                  aria-label="Search staff invitations"
                  value={inviteSearch}
                  onChange={event => setInviteSearch(event.target.value)}
                  placeholder="Search email, role or inviter…"
                />
                {inviteSearch && (
                  <button
                    type="button"
                    className="search-clear"
                    aria-label="Clear invitation search"
                    onClick={() => setInviteSearch('')}
                  >
                    <X size={14} />
                  </button>
                )}
              </label>
              <select
                aria-label="Filter invitation status"
                value={inviteStatus}
                onChange={event => setInviteStatus(event.target.value)}
              >
                <option value="all">All statuses</option>
                <option value="pending">Pending</option>
                <option value="accepted">Accepted</option>
                <option value="expired">Expired</option>
                <option value="revoked">Revoked</option>
              </select>
              <span className="toolbar-result-count">{filteredInvitations.length} of {invitations.length}</span>
            </div>
          </div>

          {invitationsQuery.isLoading ? (
            <div className="management-state">Loading invitations…</div>
          ) : invitationsQuery.isError ? (
            <div className="management-state error">
              <AlertTriangle size={18} />
              <div>
                <strong>Invitations unavailable</strong>
                <span>{apiMessage(invitationsQuery.error)}</span>
              </div>
              <button type="button" className="secondary-button" onClick={() => invitationsQuery.refetch()}>
                Try again
              </button>
            </div>
          ) : filteredInvitations.length === 0 ? (
            <Empty>
              {invitations.length
                ? 'No invitations match the current search and status.'
                : 'No invitations yet. Invite the first staff member securely.'}
            </Empty>
          ) : (
            <div className="data-table-wrap">
              <table className="data-table invitation-table">
                <thead>
                  <tr>
                    <th>Invitee</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Expires</th>
                    <th>Invited by</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredInvitations.map(invitation => (
                    <tr key={invitation.id}>
                      <td>
                        <strong>{invitation.email}</strong>
                        {invitation.accepted_by_name && (
                          <small className="cell-note">Accepted by {invitation.accepted_by_name}</small>
                        )}
                      </td>
                      <td>
                        <strong>{invitation.role_name}</strong>
                        {invitation.status === 'pending' && !invitation.role_is_current && (
                          <small className="cell-note warning-text">Role changed · reissue required</small>
                        )}
                      </td>
                      <td>
                        <span className={`status-badge ${invitationStatusClass(invitation.status)}`}>
                          {invitation.status}
                        </span>
                      </td>
                      <td>
                        <strong>{new Date(invitation.expires_at).toLocaleDateString()}</strong>
                        <small className="cell-note">
                          {invitation.status === 'pending'
                            ? new Date(invitation.expires_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
                            : invitation.accepted_at
                              ? `Accepted ${new Date(invitation.accepted_at).toLocaleDateString()}`
                              : invitation.revoked_at
                                ? `Revoked ${new Date(invitation.revoked_at).toLocaleDateString()}`
                                : 'No longer active'}
                        </small>
                      </td>
                      <td>
                        <strong>{invitation.invited_by_name}</strong>
                        {invitation.reissue_count > 0 && (
                          <small className="cell-note">
                            Reissued {invitation.reissue_count} time{invitation.reissue_count === 1 ? '' : 's'}
                          </small>
                        )}
                      </td>
                      <td>
                        <div className="inline-actions invitation-actions">
                          {(invitation.status === 'pending' || invitation.status === 'expired') && (
                            <button
                              type="button"
                              className="secondary-button"
                              disabled={!invitation.role_id}
                              title={!invitation.role_id ? 'The invited role no longer exists.' : 'Rotate the secure token and issue a new link'}
                              onClick={() => {
                                reissueInvitation.reset();
                                setReissueDays(7);
                                setReissuingInvitation(invitation);
                              }}
                            >
                              <RefreshCw size={14} />
                              Reissue
                            </button>
                          )}
                          <button
                            type="button"
                            className="secondary-button"
                            onClick={() => setHistoryInvitation(invitation)}
                          >
                            <History size={14} />
                            History
                          </button>
                          {invitation.status === 'pending' && (
                            <button
                              type="button"
                              className="secondary-button subtle-danger"
                              onClick={() => {
                                revokeInvitation.reset();
                                setRevokingInvitation(invitation);
                              }}
                            >
                              Revoke
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {section === 'roles' && can('roles.manage') && (
        <div className="panel management-panel role-management-panel">
          <div className="panel-heading">
            <div>
              <h2>Roles & permission matrix</h2>
              <p>System roles are protected templates. Create custom roles for business-specific least-privilege access.</p>
            </div>
            <button type="button" className="primary-button" onClick={() => openRole()}>
              <Plus size={16} />
              New custom role
            </button>
          </div>

          {rolesQuery.isLoading ? (
            <div className="management-state">Loading role definitions…</div>
          ) : rolesQuery.isError ? (
            <div className="management-state error">
              <AlertTriangle size={18} />
              <div>
                <strong>Role matrix unavailable</strong>
                <span>{apiMessage(rolesQuery.error)}</span>
              </div>
              <button type="button" className="secondary-button" onClick={() => rolesQuery.refetch()}>
                Try again
              </button>
            </div>
          ) : (
            <div className="role-grid">
              {managedRoles.map(role => {
                const manageable = canManageRolePermissions(
                  role.permissions.map(permission => permission.key),
                  (rolesQuery.data?.permissions ?? []).map(permission => permission.key),
                );

                return (
                  <article className="role-card" key={role.id}>
                    <header>
                      <div>
                        <strong>{role.name}</strong>
                        <span className={`status-badge ${role.is_system ? 'muted' : manageable ? 'success' : 'warning'}`}>
                          {role.is_system ? 'System' : manageable ? 'Custom' : 'Restricted'}
                        </span>
                      </div>
                      <small>{role.member_count} member{role.member_count === 1 ? '' : 's'} · {role.permissions.length} permissions</small>
                    </header>
                    <details>
                      <summary>View permissions</summary>
                      <div className="permission-chip-list">
                        {role.permissions.map(permission => <span key={permission.key}>{permission.key}</span>)}
                      </div>
                    </details>
                    <footer>
                      {role.is_system ? (
                        <span className="field-hint">Protected template</span>
                      ) : !manageable ? (
                        <span className="field-hint">Contains permissions above your access level</span>
                      ) : (
                        <div className="inline-actions">
                          <button type="button" className="secondary-button" onClick={() => openRole(role)}>
                            <Pencil size={14} />
                            Edit
                          </button>
                          <button
                            type="button"
                            className="secondary-button subtle-danger"
                            disabled={role.member_count > 0}
                            title={role.member_count > 0
                              ? 'Reassign all members before deleting this role'
                              : 'Delete custom role'}
                            onClick={() => setDeletingRole(role)}
                          >
                            <Trash2 size={14} />
                            Delete
                          </button>
                        </div>
                      )}
                    </footer>
                  </article>
                );
              })}
            </div>
          )}
        </div>
      )}

      {deactivating?.role_id && (
        <div
          className="modal-backdrop"
          role="presentation"
          onMouseDown={event => {
            if (event.target === event.currentTarget && !updateMembership.isPending) setDeactivating(null);
          }}
        >
          <div className="modal-card compact-confirmation" role="dialog" aria-modal="true" aria-label="Deactivate staff access">
            <header>
              <div>
                <span className="eyebrow">ACCESS CHANGE</span>
                <h2>Deactivate {deactivating.name}?</h2>
                <p>This removes active workspace access without deleting the user, membership record or audit history.</p>
              </div>
              <button
                type="button"
                className="icon-button"
                aria-label="Close staff deactivation"
                disabled={updateMembership.isPending}
                onClick={() => setDeactivating(null)}
              >
                <X size={18} />
              </button>
            </header>
            {updateMembership.isError && <p className="error-state">{apiMessage(updateMembership.error)}</p>}
            <footer className="modal-actions">
              <button type="button" className="secondary-button" disabled={updateMembership.isPending} onClick={() => setDeactivating(null)}>
                Keep active
              </button>
              <button
                type="button"
                className="danger-action"
                disabled={updateMembership.isPending}
                onClick={() => updateMembership.mutate({
                  member: deactivating,
                  role_id: deactivating.role_id!,
                  status: 'inactive',
                })}
              >
                {updateMembership.isPending ? 'Deactivating…' : 'Deactivate access'}
              </button>
            </footer>
          </div>
        </div>
      )}

      {inviteEditor && (
        <div
          className="modal-backdrop"
          role="presentation"
          onMouseDown={event => {
            if (event.target === event.currentTarget) closeInvitation();
          }}
        >
          <div className="modal-card management-modal invitation-editor-modal" role="dialog" aria-modal="true" aria-label="Invite staff member">
            <header>
              <div>
                <span className="eyebrow">SECURE STAFF ONBOARDING</span>
                <h2>{createdInvitation ? 'Invitation ready' : 'Invite staff member'}</h2>
                <p>
                  {createdInvitation
                    ? invitationOutcome === 'reissued'
                      ? 'The previous link is now invalid. Copy this replacement link now; the raw token will not be shown again.'
                      : 'Copy this link now. For security, the raw invitation token will not be shown again.'
                    : 'Choose only the access this person needs. The invitation expires automatically.'}
                </p>
              </div>
              <button
                type="button"
                className="icon-button"
                aria-label="Close invitation"
                disabled={createInvitation.isPending}
                onClick={closeInvitation}
              >
                <X size={18} />
              </button>
            </header>

            {createdInvitation ? (
              <div className="invitation-created">
                <div className="permission-banner">
                  <Check size={18} />
                  <div>
                    <strong>{invitationOutcome === 'reissued' ? 'New secure link issued for' : 'Invite created for'} {createdInvitation.email}</strong>
                    <span>{createdInvitation.role_name} · expires {new Date(createdInvitation.expires_at).toLocaleString()}</span>
                  </div>
                </div>
                <label>
                  <span>One-time invitation link</span>
                  <div className="invitation-link-field">
                    <input
                      readOnly
                      value={createdInvitation.invitation_url}
                      onFocus={event => event.currentTarget.select()}
                      aria-label="Invitation link"
                    />
                    <button type="button" className="secondary-button" onClick={copyInvitation}>
                      {inviteCopied ? <Check size={15} /> : <Copy size={15} />}
                      {inviteCopied ? 'Copied' : 'Copy link'}
                    </button>
                  </div>
                  <small>Share this only with the intended staff member. Anyone holding the token can open the onboarding screen.</small>
                </label>
                {copyError && <p className="field-hint error">{copyError}</p>}
              </div>
            ) : (
              <form
                className="invitation-form"
                onSubmit={event => {
                  event.preventDefault();
                  if (inviteDraft.email.trim() && inviteDraft.role_id) {
                    createInvitation.mutate(inviteDraft);
                  }
                }}
              >
                <div className="form-grid">
                  <label className="span-2">
                    <span>Staff email</span>
                    <input
                      type="email"
                      inputMode="email"
                      autoComplete="email"
                      autoFocus
                      required
                      maxLength={255}
                      value={inviteDraft.email}
                      onChange={event => setInviteDraft({ ...inviteDraft, email: event.target.value })}
                      placeholder="staff@business.com"
                    />
                  </label>
                  <label>
                    <span>Role</span>
                    <select
                      required
                      value={inviteDraft.role_id}
                      onChange={event => setInviteDraft({ ...inviteDraft, role_id: event.target.value })}
                    >
                      {assignableRoles.map(role => <option key={role.id} value={role.id}>{role.name}</option>)}
                    </select>
                    <small>Only roles at or below your own permission level are available.</small>
                  </label>
                  <label>
                    <span>Expires in</span>
                    <select
                      value={inviteDraft.expires_in_days}
                      onChange={event => setInviteDraft({
                        ...inviteDraft,
                        expires_in_days: Number(event.target.value),
                      })}
                    >
                      <option value={1}>1 day</option>
                      <option value={3}>3 days</option>
                      <option value={7}>7 days</option>
                      <option value={14}>14 days</option>
                      <option value={30}>30 days</option>
                    </select>
                  </label>
                </div>

                <div className="invitation-security-note">
                  <ShieldCheck size={17} />
                  <div>
                    <strong>Security controls</strong>
                    <span>Token is hashed at rest, acceptance is one-time, role drift invalidates the invite and revoked/expired links cannot be reused.</span>
                  </div>
                </div>

                {createInvitation.isError && <p className="error-state">{apiMessage(createInvitation.error)}</p>}

                <footer className="modal-actions">
                  <button type="button" className="secondary-button" disabled={createInvitation.isPending} onClick={closeInvitation}>
                    Cancel
                  </button>
                  <button
                    className="primary-button"
                    disabled={createInvitation.isPending || !inviteDraft.email.trim() || !inviteDraft.role_id}
                  >
                    {createInvitation.isPending ? 'Creating invite…' : 'Create secure invite'}
                  </button>
                </footer>
              </form>
            )}

            {createdInvitation && (
              <footer className="modal-actions">
                <button type="button" className="primary-button" onClick={closeInvitation}>
                  Done
                </button>
              </footer>
            )}
          </div>
        </div>
      )}

      {revokingInvitation && (
        <div
          className="modal-backdrop"
          role="presentation"
          onMouseDown={event => {
            if (event.target === event.currentTarget && !revokeInvitation.isPending) {
              setRevokingInvitation(null);
            }
          }}
        >
          <div className="modal-card compact-confirmation" role="dialog" aria-modal="true" aria-label="Revoke staff invitation">
            <header>
              <div>
                <span className="eyebrow">REVOKE INVITATION</span>
                <h2>Revoke invite for {revokingInvitation.email}?</h2>
                <p>The existing link will stop working immediately. You can issue a fresh invitation later.</p>
              </div>
              <button
                type="button"
                className="icon-button"
                aria-label="Close invitation revocation"
                disabled={revokeInvitation.isPending}
                onClick={() => setRevokingInvitation(null)}
              >
                <X size={18} />
              </button>
            </header>
            {revokeInvitation.isError && <p className="error-state">{apiMessage(revokeInvitation.error)}</p>}
            <footer className="modal-actions">
              <button type="button" className="secondary-button" disabled={revokeInvitation.isPending} onClick={() => setRevokingInvitation(null)}>
                Keep invitation
              </button>
              <button
                type="button"
                className="danger-action"
                disabled={revokeInvitation.isPending}
                onClick={() => revokeInvitation.mutate(revokingInvitation)}
              >
                {revokeInvitation.isPending ? 'Revoking…' : 'Revoke invitation'}
              </button>
            </footer>
          </div>
        </div>
      )}

      {roleEditor && (
        <div
          className="modal-backdrop"
          role="presentation"
          onMouseDown={event => {
            if (event.target === event.currentTarget && !saveRole.isPending) setRoleEditor(null);
          }}
        >
          <form
            className="modal-card management-modal role-editor-modal"
            role="dialog"
            aria-modal="true"
            aria-label={roleEditor.id ? 'Edit custom role' : 'Create custom role'}
            onSubmit={event => {
              event.preventDefault();
              if (roleEditor.name.trim() && roleEditor.permissions.length > 0) saveRole.mutate(roleEditor);
            }}
          >
            <header>
              <div>
                <span className="eyebrow">CUSTOM ROLE</span>
                <h2>{roleEditor.id ? 'Edit role' : 'Create role'}</h2>
                <p>Grant only the capabilities this role needs. Permission changes apply to every member assigned to the role and invalidate pending invitations that used a different permission snapshot.</p>
              </div>
              <button
                type="button"
                className="icon-button"
                aria-label="Close role editor"
                disabled={saveRole.isPending}
                onClick={() => setRoleEditor(null)}
              >
                <X size={18} />
              </button>
            </header>

            <label>
              <span>Role name</span>
              <input
                autoFocus
                required
                maxLength={120}
                value={roleEditor.name}
                onChange={event => setRoleEditor({ ...roleEditor, name: event.target.value })}
                placeholder="e.g. Floor Supervisor"
              />
            </label>

            <div className="role-editor-summary">
              <span>Selected permissions</span>
              <strong>{roleEditor.permissions.length}</strong>
            </div>

            <div className="permission-group-grid">
              {Object.entries(permissionGroups).map(([group, permissions]) => {
                const allSelected = permissions.every(permission => roleEditor.permissions.includes(permission.key));

                return (
                  <section className="permission-group-card" key={group}>
                    <header>
                      <div>
                        <strong>{group}</strong>
                        <small>{permissions.length} permission{permissions.length === 1 ? '' : 's'}</small>
                      </div>
                      <button type="button" className="text-button" onClick={() => toggleGroup(permissions)}>
                        {allSelected ? 'Clear group' : 'Select group'}
                      </button>
                    </header>
                    {permissions.map(permission => (
                      <label className="permission-option" key={permission.key}>
                        <input
                          type="checkbox"
                          checked={roleEditor.permissions.includes(permission.key)}
                          onChange={() => togglePermission(permission.key)}
                        />
                        <span>
                          <strong>{permission.key}</strong>
                          <small>{permission.description ?? 'Permission capability'}</small>
                        </span>
                      </label>
                    ))}
                  </section>
                );
              })}
            </div>

            {saveRole.isError && <p className="error-state">{apiMessage(saveRole.error)}</p>}

            <footer className="modal-actions">
              <button type="button" className="secondary-button" disabled={saveRole.isPending} onClick={() => setRoleEditor(null)}>
                Cancel
              </button>
              <button
                className="primary-button"
                disabled={saveRole.isPending || !roleEditor.name.trim() || roleEditor.permissions.length === 0}
              >
                {saveRole.isPending ? 'Saving role…' : roleEditor.id ? 'Save role changes' : 'Create custom role'}
              </button>
            </footer>
          </form>
        </div>
      )}

      {deletingRole && (
        <div
          className="modal-backdrop"
          role="presentation"
          onMouseDown={event => {
            if (event.target === event.currentTarget && !deleteRole.isPending) setDeletingRole(null);
          }}
        >
          <div className="modal-card compact-confirmation" role="dialog" aria-modal="true" aria-label="Delete custom role">
            <header>
              <div>
                <span className="eyebrow">DELETE CUSTOM ROLE</span>
                <h2>Delete {deletingRole.name}?</h2>
                <p>The role definition will be removed permanently. Audit history remains intact, and live staff invitations must be revoked or expired first.</p>
              </div>
              <button
                type="button"
                className="icon-button"
                aria-label="Close role deletion"
                disabled={deleteRole.isPending}
                onClick={() => setDeletingRole(null)}
              >
                <X size={18} />
              </button>
            </header>
            {deleteRole.isError && <p className="error-state">{apiMessage(deleteRole.error)}</p>}
            <footer className="modal-actions">
              <button type="button" className="secondary-button" disabled={deleteRole.isPending} onClick={() => setDeletingRole(null)}>
                Keep role
              </button>
              <button
                type="button"
                className="danger-action"
                disabled={deleteRole.isPending || deletingRole.member_count > 0}
                onClick={() => deleteRole.mutate(deletingRole)}
              >
                {deleteRole.isPending ? 'Deleting…' : 'Delete custom role'}
              </button>
            </footer>
          </div>
        </div>
      )}
    </>
  );
}
