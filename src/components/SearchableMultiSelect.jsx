import Icon from './Icon.jsx';
import './searchable-multi-placement.css';
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

export default function SearchableMultiSelect({ options, value = [], onChange, placeholder, name, portal = false }) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [dropUp, setDropUp] = useState(false);
  const [menuStyle, setMenuStyle] = useState({});
  const ref = useRef(null);
  const menuRef = useRef(null);
  const selected = options.filter(option => value.includes(option.id));
  const filtered = options.filter(option => `${option.name} ${option.email || ''} ${option.project_names || ''}`.toLowerCase().includes(query.trim().toLowerCase()));

  useEffect(() => {
    const close = event => {
      if (!ref.current?.contains(event.target) && !menuRef.current?.contains(event.target)) setOpen(false);
    };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, []);

  function updatePlacement() {
    const trigger = ref.current?.querySelector('.searchable-multi-trigger');
    if (!trigger) return;
    const rect = trigger.getBoundingClientRect();
    let boundary = null;
    let parent = ref.current?.parentElement;
    while (parent) {
      const style = window.getComputedStyle(parent);
      if (['auto', 'scroll'].includes(style.overflowY) && parent.scrollHeight > parent.clientHeight) {
        boundary = parent.getBoundingClientRect();
        break;
      }
      parent = parent.parentElement;
    }
    const below = Math.max(0, Math.min(boundary?.bottom ?? window.innerHeight, window.innerHeight) - rect.bottom);
    const above = Math.max(0, rect.top - (portal ? 0 : boundary?.top ?? 0));
    const desiredHeight = Math.min(menuRef.current?.scrollHeight || 56 + filtered.length * 40, 260) + 8;
    const openUp = below < desiredHeight && above > below;
    setDropUp(openUp);
    if (portal) {
      const availableHeight = openUp ? rect.top - 12 : window.innerHeight - rect.bottom - 12;
      setMenuStyle({
        left: rect.left,
        width: rect.width,
        maxHeight: Math.min(260, Math.max(40, availableHeight)),
        ...(openUp ? { bottom: window.innerHeight - rect.top + 5, top: 'auto' } : { top: rect.bottom + 5, bottom: 'auto' }),
      });
    }
  }

  useEffect(() => {
    if (!open) return undefined;
    const frame = requestAnimationFrame(updatePlacement);
    window.addEventListener('resize', updatePlacement);
    window.addEventListener('scroll', updatePlacement, true);
    return () => {
      cancelAnimationFrame(frame);
      window.removeEventListener('resize', updatePlacement);
      window.removeEventListener('scroll', updatePlacement, true);
    };
  }, [open, filtered.length, portal, query, value.length]);

  function toggle(id) {
    onChange(value.includes(id) ? value.filter(item => item !== id) : [...value, id]);
  }

  function toggleMenu() {
    if (!open) updatePlacement();
    setOpen(current => !current);
  }

  const menu = open && <div className={`searchable-multi-menu${portal ? ' is-portaled' : ''}`} ref={menuRef} style={portal ? menuStyle : undefined}>
    <input autoFocus value={query} onChange={event => setQuery(event.target.value)} placeholder="Пошук..." />
    {filtered.length ? filtered.map(option => <label className="multi-option" key={option.id}>
      <input type="checkbox" checked={value.includes(option.id)} onChange={() => toggle(option.id)} />
      <span><strong>{option.name}</strong>{option.email && <small>{option.email}</small>}{option.project_names && <small className="multi-option-projects">Проєкти: {option.project_names}</small>}</span>
    </label>) : <div className="multi-empty">Нічого не знайдено</div>}
  </div>;

  return <div className={`searchable-multi${dropUp && !portal ? ' drop-up' : ''}`} ref={ref}>
    <button type="button" className={`searchable-multi-trigger${open ? ' is-open' : ''}`} onClick={toggleMenu}>
      {selected.length ? <span className="multi-chips">{selected.map(item => <b key={item.id}>{item.name}</b>)}</span> : <span className="multi-placeholder">{placeholder}</span>}
      <span className="multi-chevron"><Icon name="down" /></span>
    </button>
    {portal ? menu && createPortal(menu, document.body) : menu}
    {value.map(id => <input key={id} type="hidden" name={name} value={id} />)}
  </div>;
}
