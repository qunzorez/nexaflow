import { Component } from 'react';
import { useLocation } from 'react-router-dom';

class RouteErrorBoundaryImpl extends Component {
  state = { failed: false };
  static getDerivedStateFromError() { return { failed: true }; }
  componentDidCatch(error) {
    console.error('Page render failed:', error);
    const path = `${window.location.pathname}${window.location.search}`;
    const recoveryKey = `nexaflow.route-recovery.${path}`;
    try {
      if (!window.sessionStorage.getItem(recoveryKey)) {
        window.sessionStorage.setItem(recoveryKey, '1');
        window.location.reload();
        return;
      }
    } catch {}
    window.location.replace(window.location.pathname === '/dashboard' ? '/' : '/dashboard');
  }
  render() {
    if (this.state.failed) return <main className="loading-screen" role="status" aria-label="Відновлюємо сторінку" />;
    return this.props.children;
  }
}

export default function RouteErrorBoundary({ children }) {
  const location = useLocation();
  return <RouteErrorBoundaryImpl key={location.key || `${location.pathname}${location.search}`}>
    {children}
  </RouteErrorBoundaryImpl>;
}
