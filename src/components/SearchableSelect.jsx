import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import Icon from './Icon.jsx';
import './searchable-select.css';

export default function SearchableSelect({ options = [], value = '', onChange, placeholder = 'Оберіть значення', name, portal = false, disabled = false }) {
  const [open, setOpen] = useState(false);
  const [dropUp, setDropUp] = useState(false);
  const [query, setQuery] = useState('');
  const [menuStyle, setMenuStyle] = useState({});
  const ref = useRef(null);
  const menuRef = useRef(null);
  const selected = options.find(option => String(option.id) === String(value));
  const filtered = options.filter(option => `${option.name} ${option.email || ''}`.toLowerCase().includes(query.trim().toLowerCase()));

  useEffect(() => {
    const close = event => { if (!ref.current?.contains(event.target) && !menuRef.current?.contains(event.target)) setOpen(false); };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, []);

  useEffect(() => {
    if (!open) return undefined;
    const updatePlacement = () => {
      const trigger = ref.current?.querySelector('.searchable-select-trigger');
      if (!trigger) return;
      const rect = trigger.getBoundingClientRect();
      const below = window.innerHeight - rect.bottom;
      const above = rect.top;
      const openUp = below < 250 && above > below;
      setDropUp(openUp);
      if (portal) {
        const availableHeight = openUp ? rect.top - 12 : window.innerHeight - rect.bottom - 12;
        setMenuStyle({ left: rect.left, width: rect.width, maxHeight: Math.min(260, Math.max(80, availableHeight)), ...(openUp ? { bottom: window.innerHeight - rect.top + 5, top: 'auto' } : { top: rect.bottom + 5, bottom: 'auto' }) });
      }
    };
    const frame = requestAnimationFrame(updatePlacement);
    window.addEventListener('resize', updatePlacement);
    window.addEventListener('scroll', updatePlacement, true);
    return () => {
      cancelAnimationFrame(frame);
      window.removeEventListener('resize', updatePlacement);
      window.removeEventListener('scroll', updatePlacement, true);
    };
  }, [open, options.length, portal, query]);

  function choose(option) { onChange(String(option.id)); setQuery(''); setOpen(false); }
  function toggleMenu() { if (!open) requestAnimationFrame(() => { const trigger = ref.current?.querySelector('.searchable-select-trigger'); if (trigger && portal) { const rect = trigger.getBoundingClientRect(); const below = window.innerHeight - rect.bottom; const above = rect.top; const openUp = below < 250 && above > below; const availableHeight = openUp ? rect.top - 12 : window.innerHeight - rect.bottom - 12; setDropUp(openUp); setMenuStyle({ left: rect.left, width: rect.width, maxHeight: Math.min(260, Math.max(80, availableHeight)), ...(openUp ? { bottom: window.innerHeight - rect.top + 5, top: 'auto' } : { top: rect.bottom + 5, bottom: 'auto' }) }); } }); setOpen(current => !current); }

  const menu = open && <div ref={menuRef} className={`searchable-select-menu${dropUp && !portal ? ' is-upward' : ''}${portal ? ' is-portaled' : ''}`} style={portal ? menuStyle : undefined}>
      <input autoFocus value={query} onChange={event => setQuery(event.target.value)} placeholder="Пошук..." />
      <div className="searchable-select-options">{filtered.length ? filtered.map(option => <button type="button" className={`searchable-select-option${String(option.id) === String(value) ? ' selected' : ''}`} key={option.id} onClick={() => choose(option)}><span>{option.name}</span>{option.email && <small>{option.email}</small>}<Icon name={String(option.id) === String(value) ? 'check' : 'chevron-right'} /></button>) : <div className="searchable-select-empty">Нічого не знайдено</div>}</div>
    </div>;

  return <div className={`searchable-select${open ? ' is-open' : ''}`} ref={ref}>
    <button type="button" className="searchable-select-trigger" onClick={toggleMenu} disabled={disabled}>
      <span className={selected ? 'searchable-select-value' : 'searchable-select-placeholder'}>{selected?.name || placeholder}</span>
      <Icon name="down" />
    </button>
    {portal ? menu && createPortal(menu, document.body) : menu}
    {name && <input type="hidden" name={name} value={value} />}
  </div>;
}
