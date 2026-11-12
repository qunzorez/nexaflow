import { useEffect, useRef, useState } from 'react';

export default function SearchableMultiSelect({ options, value = [], onChange, placeholder, name }) {
  const [open, setOpen] = useState(false); const [query, setQuery] = useState(''); const ref = useRef(null);
  const selected = options.filter(option => value.includes(option.id));
  const filtered = options.filter(option => `${option.name} ${option.email || ''}`.toLowerCase().includes(query.trim().toLowerCase()));
  useEffect(() => { const close = event => { if (!ref.current?.contains(event.target)) setOpen(false); }; document.addEventListener('mousedown', close); return () => document.removeEventListener('mousedown', close); }, []);
  function toggle(id) { onChange(value.includes(id) ? value.filter(item => item !== id) : [...value, id]); }
  return <div className="searchable-multi" ref={ref}><button type="button" className={`searchable-multi-trigger${open ? ' is-open' : ''}`} onClick={() => setOpen(current => !current)}>{selected.length ? <span className="multi-chips">{selected.map(item => <b key={item.id}>{item.name}</b>)}</span> : <span className="multi-placeholder">{placeholder}</span>}<span className="multi-chevron">⌄</span></button>{open && <div className="searchable-multi-menu"><input autoFocus value={query} onChange={event => setQuery(event.target.value)} placeholder="Пошук..." />{filtered.length ? filtered.map(option => <label className="multi-option" key={option.id}><input type="checkbox" checked={value.includes(option.id)} onChange={() => toggle(option.id)} /><span><strong>{option.name}</strong>{option.email && <small>{option.email}</small>}</span></label>) : <div className="multi-empty">Нічого не знайдено</div>}</div>}{value.map(id => <input key={id} type="hidden" name={name} value={id} />)}</div>;
}
