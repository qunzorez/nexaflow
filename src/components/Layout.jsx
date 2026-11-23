import { useEffect, useState } from 'react';
import Sidebar from './Sidebar.jsx';
import Header from './Header.jsx';

export default function Layout({ page, user, breadcrumbCurrent, children }) {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [showScrollTop, setShowScrollTop] = useState(false);
  useEffect(() => {
    const updateScrollButton = () => setShowScrollTop(window.scrollY > 240);
    updateScrollButton();
    window.addEventListener('scroll', updateScrollButton, { passive: true });
    return () => window.removeEventListener('scroll', updateScrollButton);
  }, []);
  useEffect(() => {
    if (!sidebarOpen) return undefined;
    const closeOnEscape = event => event.key === 'Escape' && setSidebarOpen(false);
    window.addEventListener('keydown', closeOnEscape);
    return () => window.removeEventListener('keydown', closeOnEscape);
  }, [sidebarOpen]);
  return <div className="app-shell"><Sidebar user={user} open={sidebarOpen} onNavigate={() => setSidebarOpen(false)} />{sidebarOpen && <button className="sidebar-backdrop" type="button" aria-label="Закрити меню навігації" onClick={() => setSidebarOpen(false)} />}<main className="main-area"><Header page={page} user={user} breadcrumbCurrent={breadcrumbCurrent} menuOpen={sidebarOpen} onMenu={() => setSidebarOpen(value => !value)} />{children}</main>{showScrollTop&&<button type="button" className="scroll-to-top" aria-label="Прокрутити сторінку вгору" title="Вгору" onClick={()=>window.scrollTo({top:0,behavior:'smooth'})}><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 14 6-6 6 6"/></svg></button>}</div>;
}
