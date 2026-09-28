import { useQuery } from '@tanstack/react-query';
import { AlertTriangle, BarChart3, Download, RefreshCw } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '../../features/auth/AuthProvider';
import { api } from '../../lib/api';

type ReportScope = {
  business_id: string;
  location_id: string;
  location_name: string;
  currency: string;
  timezone: string;
  from: string;
  to: string;
};

type OperationalReport = {
  scope: ReportScope;
  summary: {
    gross_sales: string;
    refunds: string;
    net_sales: string;
    orders_opened: number;
    paid_order_count: number;
    average_ticket: string;
    cancelled_orders: number;
    discounts: string;
    voided_items: number;
    voided_value: string;
  };
  payment_mix: Array<{
    method: string;
    gross_amount: string;
    refund_amount: string;
    net_amount: string;
  }>;
  orders_by_type: Array<{ type: string; order_count: number }>;
  product_mix: Array<{
    product_name: string;
    sku: string | null;
    quantity: string;
    gross_line_value: string;
  }>;
  staff_activity: Array<{
    user_id: number;
    name: string;
    orders_opened: number;
    cancelled_orders: number;
    current_order_value: string;
  }>;
  definitions: Record<string, string>;
};

type FinancialReport = {
  scope: ReportScope;
  summary: {
    gross_sales: string;
    refunds: string;
    net_sales: string;
    location_expenses: string;
    unallocated_business_expenses: string;
    net_after_location_expenses: string;
    invoice_count: number;
    invoice_total: string;
    credit_note_count: number;
    credit_note_total: string;
    goods_receipt_count: number;
    goods_received_cost: string;
  };
  expense_categories: Array<{ category: string; net_amount: string }>;
  cash_reconciliation: {
    closed_sessions: number;
    cash_over: string;
    cash_short: string;
    net_variance: string;
  };
  definitions: Record<string, string>;
};

type ReportTab = 'operational' | 'financial';

function apiMessage(error: unknown): string {
  const response = (error as {
    response?: { data?: { errors?: Record<string, string[]>; message?: string } };
  })?.response;
  const errors = response?.data?.errors;

  if (errors) {
    const first = Object.values(errors).flat()[0];
    if (typeof first === 'string') return first;
  }

  return response?.data?.message ?? 'The report could not be loaded.';
}

function dateInTimeZone(date: Date, timeZone: string): string {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(date);

  const value = Object.fromEntries(parts.map(part => [part.type, part.value]));
  return `${value.year}-${value.month}-${value.day}`;
}

function shiftIsoDate(isoDate: string, days: number): string {
  const [year, month, day] = isoDate.split('-').map(Number);
  const value = new Date(Date.UTC(year, month - 1, day));
  value.setUTCDate(value.getUTCDate() + days);
  return value.toISOString().slice(0, 10);
}

function defaultRange(timeZone: string): { from: string; to: string } {
  const today = dateInTimeZone(new Date(), timeZone);
  return {
    from: shiftIsoDate(today, -29),
    to: today,
  };
}

function rangeDays(from: string, to: string): number {
  const start = Date.parse(`${from}T00:00:00Z`);
  const end = Date.parse(`${to}T00:00:00Z`);
  if (!Number.isFinite(start) || !Number.isFinite(end)) return Number.NaN;
  return Math.round((end - start) / 86_400_000) + 1;
}

function money(value: string | number, currency: string): string {
  return new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency,
    maximumFractionDigits: 2,
  }).format(Number(value));
}

function number(value: string | number): string {
  return new Intl.NumberFormat(undefined, { maximumFractionDigits: 4 }).format(Number(value));
}

function safeCsvCell(value: unknown): string {
  let text = value === null || value === undefined ? '' : String(value);
  if (/^[=+\-@]/.test(text)) text = `'${text}`;
  return `"${text.replaceAll('"', '""')}"`;
}

function exportCsv(filename: string, sections: Array<{ title: string; rows: unknown[][] }>): void {
  const lines: string[] = [];

  sections.forEach((section, index) => {
    if (index > 0) lines.push('');
    lines.push(safeCsvCell(section.title));
    section.rows.forEach(row => lines.push(row.map(safeCsvCell).join(',')));
  });

  const blob = new Blob(['\uFEFF', lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

function Empty({ children }: { children: string }) {
  return <div className="management-empty">{children}</div>;
}

export function ReportsPage() {
  const { activeBusiness, activeLocation, can } = useAuth();
  const canOperational = can('reports.operational.view');
  const canFinancial = can('reports.financial.view');
  const timeZone = activeBusiness?.timezone ?? 'UTC';
  const initial = defaultRange(timeZone);

  const [tab, setTab] = useState<ReportTab>(canOperational ? 'operational' : 'financial');
  const [fromDraft, setFromDraft] = useState(initial.from);
  const [toDraft, setToDraft] = useState(initial.to);
  const [range, setRange] = useState(initial);

  useEffect(() => {
    if (tab === 'operational' && !canOperational && canFinancial) setTab('financial');
    if (tab === 'financial' && !canFinancial && canOperational) setTab('operational');
  }, [tab, canOperational, canFinancial]);

  const days = rangeDays(fromDraft, toDraft);
  const invalidRange = !Number.isFinite(days) || days < 1 || days > 367;

  const operationalQuery = useQuery({
    queryKey: ['reports-operational', activeBusiness?.id, activeLocation?.id, range.from, range.to],
    enabled: Boolean(activeBusiness && activeLocation && canOperational && tab === 'operational'),
    queryFn: () => api
      .get<{ data: OperationalReport }>('/reports/operational', {
        params: { location_id: activeLocation!.id, from: range.from, to: range.to },
      })
      .then(response => response.data.data),
  });

  const financialQuery = useQuery({
    queryKey: ['reports-financial', activeBusiness?.id, activeLocation?.id, range.from, range.to],
    enabled: Boolean(activeBusiness && activeLocation && canFinancial && tab === 'financial'),
    queryFn: () => api
      .get<{ data: FinancialReport }>('/reports/financial', {
        params: { location_id: activeLocation!.id, from: range.from, to: range.to },
      })
      .then(response => response.data.data),
  });

  const operational = operationalQuery.data;
  const financial = financialQuery.data;
  const currency = operational?.scope.currency ?? financial?.scope.currency ?? activeBusiness?.currency ?? 'EUR';

  const reportTitle = useMemo(
    () => `${activeLocation?.name ?? 'Location'} · ${range.from} to ${range.to}`,
    [activeLocation?.name, range.from, range.to],
  );

  if (!activeLocation) {
    return <div className="management-empty">Select an active location before opening reports.</div>;
  }

  if (!canOperational && !canFinancial) {
    return <div className="management-empty">Your role does not have access to operational or financial reports.</div>;
  }

  const applyPreset = (daysBack: number) => {
    const today = dateInTimeZone(new Date(), timeZone);
    const next = {
      from: shiftIsoDate(today, -(daysBack - 1)),
      to: today,
    };
    setFromDraft(next.from);
    setToDraft(next.to);
    if (next.from === range.from && next.to === range.to) {
      if (tab === 'operational' && canOperational) void operationalQuery.refetch();
      if (tab === 'financial' && canFinancial) void financialQuery.refetch();
    } else {
      setRange(next);
    }
  };

  const applyRange = () => {
    if (invalidRange) return;
    if (fromDraft === range.from && toDraft === range.to) {
      if (tab === 'operational' && canOperational) void operationalQuery.refetch();
      if (tab === 'financial' && canFinancial) void financialQuery.refetch();
      return;
    }
    setRange({ from: fromDraft, to: toDraft });
  };

  const exportOperational = () => {
    if (!operational) return;
    exportCsv(
      `operational-report-${operational.scope.location_name}-${operational.scope.from}-${operational.scope.to}.csv`,
      [
        {
          title: 'Operational summary',
          rows: [
            ['Metric', 'Value', 'Currency'],
            ['Gross sales', operational.summary.gross_sales, currency],
            ['Refunds', operational.summary.refunds, currency],
            ['Net sales', operational.summary.net_sales, currency],
            ['Orders opened', operational.summary.orders_opened, ''],
            ['Paid orders', operational.summary.paid_order_count, ''],
            ['Average ticket', operational.summary.average_ticket, currency],
            ['Cancelled orders', operational.summary.cancelled_orders, ''],
            ['Discounts applied', operational.summary.discounts, currency],
            ['Voided items', operational.summary.voided_items, ''],
            ['Voided item value', operational.summary.voided_value, currency],
          ],
        },
        {
          title: 'Payment mix',
          rows: [
            ['Method', 'Gross', 'Refunded', 'Net'],
            ...operational.payment_mix.map(row => [row.method, row.gross_amount, row.refund_amount, row.net_amount]),
          ],
        },
        {
          title: 'Product mix',
          rows: [
            ['Product', 'SKU', 'Quantity', 'Gross line value'],
            ...operational.product_mix.map(row => [row.product_name, row.sku ?? '', row.quantity, row.gross_line_value]),
          ],
        },
        {
          title: 'Staff activity',
          rows: [
            ['Staff', 'Orders opened', 'Cancelled orders', 'Current order value'],
            ...operational.staff_activity.map(row => [row.name, row.orders_opened, row.cancelled_orders, row.current_order_value]),
          ],
        },
      ],
    );
  };

  const exportFinancial = () => {
    if (!financial) return;
    exportCsv(
      `financial-report-${financial.scope.location_name}-${financial.scope.from}-${financial.scope.to}.csv`,
      [
        {
          title: 'Financial summary',
          rows: [
            ['Metric', 'Value', 'Currency'],
            ['Gross sales', financial.summary.gross_sales, currency],
            ['Refunds', financial.summary.refunds, currency],
            ['Net sales', financial.summary.net_sales, currency],
            ['Location expenses', financial.summary.location_expenses, currency],
            ['Unallocated business expenses', financial.summary.unallocated_business_expenses, currency],
            ['Net after location expenses', financial.summary.net_after_location_expenses, currency],
            ['Invoice count', financial.summary.invoice_count, ''],
            ['Invoice total', financial.summary.invoice_total, currency],
            ['Credit note count', financial.summary.credit_note_count, ''],
            ['Credit note total', financial.summary.credit_note_total, currency],
            ['Goods receipt count', financial.summary.goods_receipt_count, ''],
            ['Goods received cost', financial.summary.goods_received_cost, currency],
          ],
        },
        {
          title: 'Expense categories',
          rows: [
            ['Category', 'Net amount'],
            ...financial.expense_categories.map(row => [row.category, row.net_amount]),
          ],
        },
        {
          title: 'Cash reconciliation',
          rows: [
            ['Closed sessions', 'Cash over', 'Cash short', 'Net variance'],
            [
              financial.cash_reconciliation.closed_sessions,
              financial.cash_reconciliation.cash_over,
              financial.cash_reconciliation.cash_short,
              financial.cash_reconciliation.net_variance,
            ],
          ],
        },
      ],
    );
  };

  return <main className="management-page reports-page">
    <div className="page-heading">
      <div>
        <span className="eyebrow">DECISION SUPPORT</span>
        <h1>Reports</h1>
        <p>Transaction-grounded operational and financial reporting for {activeLocation.name}, with explicit metric definitions.</p>
      </div>
      <div className="page-heading-actions">
        {tab === 'operational' && operational && <button type="button" className="secondary-button" onClick={exportOperational}><Download size={16} /> Export CSV</button>}
        {tab === 'financial' && financial && <button type="button" className="secondary-button" onClick={exportFinancial}><Download size={16} /> Export CSV</button>}
      </div>
    </div>

    <section className="panel management-panel report-filter-panel">
      <div className="report-filter-row">
        <div className="report-date-fields">
          <label><span>From</span><input type="date" value={fromDraft} onChange={event => setFromDraft(event.target.value)} /></label>
          <label><span>To</span><input type="date" value={toDraft} onChange={event => setToDraft(event.target.value)} /></label>
        </div>
        <div className="report-presets" role="group" aria-label="Report date presets">
          <button type="button" className="secondary-button" onClick={() => applyPreset(7)}>7 days</button>
          <button type="button" className="secondary-button" onClick={() => applyPreset(30)}>30 days</button>
          <button type="button" className="secondary-button" onClick={() => applyPreset(90)}>90 days</button>
        </div>
        <button
          type="button"
          className="primary-button"
          disabled={invalidRange}
          onClick={applyRange}
        >
          <RefreshCw size={15} />
          Apply / refresh
        </button>
      </div>
      <div className="report-range-meta">
        <span>{reportTitle}</span>
        <small>Business timezone: {timeZone} · maximum range 367 calendar days</small>
      </div>
      {invalidRange && <p className="field-hint error">Choose a valid date range of no more than 367 calendar days.</p>}
    </section>

    <div className="management-tabs reports-tabs" role="tablist" aria-label="Report type">
      {canOperational && <button type="button" role="tab" aria-selected={tab === 'operational'} className={tab === 'operational' ? 'active' : ''} onClick={() => setTab('operational')}><BarChart3 size={15} /> Operational</button>}
      {canFinancial && <button type="button" role="tab" aria-selected={tab === 'financial'} className={tab === 'financial' ? 'active' : ''} onClick={() => setTab('financial')}><BarChart3 size={15} /> Financial</button>}
    </div>

    {tab === 'operational' && canOperational && (
      operationalQuery.isLoading ? <div className="management-state">Building operational report…</div> : operationalQuery.isError ? (
        <div className="management-state error"><AlertTriangle size={18} /><div><strong>Operational report unavailable</strong><span>{apiMessage(operationalQuery.error)}</span></div><button type="button" className="secondary-button" onClick={() => operationalQuery.refetch()}>Try again</button></div>
      ) : operational && <>
        <div className="metric-grid management-metrics report-primary-metrics">
          <div className="metric-card"><span>Gross sales</span><strong>{money(operational.summary.gross_sales, currency)}</strong><small>Completed payments</small></div>
          <div className="metric-card"><span>Refunds</span><strong>{money(operational.summary.refunds, currency)}</strong><small>Completed refund transactions</small></div>
          <div className="metric-card"><span>Net sales</span><strong>{money(operational.summary.net_sales, currency)}</strong><small>Gross payments less refunds</small></div>
          <div className="metric-card"><span>Average ticket</span><strong>{money(operational.summary.average_ticket, currency)}</strong><small>Gross payments · {operational.summary.paid_order_count} paid order{operational.summary.paid_order_count === 1 ? '' : 's'}</small></div>
        </div>

        <div className="metric-grid management-metrics report-secondary-metrics">
          <div className="metric-card"><span>Orders opened</span><strong>{operational.summary.orders_opened}</strong><small>By opened_at</small></div>
          <div className="metric-card"><span>Cancelled orders</span><strong>{operational.summary.cancelled_orders}</strong><small>Cancelled in period</small></div>
          <div className="metric-card"><span>Discounts</span><strong>{money(operational.summary.discounts, currency)}</strong><small>Applied in period</small></div>
          <div className="metric-card"><span>Voided items</span><strong>{operational.summary.voided_items}</strong><small>{money(operational.summary.voided_value, currency)} line value</small></div>
        </div>

        <div className="reports-grid">
          <section className="panel management-panel">
            <div className="panel-heading"><div><h2>Payment mix</h2><p>Gross collection, refunds and net transaction value by payment method.</p></div></div>
            {operational.payment_mix.length === 0 ? <Empty>No payment transactions in this range.</Empty> : <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Method</th><th>Gross</th><th>Refunded</th><th>Net</th></tr></thead><tbody>{operational.payment_mix.map(row => <tr key={row.method}><td><strong>{row.method.replaceAll('_', ' ')}</strong></td><td>{money(row.gross_amount, currency)}</td><td>{money(row.refund_amount, currency)}</td><td><strong>{money(row.net_amount, currency)}</strong></td></tr>)}</tbody></table></div>}
          </section>

          <section className="panel management-panel">
            <div className="panel-heading"><div><h2>Order types</h2><p>Orders grouped by the type captured when they were opened.</p></div></div>
            {operational.orders_by_type.length === 0 ? <Empty>No orders opened in this range.</Empty> : <div className="report-stat-list">{operational.orders_by_type.map(row => <div key={row.type}><span>{row.type.replaceAll('_', ' ')}</span><strong>{row.order_count}</strong></div>)}</div>}
          </section>
        </div>

        <section className="panel management-panel">
          <div className="panel-heading"><div><h2>Product mix</h2><p>Non-voided line quantities from paid/refunded/closed orders opened in the period. Gross line value is before order-level discounts and refunds.</p></div></div>
          {operational.product_mix.length === 0 ? <Empty>No completed product activity in this range.</Empty> : <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Product snapshot</th><th>SKU</th><th>Quantity</th><th>Gross line value</th></tr></thead><tbody>{operational.product_mix.map((row, index) => <tr key={`${row.product_name}-${row.sku ?? ''}-${index}`}><td><strong>{row.product_name}</strong></td><td>{row.sku ?? '—'}</td><td>{number(row.quantity)}</td><td>{money(row.gross_line_value, currency)}</td></tr>)}</tbody></table></div>}
        </section>

        <section className="panel management-panel">
          <div className="panel-heading"><div><h2>Staff order activity</h2><p>Descriptive activity by the user who opened each order; this is not a performance score.</p></div></div>
          {operational.staff_activity.length === 0 ? <Empty>No staff order activity in this range.</Empty> : <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Staff</th><th>Orders opened</th><th>Cancelled</th><th>Current non-cancelled order value</th></tr></thead><tbody>{operational.staff_activity.map(row => <tr key={row.user_id}><td><strong>{row.name}</strong></td><td>{row.orders_opened}</td><td>{row.cancelled_orders}</td><td>{money(row.current_order_value, currency)}</td></tr>)}</tbody></table></div>}
        </section>

        <section className="report-definition-panel">
          {Object.values(operational.definitions).map(definition => <p key={definition}>{definition}</p>)}
        </section>
      </>
    )}

    {tab === 'financial' && canFinancial && (
      financialQuery.isLoading ? <div className="management-state">Building financial report…</div> : financialQuery.isError ? (
        <div className="management-state error"><AlertTriangle size={18} /><div><strong>Financial report unavailable</strong><span>{apiMessage(financialQuery.error)}</span></div><button type="button" className="secondary-button" onClick={() => financialQuery.refetch()}>Try again</button></div>
      ) : financial && <>
        <div className="metric-grid management-metrics report-primary-metrics">
          <div className="metric-card"><span>Net sales</span><strong>{money(financial.summary.net_sales, currency)}</strong><small>Payments less refunds</small></div>
          <div className="metric-card"><span>Location expenses</span><strong>{money(financial.summary.location_expenses, currency)}</strong><small>Net posted/reversed expense activity</small></div>
          <div className="metric-card"><span>Net after expenses</span><strong>{money(financial.summary.net_after_location_expenses, currency)}</strong><small>Location result, not accounting profit</small></div>
          <div className="metric-card"><span>Unallocated expenses</span><strong>{money(financial.summary.unallocated_business_expenses, currency)}</strong><small>Business-wide · excluded above</small></div>
        </div>

        <div className="reports-grid">
          <section className="panel management-panel">
            <div className="panel-heading"><div><h2>Documents</h2><p>Issued invoice and correction values in the selected period.</p></div></div>
            <div className="report-stat-list">
              <div><span>Invoices</span><strong>{financial.summary.invoice_count} · {money(financial.summary.invoice_total, currency)}</strong></div>
              <div><span>Credit notes</span><strong>{financial.summary.credit_note_count} · {money(financial.summary.credit_note_total, currency)}</strong></div>
            </div>
          </section>

          <section className="panel management-panel">
            <div className="panel-heading"><div><h2>Procurement receipts</h2><p>Goods physically received; procurement cost is not automatically booked as an expense.</p></div></div>
            <div className="report-stat-list">
              <div><span>Goods receipts</span><strong>{financial.summary.goods_receipt_count}</strong></div>
              <div><span>Received cost</span><strong>{money(financial.summary.goods_received_cost, currency)}</strong></div>
            </div>
          </section>
        </div>

        <div className="reports-grid">
          <section className="panel management-panel">
            <div className="panel-heading"><div><h2>Expense categories</h2><p>Location-assigned expenses, net of reversal entries occurring in the period.</p></div></div>
            {financial.expense_categories.length === 0 ? <Empty>No location expenses in this range.</Empty> : <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Category</th><th>Net amount</th></tr></thead><tbody>{financial.expense_categories.map(row => <tr key={row.category}><td><strong>{row.category}</strong></td><td>{money(row.net_amount, currency)}</td></tr>)}</tbody></table></div>}
          </section>

          <section className="panel management-panel">
            <div className="panel-heading"><div><h2>Cash reconciliation</h2><p>Closed cash sessions whose closing timestamp falls in the selected period.</p></div></div>
            <div className="report-stat-list">
              <div><span>Closed sessions</span><strong>{financial.cash_reconciliation.closed_sessions}</strong></div>
              <div><span>Cash over</span><strong>{money(financial.cash_reconciliation.cash_over, currency)}</strong></div>
              <div><span>Cash short</span><strong>{money(financial.cash_reconciliation.cash_short, currency)}</strong></div>
              <div><span>Net variance</span><strong>{money(financial.cash_reconciliation.net_variance, currency)}</strong></div>
            </div>
          </section>
        </div>

        <section className="report-definition-panel">
          {Object.values(financial.definitions).map(definition => <p key={definition}>{definition}</p>)}
        </section>
      </>
    )}
  </main>;
}
