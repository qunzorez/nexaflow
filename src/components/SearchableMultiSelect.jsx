import Icon from './Icon.jsx';
import './searchable-multi-placement.css';
import { useEffect, useRef, useState } from 'react';

export default function SearchableMultiSelect({ options, value = [], onChange, placeholder, name }) {
  const [open, setOpen] = useState(false); const [query, setQuery] = useState(''); const [dropUp, setDropUp] = useState(false); const ref = useRef(null); const menuRef = useRef(null);
  const selected = options.filter(option => value.includes(option.id));
  const filtered = options.filter(option => `${option.name} ${option.email || ''} ${option.project_names || ''}`.toLowerCase().includes(query.trim().toLowerCase()));
  useEffect(() => { const close = event => { if (!ref.current?.contains(event.target)) setOpen(false); }; document.addEventListener('mousedown', close); return () => document.removeEventListener('mousedown', close); }, []);
  useEffect(() => {
    if (!open) { setDropUp(false); return undefined; }
    const updatePlacement = () => {
      const trigger = ref.current?.querySelector('.searchable-multi-trigger');
      const menu = menuRef.current;
      if (!trigger || !menu) return;
      const triggerRect = trigger.getBoundingClientRect();
      let boundary = null; let parent = ref.current?.parentElement;
      while (parent) { const style = window.getComputedStyle(parent); if (['auto', 'scroll'].includes(style.overflowY) && parent.scrollHeight > parent.clientHeight) { boundary = parent.getBoundingClientRect(); break; } parent = parent.parentElement; }
      const bottomLimit = boundary?.bottom || window.innerHeight; const topLimit = boundary?.top || 0;
      const menuHeight = Math.min(menu.scrollHeight, 260) + 16;
      setDropUp(bottomLimit - triggerRect.bottom < menuHeight && triggerRect.top - topLimit > menuHeight);
    };
    const frame = requestAnimationFrame(updatePlacement);
    window.addEventListener('resize', updatePlacement);
    window.addEventListener('scroll', updatePlacement, true);
    return () => { cancelAnimationFrame(frame); window.removeEventListener('resize', updatePlacement); window.removeEventListener('scroll', updatePlacement, true); };
  }, [open, filtered.length]);
  function toggle(id) { onChange(value.includes(id) ? value.filter(item => item !== id) : [...value, id]); }
  function toggleMenu() { const rect = ref.current?.querySelector('.searchable-multi-trigger')?.getBoundingClientRect(); let boundary = null; let parent = ref.current?.parentElement; while (parent) { const style = window.getComputedStyle(parent); if (['auto', 'scroll'].includes(style.overflowY) && parent.scrollHeight > parent.clientHeight) { boundary = parent.getBoundingClientRect(); break; } parent = parent.parentElement; } const bottomLimit = boundary?.bottom || window.innerHeight; const topLimit = boundary?.top || 0; const menuHeight = Math.min(menuRef.current?.scrollHeight || 260, 260) + 16; const shouldOpenUp = Boolean(rect && bottomLimit - rect.bottom < menuHeight && rect.top - topLimit > menuHeight); setDropUp(shouldOpenUp); setOpen(current => !current); }
  return <div className={`searchable-multi${dropUp ? ' drop-up' : ''}`} ref={ref}><button type="button" className={`searchable-multi-trigger${open ? ' is-open' : ''}`} onClick={toggleMenu}>{selected.length ? <span className="multi-chips">{selected.map(item => <b key={item.id}>{item.name}</b>)}</span> : <span className="multi-placeholder">{placeholder}</span>}<span className="multi-chevron"><Icon name="down" /></span></button>{open && <div className="searchable-multi-menu" ref={menuRef}><input autoFocus value={query} onChange={event => setQuery(event.target.value)} placeholder="Пошук..." />{filtered.length ? filtered.map(option => <label className="multi-option" key={option.id}><input type="checkbox" checked={value.includes(option.id)} onChange={() => toggle(option.id)} /><span><strong>{option.name}</strong>{option.email && <small>{option.email}</small>}{option.project_names && <small className="multi-option-projects">Проєкти: {option.project_names}</small>}</span></label>) : <div className="multi-empty">Нічого не знайдено</div>}</div>}{value.map(id => <input key={id} type="hidden" name={name} value={id} />)}</div>;
}
