import { useEffect, useRef, useState } from 'react';

export default function SearchableUserSelect({ users, value, onChange }) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const ref = useRef(null);
  const selected = users.find(user => user.id === value);
  const filtered = users.filter(user => `${user.name} ${user.email}`.toLowerCase().includes(query.trim().toLowerCase()));

  useEffect(() => {
    const close = event => { if (!ref.current?.contains(event.target)) setOpen(false); };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, []);

  return <div className="searchable-user-select" ref={ref}>
    <button type="button" className={`searchable-user-trigger${open ? ' is-open' : ''}`} onClick={() => setOpen(current => !current)}>{selected ? `${selected.name} — ${selected.email}` : 'Оберіть користувача'}<span>⌄</span></button>
    {open && <div className="searchable-user-menu"><input autoFocus value={query} onChange={event => setQuery(event.target.value)} placeholder="Пошук за ім’ям або email..." />{filtered.length ? filtered.map(user => <button type="button" className="searchable-user-option" key={user.id} onClick={() => { onChange(user.id); setQuery(''); setOpen(false); }}>{user.name}<small>{user.email}</small></button>) : <div className="searchable-user-empty">Користувачів не знайдено</div>}</div>}
  </div>;
}
