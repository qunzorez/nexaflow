import { Component } from 'react';
import { useLocation } from 'react-router-dom';

class RouteErrorBoundaryImpl extends Component {
  state = { failed: false };
  static getDerivedStateFromError() { return { failed: true }; }
  componentDidCatch(error) { console.error('Page render failed:', error); }
  retry = () => this.setState({ failed: false });
  render() {
    if (this.state.failed) return <main className="loading-screen" role="alert"><section className="route-error-card"><h1>Не вдалося відкрити сторінку</h1><p>Спробуйте відкрити її ще раз. Якщо помилка повториться, поверніться до огляду.</p><button type="button" onClick={this.retry}>Спробувати ще раз</button><a href="/dashboard">До огляду</a></section></main>;
    return this.props.children;
  }
}

export default function RouteErrorBoundary({ children }) {
  const location = useLocation();
  return <RouteErrorBoundaryImpl key={location.key || `${location.pathname}${location.search}`}>
    {children}
  </RouteErrorBoundaryImpl>;
}
