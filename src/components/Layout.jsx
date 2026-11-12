import { useState } from 'react';
import Sidebar from './Sidebar.jsx';
import Header from './Header.jsx';

export default function Layout({ page, user, children }) {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  return <div className="app-shell"><Sidebar user={user} open={sidebarOpen} /><main className="main-area"><Header page={page} user={user} onMenu={() => setSidebarOpen(!sidebarOpen)} />{children}</main></div>;
}
