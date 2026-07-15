import { useState } from 'react';
import { NavLink, Outlet } from 'react-router-dom';
import { useAuthStore } from '../../stores/authStore';
import { useTerminalStore } from '../../stores/terminalStore';

interface NavItem {
  to: string;
  label: string;
  icon: string;
  permission?: string;
}

const NAV_ITEMS: NavItem[] = [
  { to: '/dashboard', label: 'Dashboard', icon: '▦', permission: 'pos.reports-view' },
  { to: '/checkout', label: 'POS Checkout', icon: '▢' },
  { to: '/sales-history', label: 'Sales History', icon: '☷' },
  { to: '/products', label: 'Products', icon: '▣' },
  { to: '/customers', label: 'Customers', icon: '▤' },
  { to: '/reports', label: 'Reports', icon: '■', permission: 'pos.reports-view' },
  { to: '/usin-settings', label: 'USIN Counters', icon: '#', permission: 'pos.terminal-manage' },
];

/** Top nav + left sidebar shell wrapping every authenticated route. Mounted
 *  inside RequireAuth in App.tsx; Login stays outside it entirely. */
export default function AppShell() {
  const session = useAuthStore((s) => s.session);
  const logout = useAuthStore((s) => s.logout);
  const { terminalId } = useTerminalStore();
  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);

  const permissions = session?.user.permissions ?? [];
  const items = NAV_ITEMS.filter((item) => !item.permission || permissions.includes(item.permission));

  return (
    <div className="flex h-full flex-col">
      <header className="flex h-14 shrink-0 items-center justify-between border-b border-border bg-surface px-4">
        <div className="flex items-center gap-3">
          <button
            onClick={() => setMobileOpen((v) => !v)}
            className="rounded p-1.5 text-ink-muted hover:bg-surface-hover md:hidden"
            aria-label="Toggle menu"
          >
            ☰
          </button>
          <button
            onClick={() => setCollapsed((v) => !v)}
            className="hidden rounded p-1.5 text-ink-muted hover:bg-surface-hover md:block"
            aria-label="Collapse sidebar"
          >
            ☰
          </button>
          <span className="text-sm font-semibold text-primary-700">CHANGAN MULTAN MOTORS</span>
          {terminalId && <span className="hidden text-xs text-ink-faint sm:inline">Terminal #{terminalId}</span>}
        </div>
        <div className="flex items-center gap-3">
          <span className="hidden text-sm text-ink-muted sm:inline">{session?.user.name}</span>
          <button onClick={logout} className="text-sm font-medium text-ink-muted hover:text-ink">
            Sign out
          </button>
        </div>
      </header>

      <div className="flex flex-1 overflow-hidden">
        {mobileOpen && (
          <div className="fixed inset-0 z-40 bg-ink/40 md:hidden" onClick={() => setMobileOpen(false)} />
        )}

        <nav
          className={`z-50 shrink-0 border-r border-border bg-surface transition-all duration-150
            ${collapsed ? 'md:w-14' : 'md:w-56'}
            ${mobileOpen ? 'fixed inset-y-0 left-0 top-14 w-56' : 'hidden md:block'}`}
        >
          <ul className="space-y-0.5 p-2">
            {items.map((item) => (
              <li key={item.to}>
                <NavLink
                  to={item.to}
                  onClick={() => setMobileOpen(false)}
                  className={({ isActive }) =>
                    `flex items-center gap-2.5 rounded-md px-2.5 py-2 text-sm font-medium transition-colors ${
                      isActive ? 'bg-primary-50 text-primary-700' : 'text-ink-muted hover:bg-surface-hover hover:text-ink'
                    }`
                  }
                  title={item.label}
                >
                  <span className="w-4 shrink-0 text-center">{item.icon}</span>
                  {!collapsed && <span className="truncate">{item.label}</span>}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>

        <main className="flex-1 overflow-y-auto bg-canvas p-4 sm:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
