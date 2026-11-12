import { useEffect, useRef, useState } from 'react';

const options = [
  { value: 'low', label: 'Низький', icon: '↓' },
  { value: 'normal', label: 'Звичайний', icon: '—' },
  { value: 'high', label: 'Високий', icon: '↑' },
  { value: 'urgent', label: 'Терміновий', icon: '!' },
];

export default function PrioritySelect({ value = 'normal' }) {
  const [open, setOpen] = useState(false);
  const [selected, setSelected] = useState(value);
  const rootRef = useRef(null);
  const current = options.find(option => option.value === selected) || options[1];

  useEffect(() => {
    const close = event => {
      if (!rootRef.current?.contains(event.target)) setOpen(false);
    };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, []);

  return (
    <div className={`priority-dropdown priority-dropdown-${current.value}${open ? ' is-open' : ''}`} ref={rootRef}>
      <input type="hidden" name="priority" value={selected} readOnly />
      <button type="button" className="priority-dropdown-trigger" onClick={() => setOpen(opened => !opened)} aria-expanded={open}>
        <span className="priority-dropdown-icon">{current.icon}</span>
        <span>{current.label}</span>
        <span className="priority-dropdown-chevron" aria-hidden="true" />
      </button>
      {open && <div className="priority-dropdown-menu">
        {options.map(option => <button type="button" className={option.value === current.value ? 'active' : ''} key={option.value} onClick={() => {
          setSelected(option.value);
          setOpen(false);
        }}>
          <span className={`priority-dropdown-icon priority-dropdown-icon-${option.value}`}>{option.icon}</span>
          <span>{option.label}</span>
          {option.value === current.value && <b>✓</b>}
        </button>)}
      </div>}
    </div>
  );
}
