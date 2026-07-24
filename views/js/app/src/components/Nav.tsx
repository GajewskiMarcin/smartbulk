import { NavLink } from 'react-router-dom';
import { cn } from '../lib/utils';
import { t } from '../lib/i18n';

interface NavItem {
  to: string;
  labelKey: string;
  fallback: string;
  emoji: string;
  badge?: number;
  utility?: boolean;
}

const items: NavItem[] = [
  { to: '/dashboard',   labelKey: 'nav.dashboard',    fallback: 'Dashboard',    emoji: '📊' },
  { to: '/bulk-editor', labelKey: 'nav.bulk_editor',  fallback: 'Bulk Editor',  emoji: '📝' },
  { to: '/ai',          labelKey: 'nav.ai_assistant', fallback: 'AI Assistant', emoji: '✨', badge: 12 },
  { to: '/prompts',     labelKey: 'nav.prompts',      fallback: 'Prompts',      emoji: '💬' },
  { to: '/health',      labelKey: 'nav.health',       fallback: 'Health',       emoji: '💊' },
  { to: '/history',     labelKey: 'nav.history',      fallback: 'History',      emoji: '🕐' },
  { to: '/scheduler',   labelKey: 'nav.scheduler',    fallback: 'Scheduler',    emoji: '⏰' },
  { to: '/settings',    labelKey: 'nav.settings',     fallback: 'Settings',     emoji: '⚙️', utility: true },
  { to: '/support',     labelKey: 'nav.support',      fallback: 'Support',      emoji: '☕', utility: true },
];

export default function Nav() {
  const mainItems = items.filter((i) => !i.utility);
  const utilityItems = items.filter((i) => i.utility);

  return (
    <nav className="flex items-center gap-1 p-2 bg-white border border-border rounded-[8px] overflow-x-auto whitespace-nowrap">
      {mainItems.map((item) => (
        <NavItemLink key={item.to} item={item} />
      ))}
      <div className="flex-1" />
      {utilityItems.map((item) => (
        <NavItemLink key={item.to} item={item} />
      ))}
    </nav>
  );
}

function NavItemLink({ item }: { item: NavItem }) {
  return (
    <NavLink
      to={item.to}
      className={({ isActive }) =>
        cn(
          'inline-flex items-center gap-1.5 px-3.5 py-2 rounded-md text-[13px] font-medium transition-colors no-underline',
          isActive
            ? 'bg-primary text-white hover:bg-primary-700'
            : 'text-slate-600 hover:bg-muted hover:text-slate-900'
        )
      }
    >
      {({ isActive }) => (
        <>
          <span className="text-[15px] leading-none">{item.emoji}</span>
          <span>{t(item.labelKey, item.fallback)}</span>
          {item.badge !== undefined && (
            <span
              className={cn(
                'ml-0.5 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1.5 rounded-full text-[10px] font-bold',
                isActive ? 'bg-white/25 text-white' : 'bg-slate-200 text-slate-700'
              )}
            >
              {item.badge}
            </span>
          )}
        </>
      )}
    </NavLink>
  );
}
