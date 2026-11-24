import { useEffect, useState } from 'react';
import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { api } from '../services/api.js';

export default function EmailVerifiedRoute() {
  const location = useLocation();
  const [state, setState] = useState(null);
  useEffect(() => {
    let active = true;
    api('/api/me.php?optional=1').then(result => {
      if (active) setState(result.ok && result.authenticated ? (result.user?.email_verified_at ? (result.user?.client_portal_only ? 'client' : 'verified') : 'unverified') : 'guest');
    });
    return () => { active = false; };
  }, []);
  if (!state) return <div className="loading-screen" role="status">Перевіряємо доступ…</div>;
  if (state === 'guest') return <Navigate to="/login" replace />;
  if (state === 'unverified') return <Navigate to="/settings?verify_email=1" replace />;
  if (state === 'client' && location.pathname !== '/accounting/portal') return <Navigate to="/accounting/portal" replace />;
  return <Outlet />;
}
