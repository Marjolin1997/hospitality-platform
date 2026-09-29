import { Command, Search, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ComponentType } from 'react';

export type WorkspaceCommandItem = {
  label: string;
  section: string;
  to: string;
  icon: ComponentType<{ size?: number }>;
  keywords?: string[];
};

type Props = {
  open: boolean;
  items: WorkspaceCommandItem[];
  activePath: string;
  onClose: () => void;
  onNavigate: (to: string) => void;
};

export function WorkspaceCommandPalette({
  open,
  items,
  activePath,
  onClose,
  onNavigate,
}: Props) {
  const [query, setQuery] = useState('');
  const [activeIndex, setActiveIndex] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);
  const returnFocusRef = useRef<HTMLElement | null>(null);

  const filtered = useMemo(() => {
    const term = query.trim().toLowerCase();
    if (!term) return items;

    return items.filter(item => [
      item.label,
      item.section,
      ...(item.keywords ?? []),
    ].some(value => value.toLowerCase().includes(term)));
  }, [items, query]);

  useEffect(() => {
    if (!open) return;

    returnFocusRef.current = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;
    setQuery('');
    setActiveIndex(0);
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    const focusTimer = window.setTimeout(() => inputRef.current?.focus(), 0);

    return () => {
      window.clearTimeout(focusTimer);
      document.body.style.overflow = previousOverflow;
      const previousFocus = returnFocusRef.current;
      window.setTimeout(() => {
        if (previousFocus?.isConnected) previousFocus.focus();
      }, 0);
    };
  }, [open]);

  useEffect(() => {
    if (activeIndex >= filtered.length) {
      setActiveIndex(Math.max(0, filtered.length - 1));
    }
  }, [activeIndex, filtered.length]);

  if (!open) return null;

  const choose = (to: string) => {
    onNavigate(to);
    onClose();
  };

  return (
    <div
      className="command-palette-backdrop"
      role="presentation"
      onMouseDown={event => {
        if (event.target === event.currentTarget) onClose();
      }}
    >
      <section
        className="command-palette"
        role="dialog"
        aria-modal="true"
        aria-label="Navigate workspace"
        onKeyDown={event => {
          if (event.key === 'Escape') {
            event.preventDefault();
            onClose();
            return;
          }

          if (event.key === 'ArrowDown') {
            event.preventDefault();
            if (filtered.length > 0) {
              setActiveIndex(index => (index + 1) % filtered.length);
            }
            return;
          }

          if (event.key === 'ArrowUp') {
            event.preventDefault();
            if (filtered.length > 0) {
              setActiveIndex(index => (index - 1 + filtered.length) % filtered.length);
            }
            return;
          }

          if (event.key === 'Enter' && filtered[activeIndex]) {
            event.preventDefault();
            choose(filtered[activeIndex].to);
          }
        }}
      >
        <header className="command-palette-search">
          <Search size={18} aria-hidden="true" />
          <input
            ref={inputRef}
            value={query}
            onChange={event => {
              setQuery(event.target.value);
              setActiveIndex(0);
            }}
            placeholder="Search modules, actions or workspace areas…"
            aria-label="Search workspace modules"
          />
          {query ? (
            <button
              type="button"
              className="icon-button command-clear"
              aria-label="Clear workspace search"
              onClick={() => {
                setQuery('');
                setActiveIndex(0);
                inputRef.current?.focus();
              }}
            >
              <X size={16} />
            </button>
          ) : (
            <span className="command-shortcut" aria-hidden="true">ESC</span>
          )}
        </header>

        <div className="command-palette-body">
          {filtered.length === 0 ? (
            <div className="command-empty">
              <Search size={20} />
              <strong>No matching module</strong>
              <span>Try a menu name such as POS, inventory, staff, reports or settings.</span>
            </div>
          ) : (
            filtered.map((item, index) => {
              const Icon = item.icon;
              const isCurrent = activePath === item.to;
              return (
                <button
                  type="button"
                  key={item.to}
                  className={`command-result ${index === activeIndex ? 'active' : ''}`}
                  onMouseEnter={() => setActiveIndex(index)}
                  onClick={() => choose(item.to)}
                >
                  <span className="command-result-icon"><Icon size={17} /></span>
                  <span className="command-result-copy">
                    <strong>{item.label}</strong>
                    <small>{item.section}{isCurrent ? ' · Current page' : ''}</small>
                  </span>
                  <span className="command-result-enter">{isCurrent ? 'Open' : 'Go'}</span>
                </button>
              );
            })
          )}
        </div>

        <footer className="command-palette-footer">
          <span><kbd>↑</kbd><kbd>↓</kbd> Navigate</span>
          <span><kbd>Enter</kbd> Open</span>
          <span><Command size={13} />K Search</span>
        </footer>
      </section>
    </div>
  );
}
