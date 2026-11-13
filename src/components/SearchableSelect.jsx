import { useEffect, useRef, useState } from 'react';
import Icon from './Icon.jsx';
import './searchable-select.css';

export default function SearchableSelect({ options = [], value = '', onChange, placeholder = 'Оберіть значення', name }) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const ref = useRef(null);
  const selected = options.find(option => String(option.id) === String(value));
  const filtered = options.filter(option => `${option.name} ${option.email || ''}`.toLowerCase().includes(query.trim().toLowerCase()));

  useEffect(() => {
    const close = event => { if (!ref.current?.contains(event.target)) setOpen(false); };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, []);

  function choose(option) { onChange(String(option.id)); setQuery(''); setOpen(false); }

  return <div className={`searchable-select${open ? ' is-open' : ''}`} ref={ref}>
    <button type="button" className="searchable-select-trigger" onClick={() => setOpen(current => !current)}>
      <span className={selected ? 'searchable-select-value' : 'searchable-select-placeholder'}>{selected?.name || placeholder}</span>
      <Icon name="down" />
    </button>
    {open && <div className="searchable-select-menu">
      <input autoFocus value={query} onChange={event => setQuery(event.target.value)} placeholder="Пошук..." />
      <div className="searchable-select-options">{filtered.length ? filtered.map(option => <button type="button" className={`searchable-select-option${String(option.id) === String(value) ? ' selected' : ''}`} key={option.id} onClick={() => choose(option)}><span>{option.name}</span>{option.email && <small>{option.email}</small>}<Icon name={String(option.id) === String(value) ? 'check' : 'chevron-right'} /></button>) : <div className="searchable-select-empty">Нічого не знайдено</div>}</div>
    </div>}
    {name && <input type="hidden" name={name} value={value} />}
  </div>;
}
