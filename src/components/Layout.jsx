import { useEffect, useState } from 'react';
import Sidebar from './Sidebar.jsx';
import Header from './Header.jsx';

export default function Layout({ page, user, children }) {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  useEffect(() => {
    if (!sidebarOpen) return undefined;
    const closeOnEscape = event => event.key === 'Escape' && setSidebarOpen(false);
    window.addEventListener('keydown', closeOnEscape);
    return () => window.removeEventListener('keydown', closeOnEscape);
  }, [sidebarOpen]);
  return <div className="app-shell"><Sidebar user={user} open={sidebarOpen} onNavigate={() => setSidebarOpen(false)} />{sidebarOpen && <button className="sidebar-backdrop" type="button" aria-label="Закрити меню навігації" onClick={() => setSidebarOpen(false)} />}<main className="main-area"><Header page={page} user={user} menuOpen={sidebarOpen} onMenu={() => setSidebarOpen(value => !value)} />{children}</main></div>;
}
