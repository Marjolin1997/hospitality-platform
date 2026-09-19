import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  AlertTriangle,
  History,
  LayoutGrid,
  Pencil,
  Plus,
  Search,
  Table2,
  WalletCards,
  X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { useAuth } from '../../features/auth/AuthProvider';
import { api } from '../../lib/api';
import {
  canDisableCashRegister,
  canDisableVenueArea,
  canDisableVenueTable,
} from '../../lib/managementGuards';

type VenueArea = {
  id: string;
  location_id: string;
  name: string;
  sort_order: number;
  is_active: boolean;
  table_count: number;
  active_table_count: number;
};

type VenueTable = {
  id: string;
  location_id: string;
  venue_area_id: string | null;
  name: string;
  capacity: number;
  is_active: boolean;
  area_name: string | null;
  area_is_active: boolean | null;
  open_order_count: number;
};

type VenueResponse = {
  areas: VenueArea[];
  tables: VenueTable[];
};

type CashRegister = {
  id: string;
  location_id: string;
  name: string;
  code: string;
  is_active: boolean;
  fiscal_tcr_code: string | null;
  open_session_count: number;
};

type AreaDraft = {
  id?: string;
  name: string;
  sort_order: string;
};

type TableDraft = {
  id?: string;
  venue_area_id: string;
  name: string;
  capacity: string;
};

type RegisterDraft = {
  id?: string;
  name: string;
  code: string;
};
type ConfigurationEvent = {
  id: string;
  action: string;
  previous_state: Record<string, unknown> | null;
  new_state: Record<string, unknown> | null;
  performed_at: string;
  performed_by_user_id: number;
  performed_by_name: string;
};

type HistoryTarget = {
  type: 'area' | 'table' | 'register';
  id: string;
  name: string;
};


function apiMessage(error: unknown): string {
  const response = (error as {
    response?: { data?: { errors?: Record<string, string[]>; message?: string } };
  })?.response;
  const errors = response?.data?.errors;

  if (errors) {
    const first = Object.values(errors).flat()[0];
    if (typeof first === 'string') return first;
  }

  return response?.data?.message ?? 'The request could not be completed.';
}

function Empty({ children }: { children: string }) {
  return <div className="management-empty">{children}</div>;
}

export function VenueSetupPage() {
  const { activeBusiness, activeLocation, can } = useAuth();
  const qc = useQueryClient();

  const canVenue = can('venue.manage');
  const canRegisters = can('cash_registers.manage');
  const [section, setSection] = useState<'venue' | 'registers'>(canVenue ? 'venue' : 'registers');

  const [tableSearch, setTableSearch] = useState('');
  const [tableStatus, setTableStatus] = useState('all');
  const [tableArea, setTableArea] = useState('all');

  const [registerSearch, setRegisterSearch] = useState('');
  const [registerStatus, setRegisterStatus] = useState('all');

  const [areaEditor, setAreaEditor] = useState<AreaDraft | null>(null);
  const [tableEditor, setTableEditor] = useState<TableDraft | null>(null);
  const [registerEditor, setRegisterEditor] = useState<RegisterDraft | null>(null);

  const [areaDisableTarget, setAreaDisableTarget] = useState<VenueArea | null>(null);
  const [tableDisableTarget, setTableDisableTarget] = useState<VenueTable | null>(null);
  const [registerDisableTarget, setRegisterDisableTarget] = useState<CashRegister | null>(null);
  const [historyTarget, setHistoryTarget] = useState<HistoryTarget | null>(null);

  useEffect(() => {
    if (section === 'venue' && !canVenue && canRegisters) setSection('registers');
    if (section === 'registers' && !canRegisters && canVenue) setSection('venue');
  }, [canRegisters, canVenue, section]);

  const venueQuery = useQuery({
    queryKey: ['venue-management', activeBusiness?.id, activeLocation?.id],
    enabled: Boolean(activeBusiness && activeLocation && canVenue),
    queryFn: () => api
      .get<{ data: VenueResponse }>('/management/venue', {
        params: { location_id: activeLocation!.id },
      })
      .then(response => response.data.data),
  });

  const configurationHistoryQuery = useQuery({
    queryKey: ['configuration-events', activeBusiness?.id, historyTarget?.type, historyTarget?.id],
    enabled: Boolean(activeBusiness && historyTarget),
    queryFn: () => {
      const path = historyTarget!.type === 'area'
        ? `/management/venue/areas/${historyTarget!.id}/events`
        : historyTarget!.type === 'table'
          ? `/management/venue/tables/${historyTarget!.id}/events`
          : `/management/cash-registers/${historyTarget!.id}/events`;

      return api.get<{ data: ConfigurationEvent[] }>(path).then(response => response.data.data);
    },
  });

  const registersQuery = useQuery({
    queryKey: ['register-management', activeBusiness?.id, activeLocation?.id],
    enabled: Boolean(activeBusiness && activeLocation && canRegisters),
    queryFn: () => api
      .get<{ data: CashRegister[] }>('/management/cash-registers', {
        params: { location_id: activeLocation!.id },
      })
      .then(response => response.data.data),
  });

  const invalidateVenue = async () => {
    await Promise.all([
      qc.invalidateQueries({ queryKey: ['venue-management', activeBusiness?.id, activeLocation?.id] }),
      qc.invalidateQueries({ queryKey: ['venue', activeLocation?.id] }),
    ]);
  };

  const invalidateRegisters = async () => {
    await Promise.all([
      qc.invalidateQueries({ queryKey: ['register-management', activeBusiness?.id, activeLocation?.id] }),
      qc.invalidateQueries({ queryKey: ['cash-registers', activeLocation?.id] }),
      qc.invalidateQueries({ queryKey: ['cash-session'] }),
      qc.invalidateQueries({ queryKey: ['fiscalization-setup', activeBusiness?.id] }),
      qc.invalidateQueries({ queryKey: ['fiscalization-profile', activeBusiness?.id] }),
    ]);
  };

  const saveArea = useMutation({
    mutationFn: (draft: AreaDraft) => api.post('/management/venue/areas', {
      id: draft.id,
      location_id: activeLocation!.id,
      name: draft.name.trim(),
      sort_order: Number(draft.sort_order),
    }),
    onSuccess: async () => {
      setAreaEditor(null);
      await invalidateVenue();
    },
  });

  const toggleArea = useMutation({
    mutationFn: (area: VenueArea) => api.patch(`/management/venue/areas/${area.id}/status`, {
      is_active: !area.is_active,
    }),
    onSuccess: async () => {
      setAreaDisableTarget(null);
      await invalidateVenue();
    },
  });

  const saveTable = useMutation({
    mutationFn: (draft: TableDraft) => api.post('/management/venue/tables', {
      id: draft.id,
      location_id: activeLocation!.id,
      venue_area_id: draft.venue_area_id,
      name: draft.name.trim(),
      capacity: Number(draft.capacity),
    }),
    onSuccess: async () => {
      setTableEditor(null);
      await invalidateVenue();
    },
  });

  const toggleTable = useMutation({
    mutationFn: (table: VenueTable) => api.patch(`/management/venue/tables/${table.id}/status`, {
      is_active: !table.is_active,
    }),
    onSuccess: async () => {
      setTableDisableTarget(null);
      await invalidateVenue();
    },
  });

  const saveRegister = useMutation({
    mutationFn: (draft: RegisterDraft) => api.post('/management/cash-registers', {
      id: draft.id,
      location_id: activeLocation!.id,
      name: draft.name.trim(),
      code: draft.code.trim().toUpperCase(),
    }),
    onSuccess: async () => {
      setRegisterEditor(null);
      await invalidateRegisters();
    },
  });

  const toggleRegister = useMutation({
    mutationFn: (register: CashRegister) => api.patch(`/management/cash-registers/${register.id}/status`, {
      is_active: !register.is_active,
    }),
    onSuccess: async () => {
      setRegisterDisableTarget(null);
      await invalidateRegisters();
    },
  });

  if (!canVenue && !canRegisters) {
    return (
      <div className="panel management-state error">
        <AlertTriangle size={20} />
        Your role does not have permission to manage venue layout or cash registers.
      </div>
    );
  }

  if (!activeLocation) {
    return (
      <div className="panel management-state error">
        <AlertTriangle size={20} />
        Select an active business location before configuring its service layout.
      </div>
    );
  }

  const venue = venueQuery.data ?? { areas: [], tables: [] };
  const registers = registersQuery.data ?? [];

  const activeAreas = venue.areas.filter(area => area.is_active);
  const activeTables = venue.tables.filter(table => table.is_active);
  const seats = activeTables.reduce((sum, table) => sum + table.capacity, 0);
  const activeRegisters = registers.filter(register => register.is_active);

  const tableTerm = tableSearch.trim().toLowerCase();
  const filteredTables = venue.tables.filter(table => (
    (tableStatus === 'all' || (tableStatus === 'active' ? table.is_active : !table.is_active))
    && (tableArea === 'all' || table.venue_area_id === tableArea)
    && (!tableTerm || [
      table.name,
      table.area_name ?? '',
      String(table.capacity),
    ].some(value => value.toLowerCase().includes(tableTerm)))
  ));

  const registerTerm = registerSearch.trim().toLowerCase();
  const filteredRegisters = registers.filter(register => (
    (registerStatus === 'all' || (registerStatus === 'active' ? register.is_active : !register.is_active))
    && (!registerTerm || [
      register.name,
      register.code,
      register.fiscal_tcr_code ?? '',
    ].some(value => value.toLowerCase().includes(registerTerm)))
  ));

  const availableAreasForTable = venue.areas.filter(area => area.is_active);
  const editingTable = tableEditor?.id
    ? venue.tables.find(table => table.id === tableEditor.id)
    : null;

  const tableAreaOptions = tableEditor?.venue_area_id
    && !availableAreasForTable.some(area => area.id === tableEditor.venue_area_id)
    ? [
        ...availableAreasForTable,
        ...venue.areas.filter(area => area.id === tableEditor.venue_area_id),
      ]
    : availableAreasForTable;

  const selectedEditorArea = tableEditor?.venue_area_id
    ? venue.areas.find(area => area.id === tableEditor.venue_area_id)
    : null;

  const inactiveAreaBlocksTableSave = Boolean(
    selectedEditorArea
    && !selectedEditorArea.is_active
    && (!editingTable
      || editingTable.venue_area_id !== selectedEditorArea.id
      || editingTable.is_active),
  );

  const openAreaEditor = (area?: VenueArea) => {
    saveArea.reset();
    setAreaEditor(area
      ? { id: area.id, name: area.name, sort_order: String(area.sort_order) }
      : { name: '', sort_order: String(((venue.areas[venue.areas.length - 1]?.sort_order) ?? -10) + 10) });
  };

  const openTableEditor = (table?: VenueTable) => {
    saveTable.reset();
    setTableEditor(table
      ? {
          id: table.id,
          venue_area_id: table.venue_area_id ?? '',
          name: table.name,
          capacity: String(table.capacity),
        }
      : {
          venue_area_id: activeAreas[0]?.id ?? '',
          name: '',
          capacity: '2',
        });
  };

  const openRegisterEditor = (register?: CashRegister) => {
    saveRegister.reset();
    setRegisterEditor(register
      ? { id: register.id, name: register.name, code: register.code }
      : { name: '', code: '' });
  };

  return (
    <main className="management-page venue-setup-page">
      <header className="page-heading">
        <div>
          <span className="eyebrow">VENUE CONFIGURATION</span>
          <h1>Venue Setup</h1>
          <p>
            Configure service areas, guest tables and cash registers for <strong>{activeLocation.name}</strong> without deleting operational history.
          </p>
        </div>
        <div className="management-heading-icon"><LayoutGrid size={20} /></div>
      </header>

      <div className="metric-grid management-metrics venue-setup-metrics">
        <div className="metric-card">
          <span>Active areas</span>
          <strong>{canVenue ? activeAreas.length : '—'}</strong>
          <small>{canVenue ? `${venue.areas.length} configured` : 'Venue permission required'}</small>
        </div>
        <div className="metric-card">
          <span>Active tables</span>
          <strong>{canVenue ? activeTables.length : '—'}</strong>
          <small>{canVenue ? `${seats} guest seats` : 'Venue permission required'}</small>
        </div>
        <div className="metric-card">
          <span>Active registers</span>
          <strong>{canRegisters ? activeRegisters.length : '—'}</strong>
          <small>{canRegisters ? `${registers.length} configured drawers` : 'Register permission required'}</small>
        </div>
        <div className="metric-card">
          <span>Live dependencies</span>
          <strong>
            {(canVenue ? venue.tables.reduce((sum, table) => sum + table.open_order_count, 0) : 0)
              + (canRegisters ? registers.reduce((sum, register) => sum + register.open_session_count, 0) : 0)}
          </strong>
          <small>Open table orders + cash shifts</small>
        </div>
      </div>

      {canVenue && canRegisters && (
        <div className="management-tabs" role="tablist" aria-label="Venue setup views">
          <button
            type="button"
            role="tab"
            aria-selected={section === 'venue'}
            className={section === 'venue' ? 'active' : ''}
            onClick={() => setSection('venue')}
          >
            <Table2 size={15} />
            Areas & Tables
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={section === 'registers'}
            className={section === 'registers' ? 'active' : ''}
            onClick={() => setSection('registers')}
          >
            <WalletCards size={15} />
            Cash Registers
          </button>
        </div>
      )}

      {section === 'venue' && canVenue && (
        <>
          <section className="panel management-panel venue-area-panel">
            <div className="panel-heading">
              <div>
                <h2>Service areas</h2>
                <p>Areas define the POS table layout. An area can be disabled only after all of its tables are inactive.</p>
              </div>
              <button type="button" className="primary-button" onClick={() => openAreaEditor()}>
                <Plus size={16} />
                Add area
              </button>
            </div>

            {venueQuery.isLoading ? (
              <div className="management-state">Loading venue areas…</div>
            ) : venueQuery.isError ? (
              <div className="management-state error">
                <AlertTriangle size={18} />
                <div>
                  <strong>Venue setup unavailable</strong>
                  <span>{apiMessage(venueQuery.error)}</span>
                </div>
                <button type="button" className="secondary-button" onClick={() => venueQuery.refetch()}>
                  Try again
                </button>
              </div>
            ) : venue.areas.length === 0 ? (
              <Empty>No service areas yet. Create the first room, terrace or service zone.</Empty>
            ) : (
              <div className="venue-area-grid">
                {venue.areas.map(area => (
                  <article className={`venue-area-card ${area.is_active ? '' : 'inactive'}`} key={area.id}>
                    <header>
                      <span className="venue-area-order">{area.sort_order}</span>
                      <div>
                        <strong>{area.name}</strong>
                        <small>{area.active_table_count} active · {area.table_count} total tables</small>
                      </div>
                      <span className={`status-badge ${area.is_active ? 'success' : 'muted'}`}>
                        {area.is_active ? 'Active' : 'Inactive'}
                      </span>
                    </header>
                    <footer>
                      <button type="button" className="secondary-button" onClick={() => openAreaEditor(area)}>
                        <Pencil size={14} />
                        Edit
                      </button>
                      <button
                        type="button"
                        className={area.is_active ? 'secondary-button subtle-danger' : 'secondary-button'}
                        disabled={toggleArea.isPending}
                        onClick={() => {
                          toggleArea.reset();
                          area.is_active ? setAreaDisableTarget(area) : toggleArea.mutate(area);
                        }}
                      >
                        {area.is_active ? 'Disable' : 'Enable'}
                      </button>
                    </footer>
                  </article>
                ))}
              </div>
            )}
          </section>

          <section className="panel management-panel">
            <div className="panel-heading catalog-toolbar">
              <div>
                <h2>Guest tables</h2>
                <p>Manage seating capacity and area assignment. Tables with live orders cannot be disabled.</p>
              </div>
              <div className="venue-table-actions">
                <label className="search-box compact-search">
                  <Search size={16} />
                  <input
                    aria-label="Search venue tables"
                    value={tableSearch}
                    onChange={event => setTableSearch(event.target.value)}
                    placeholder="Search table or area…"
                  />
                  {tableSearch && (
                    <button
                      type="button"
                      className="search-clear"
                      aria-label="Clear table search"
                      onClick={() => setTableSearch('')}
                    >
                      <X size={14} />
                    </button>
                  )}
                </label>
                <select aria-label="Filter table area" value={tableArea} onChange={event => setTableArea(event.target.value)}>
                  <option value="all">All areas</option>
                  {venue.areas.map(area => <option key={area.id} value={area.id}>{area.name}</option>)}
                </select>
                <select aria-label="Filter table status" value={tableStatus} onChange={event => setTableStatus(event.target.value)}>
                  <option value="all">All statuses</option>
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
                <button
                  type="button"
                  className="primary-button"
                  disabled={activeAreas.length === 0}
                  title={activeAreas.length === 0 ? 'Create or activate an area first.' : undefined}
                  onClick={() => openTableEditor()}
                >
                  <Plus size={16} />
                  Add table
                </button>
              </div>
            </div>

            {toggleTable.isError && !tableDisableTarget && (
              <p className="error-state">{apiMessage(toggleTable.error)}</p>
            )}

            {filteredTables.length === 0 ? (
              <Empty>{venue.tables.length ? 'No tables match the current filters.' : 'No tables configured yet.'}</Empty>
            ) : (
              <div className="data-table-wrap">
                <table className="data-table venue-table">
                  <thead>
                    <tr>
                      <th>Table</th>
                      <th>Area</th>
                      <th>Capacity</th>
                      <th>Live orders</th>
                      <th>Status</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredTables.map(table => (
                      <tr key={table.id}>
                        <td>
                          <div className="venue-table-identity">
                            <span><Table2 size={15} /></span>
                            <strong>{table.name}</strong>
                          </div>
                        </td>
                        <td>
                          <strong>{table.area_name ?? 'Unassigned'}</strong>
                          {table.area_is_active === false && <small className="cell-note warning-text">Area inactive</small>}
                        </td>
                        <td>{table.capacity} seats</td>
                        <td>
                          {table.open_order_count > 0
                            ? <span className="status-badge warning">{table.open_order_count} active</span>
                            : <span className="status-badge success">Clear</span>}
                        </td>
                        <td>
                          <span className={`status-badge ${table.is_active ? 'success' : 'muted'}`}>
                            {table.is_active ? 'Active' : 'Inactive'}
                          </span>
                        </td>
                        <td>
                          <div className="inline-actions">
                            <button type="button" className="secondary-button" onClick={() => openTableEditor(table)}>
                              <Pencil size={14} />
                              Edit
                            </button>
                            <button
                              type="button"
                              className={table.is_active ? 'secondary-button subtle-danger' : 'secondary-button'}
                              disabled={toggleTable.isPending}
                              onClick={() => {
                                toggleTable.reset();
                                table.is_active ? setTableDisableTarget(table) : toggleTable.mutate(table);
                              }}
                            >
                              {table.is_active ? 'Disable' : 'Enable'}
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </>
      )}

      {section === 'registers' && canRegisters && (
        <section className="panel management-panel">
          <div className="panel-heading catalog-toolbar">
            <div>
              <h2>Cash registers</h2>
              <p>Configure physical drawers for this location. Disabling is blocked while a cash shift remains open.</p>
            </div>
            <div className="venue-register-actions">
              <label className="search-box compact-search">
                <Search size={16} />
                <input
                  aria-label="Search cash registers"
                  value={registerSearch}
                  onChange={event => setRegisterSearch(event.target.value)}
                  placeholder="Search name, code or TCR…"
                />
                {registerSearch && (
                  <button
                    type="button"
                    className="search-clear"
                    aria-label="Clear register search"
                    onClick={() => setRegisterSearch('')}
                  >
                    <X size={14} />
                  </button>
                )}
              </label>
              <select
                aria-label="Filter cash register status"
                value={registerStatus}
                onChange={event => setRegisterStatus(event.target.value)}
              >
                <option value="all">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
              <button type="button" className="primary-button" onClick={() => openRegisterEditor()}>
                <Plus size={16} />
                Add register
              </button>
            </div>
          </div>

          <div className="permission-banner">
            <WalletCards size={18} />
            <div>
              <strong>Fiscal topology guard</strong>
              <span>Creating, enabling or disabling a register invalidates the last fiscal preflight so production readiness must be rechecked.</span>
            </div>
          </div>

          {registersQuery.isLoading ? (
            <div className="management-state">Loading cash registers…</div>
          ) : registersQuery.isError ? (
            <div className="management-state error">
              <AlertTriangle size={18} />
              <div>
                <strong>Cash registers unavailable</strong>
                <span>{apiMessage(registersQuery.error)}</span>
              </div>
              <button type="button" className="secondary-button" onClick={() => registersQuery.refetch()}>
                Try again
              </button>
            </div>
          ) : filteredRegisters.length === 0 ? (
            <Empty>{registers.length ? 'No registers match the current filters.' : 'No cash registers configured for this location.'}</Empty>
          ) : (
            <div className="data-table-wrap">
              <table className="data-table register-management-table">
                <thead>
                  <tr>
                    <th>Register</th>
                    <th>Code</th>
                    <th>DPT TCR</th>
                    <th>Open shift</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredRegisters.map(register => (
                    <tr key={register.id}>
                      <td>
                        <div className="venue-table-identity">
                          <span><WalletCards size={15} /></span>
                          <strong>{register.name}</strong>
                        </div>
                      </td>
                      <td><code>{register.code}</code></td>
                      <td>
                        {register.fiscal_tcr_code
                          ? <span className="status-badge success">Configured</span>
                          : <span className="status-badge warning">Missing</span>}
                      </td>
                      <td>
                        {register.open_session_count > 0
                          ? <span className="status-badge warning">Open</span>
                          : <span className="status-badge success">Clear</span>}
                      </td>
                      <td>
                        <span className={`status-badge ${register.is_active ? 'success' : 'muted'}`}>
                          {register.is_active ? 'Active' : 'Inactive'}
                        </span>
                      </td>
                      <td>
                        <div className="inline-actions">
                          <button type="button" className="secondary-button" onClick={() => openRegisterEditor(register)}>
                            <Pencil size={14} />
                            Edit
                          </button>
                          <button
                            type="button"
                            className={register.is_active ? 'secondary-button subtle-danger' : 'secondary-button'}
                            disabled={toggleRegister.isPending}
                            onClick={() => {
                              toggleRegister.reset();
                              register.is_active
                                ? setRegisterDisableTarget(register)
                                : toggleRegister.mutate(register);
                            }}
                          >
                            {register.is_active ? 'Disable' : 'Enable'}
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      )}

      {areaEditor && (
        <div className="modal-backdrop" role="presentation" onMouseDown={event => {
          if (event.target === event.currentTarget && !saveArea.isPending) setAreaEditor(null);
        }}>
          <form
            className="modal-card management-modal venue-config-modal"
            role="dialog"
            aria-modal="true"
            aria-label={areaEditor.id ? 'Edit venue area' : 'Create venue area'}
            onSubmit={event => {
              event.preventDefault();
              if (areaEditor.name.trim() && Number.isInteger(Number(areaEditor.sort_order))) {
                saveArea.mutate(areaEditor);
              }
            }}
          >
            <header>
              <div>
                <span className="eyebrow">SERVICE AREA</span>
                <h2>{areaEditor.id ? 'Edit area' : 'Add area'}</h2>
                <p>Use sort order to control the area sequence shown in the POS table selector.</p>
              </div>
              <button type="button" className="icon-button" aria-label="Close area editor" disabled={saveArea.isPending} onClick={() => setAreaEditor(null)}>
                <X size={18} />
              </button>
            </header>
            <div className="form-grid">
              <label>
                <span>Area name</span>
                <input autoFocus required maxLength={120} value={areaEditor.name} onChange={event => setAreaEditor({ ...areaEditor, name: event.target.value })} placeholder="Terrace" />
              </label>
              <label>
                <span>Sort order</span>
                <input required type="number" min="0" max="10000" step="1" value={areaEditor.sort_order} onChange={event => setAreaEditor({ ...areaEditor, sort_order: event.target.value })} />
              </label>
            </div>
            {saveArea.isError && <p className="error-state">{apiMessage(saveArea.error)}</p>}
            <footer className="modal-actions">
              <button type="button" className="secondary-button" disabled={saveArea.isPending} onClick={() => setAreaEditor(null)}>Cancel</button>
              <button className="primary-button" disabled={saveArea.isPending || !areaEditor.name.trim() || !Number.isInteger(Number(areaEditor.sort_order))}>
                {saveArea.isPending ? 'Saving area…' : areaEditor.id ? 'Save area' : 'Create area'}
              </button>
            </footer>
          </form>
        </div>
      )}

      {tableEditor && (
        <div className="modal-backdrop" role="presentation" onMouseDown={event => {
          if (event.target === event.currentTarget && !saveTable.isPending) setTableEditor(null);
        }}>
          <form
            className="modal-card management-modal venue-config-modal"
            role="dialog"
            aria-modal="true"
            aria-label={tableEditor.id ? 'Edit venue table' : 'Create venue table'}
            onSubmit={event => {
              event.preventDefault();
              if (tableEditor.name.trim() && tableEditor.venue_area_id && !inactiveAreaBlocksTableSave) {
                saveTable.mutate(tableEditor);
              }
            }}
          >
            <header>
              <div>
                <span className="eyebrow">GUEST TABLE</span>
                <h2>{tableEditor.id ? 'Edit table' : 'Add table'}</h2>
                <p>Table names are unique inside the location and capacity is used for service planning.</p>
              </div>
              <button type="button" className="icon-button" aria-label="Close table editor" disabled={saveTable.isPending} onClick={() => setTableEditor(null)}>
                <X size={18} />
              </button>
            </header>
            <div className="form-grid">
              <label>
                <span>Table name</span>
                <input autoFocus required maxLength={64} value={tableEditor.name} onChange={event => setTableEditor({ ...tableEditor, name: event.target.value })} placeholder="T1" />
              </label>
              <label>
                <span>Capacity</span>
                <input required type="number" min="1" max="100" step="1" value={tableEditor.capacity} onChange={event => setTableEditor({ ...tableEditor, capacity: event.target.value })} />
              </label>
              <label className="span-2">
                <span>Service area</span>
                <select value={tableEditor.venue_area_id} onChange={event => setTableEditor({ ...tableEditor, venue_area_id: event.target.value })}>
                  <option value="">Select area</option>
                  {tableAreaOptions.map(area => (
                    <option key={area.id} value={area.id} disabled={!area.is_active}>
                      {area.name}{area.is_active ? '' : ' · inactive'}
                    </option>
                  ))}
                </select>
                {selectedEditorArea && !selectedEditorArea.is_active && (
                  <small className={inactiveAreaBlocksTableSave ? 'field-hint error' : 'field-hint'}>
                    {inactiveAreaBlocksTableSave
                      ? 'An inactive area cannot receive or reactivate a table.'
                      : 'This table may be edited while it remains inactive in its current inactive area.'}
                  </small>
                )}
              </label>
            </div>
            {saveTable.isError && <p className="error-state">{apiMessage(saveTable.error)}</p>}
            <footer className="modal-actions">
              <button type="button" className="secondary-button" disabled={saveTable.isPending} onClick={() => setTableEditor(null)}>Cancel</button>
              <button
                className="primary-button"
                disabled={
                  saveTable.isPending
                  || !tableEditor.name.trim()
                  || !tableEditor.venue_area_id
                  || Number(tableEditor.capacity) < 1
                  || inactiveAreaBlocksTableSave
                }
              >
                {saveTable.isPending ? 'Saving table…' : tableEditor.id ? 'Save table' : 'Create table'}
              </button>
            </footer>
          </form>
        </div>
      )}

      {registerEditor && (
        <div className="modal-backdrop" role="presentation" onMouseDown={event => {
          if (event.target === event.currentTarget && !saveRegister.isPending) setRegisterEditor(null);
        }}>
          <form
            className="modal-card management-modal venue-config-modal"
            role="dialog"
            aria-modal="true"
            aria-label={registerEditor.id ? 'Edit cash register' : 'Create cash register'}
            onSubmit={event => {
              event.preventDefault();
              if (registerEditor.name.trim() && /^[A-Za-z0-9][A-Za-z0-9_-]*$/.test(registerEditor.code.trim())) {
                saveRegister.mutate(registerEditor);
              }
            }}
          >
            <header>
              <div>
                <span className="eyebrow">CASH REGISTER</span>
                <h2>{registerEditor.id ? 'Edit register' : 'Add register'}</h2>
                <p>The operational code is unique across the business. DPT TCR identity remains managed from Fiscal Identity settings.</p>
              </div>
              <button type="button" className="icon-button" aria-label="Close register editor" disabled={saveRegister.isPending} onClick={() => setRegisterEditor(null)}>
                <X size={18} />
              </button>
            </header>
            <div className="form-grid">
              <label>
                <span>Register name</span>
                <input autoFocus required maxLength={120} value={registerEditor.name} onChange={event => setRegisterEditor({ ...registerEditor, name: event.target.value })} placeholder="Main Till" />
              </label>
              <label>
                <span>Register code</span>
                <input required maxLength={32} pattern="[A-Za-z0-9][A-Za-z0-9_-]*" value={registerEditor.code} onChange={event => setRegisterEditor({ ...registerEditor, code: event.target.value.toUpperCase() })} placeholder="MAIN-01" />
                <small>Letters, numbers, hyphen and underscore only.</small>
              </label>
            </div>
            {saveRegister.isError && <p className="error-state">{apiMessage(saveRegister.error)}</p>}
            <footer className="modal-actions">
              <button type="button" className="secondary-button" disabled={saveRegister.isPending} onClick={() => setRegisterEditor(null)}>Cancel</button>
              <button
                className="primary-button"
                disabled={
                  saveRegister.isPending
                  || !registerEditor.name.trim()
                  || !/^[A-Za-z0-9][A-Za-z0-9_-]*$/.test(registerEditor.code.trim())
                }
              >
                {saveRegister.isPending ? 'Saving register…' : registerEditor.id ? 'Save register' : 'Create register'}
              </button>
            </footer>
          </form>
        </div>
      )}

      {areaDisableTarget && (
        <div className="modal-backdrop" role="presentation" onMouseDown={event => {
          if (event.target === event.currentTarget && !toggleArea.isPending) setAreaDisableTarget(null);
        }}>
          <div className="modal-card compact-confirmation" role="dialog" aria-modal="true" aria-label="Disable service area">
            <header>
              <div>
                <span className="eyebrow">AREA AVAILABILITY</span>
                <h2>Disable {areaDisableTarget.name}?</h2>
                <p>The area will disappear from operational POS topology. Historical table and order records remain intact.</p>
              </div>
              <button type="button" className="icon-button" aria-label="Close area confirmation" onClick={() => setAreaDisableTarget(null)}>
                <X size={18} />
              </button>
            </header>
            {!canDisableVenueArea(areaDisableTarget.active_table_count) && (
              <div className="permission-banner warning">
                <AlertTriangle size={18} />
                <div>
                  <strong>{areaDisableTarget.active_table_count} active table{areaDisableTarget.active_table_count === 1 ? '' : 's'} still depend on this area</strong>
                  <span>Disable or move those tables before disabling the area.</span>
                </div>
              </div>
            )}
            {toggleArea.isError && <p className="error-state">{apiMessage(toggleArea.error)}</p>}
            <footer className="modal-actions">
              <button type="button" className="secondary-button" disabled={toggleArea.isPending} onClick={() => setAreaDisableTarget(null)}>Keep active</button>
              <button
                type="button"
                className="danger-action"
                disabled={toggleArea.isPending || !canDisableVenueArea(areaDisableTarget.active_table_count)}
                onClick={() => toggleArea.mutate(areaDisableTarget)}
              >
                {toggleArea.isPending ? 'Disabling…' : 'Disable area'}
              </button>
            </footer>
          </div>
        </div>
      )}

      {tableDisableTarget && (
        <div className="modal-backdrop" role="presentation" onMouseDown={event => {
          if (event.target === event.currentTarget && !toggleTable.isPending) setTableDisableTarget(null);
        }}>
          <div className="modal-card compact-confirmation" role="dialog" aria-modal="true" aria-label="Disable venue table">
            <header>
              <div>
                <span className="eyebrow">TABLE AVAILABILITY</span>
                <h2>Disable {tableDisableTarget.name}?</h2>
                <p>The table will stop accepting new table orders. Historical orders keep their original table reference.</p>
              </div>
              <button type="button" className="icon-button" aria-label="Close table confirmation" onClick={() => setTableDisableTarget(null)}>
                <X size={18} />
              </button>
            </header>
            {!canDisableVenueTable(tableDisableTarget.open_order_count) && (
              <div className="permission-banner warning">
                <AlertTriangle size={18} />
                <div>
                  <strong>{tableDisableTarget.open_order_count} active order{tableDisableTarget.open_order_count === 1 ? '' : 's'} still use this table</strong>
                  <span>Close or move those orders before disabling the table.</span>
                </div>
              </div>
            )}
            {toggleTable.isError && <p className="error-state">{apiMessage(toggleTable.error)}</p>}
            <footer className="modal-actions">
              <button type="button" className="secondary-button" disabled={toggleTable.isPending} onClick={() => setTableDisableTarget(null)}>Keep active</button>
              <button
                type="button"
                className="danger-action"
                disabled={toggleTable.isPending || !canDisableVenueTable(tableDisableTarget.open_order_count)}
                onClick={() => toggleTable.mutate(tableDisableTarget)}
              >
                {toggleTable.isPending ? 'Disabling…' : 'Disable table'}
              </button>
            </footer>
          </div>
        </div>
      )}

      {registerDisableTarget && (
        <div className="modal-backdrop" role="presentation" onMouseDown={event => {
          if (event.target === event.currentTarget && !toggleRegister.isPending) setRegisterDisableTarget(null);
        }}>
          <div className="modal-card compact-confirmation" role="dialog" aria-modal="true" aria-label="Disable cash register">
            <header>
              <div>
                <span className="eyebrow">REGISTER AVAILABILITY</span>
                <h2>Disable {registerDisableTarget.name}?</h2>
                <p>The drawer will no longer be available for new shifts. Historical cash sessions remain untouched.</p>
              </div>
              <button type="button" className="icon-button" aria-label="Close register confirmation" onClick={() => setRegisterDisableTarget(null)}>
                <X size={18} />
              </button>
            </header>
            {!canDisableCashRegister(registerDisableTarget.open_session_count) && (
              <div className="permission-banner warning">
                <AlertTriangle size={18} />
                <div>
                  <strong>An open cash shift still uses this register</strong>
                  <span>Close and reconcile the shift before disabling the register.</span>
                </div>
              </div>
            )}
            {toggleRegister.isError && <p className="error-state">{apiMessage(toggleRegister.error)}</p>}
            <footer className="modal-actions">
              <button type="button" className="secondary-button" disabled={toggleRegister.isPending} onClick={() => setRegisterDisableTarget(null)}>Keep active</button>
              <button
                type="button"
                className="danger-action"
                disabled={toggleRegister.isPending || !canDisableCashRegister(registerDisableTarget.open_session_count)}
                onClick={() => toggleRegister.mutate(registerDisableTarget)}
              >
                {toggleRegister.isPending ? 'Disabling…' : 'Disable register'}
              </button>
            </footer>
          </div>
        </div>
      )}
    </main>
  );
}
