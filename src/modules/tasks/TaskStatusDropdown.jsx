import { useEffect, useRef, useState } from 'react';

const statuses = {
  todo: { label: 'До виконання', icon: '○' },
  in_progress: { label: 'У роботі', icon: '◐' },
  review: { label: 'На перевірці', icon: '!' },
  done: { label: 'Завершено', icon: '✓' },
  cancelled: { label: 'Скасовано', icon: '×' },
  archived: { label: 'Архів', icon: '▣' },
};

export default function TaskStatusDropdown({ task, canManage, onChange }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  const current = statuses[task.status] || statuses.todo;
  const options = Object.entries(statuses).filter(([value]) => canManage || !['review', 'cancelled', 'archived'].includes(value));
  useEffect(() => { const close = event => { if (!ref.current?.contains(event.target)) setOpen(false); }; document.addEventListener('mousedown', close); return () => document.removeEventListener('mousedown', close); }, []);
  return <div className={`detail-status-dropdown status-menu-${task.status}${open ? ' is-open' : ''}`} ref={ref}><button type="button" className="detail-status-trigger" onClick={() => setOpen(value => !value)}><span className="detail-status-icon">{current.icon}</span><span>{current.label}</span><span className="detail-status-chevron" /></button>{open && <div className="detail-status-menu">{options.map(([value, option]) => <button type="button" className={value === task.status ? 'active' : ''} key={value} onClick={() => { setOpen(false); onChange(value); }}><span className={`detail-status-icon detail-status-icon-${value}`}>{option.icon}</span><span>{option.label}</span>{value === task.status && <b>✓</b>}</button>)}</div>}</div>;
}
