import { Component } from 'react';

export default class RouteErrorBoundary extends Component {
  state = { failed: false };
  static getDerivedStateFromError() { return { failed: true }; }
  componentDidCatch(error) { console.error('Page render failed:', error); }
  render() {
    if (this.state.failed) return <main className="loading-screen" role="alert"><section className="route-error-card"><h1>Не вдалося відкрити сторінку</h1><p>Оновіть сторінку. Якщо помилка повториться, поверніться до огляду.</p><button type="button" onClick={() => window.location.reload()}>Оновити сторінку</button><a href="/dashboard">До огляду</a></section></main>;
    return this.props.children;
  }
}
