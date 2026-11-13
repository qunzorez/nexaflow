import Icon from '../../components/Icon.jsx';
import { useEffect, useRef, useState } from 'react';

const statuses = {
  todo: { label: 'До виконання', icon: <Icon name="todo" /> },
  in_progress: { label: 'У роботі', icon: <Icon name="progress" /> },
  review: { label: 'На перевірці', icon: <Icon name="urgent" /> },
  done: { label: 'Завершено', icon: <Icon name="check" /> },
  cancelled: { label: 'Скасовано', icon: <Icon name="close" /> },
  archived: { label: 'Архів', icon: <Icon name="archive" /> },
};

export default function TaskStatusDropdown({ task, canManage, onChange }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  const current = statuses[task.status] || statuses.todo;
  const options = Object.entries(statuses).filter(([value]) => canManage || !['review', 'cancelled', 'archived'].includes(value));
  useEffect(() => { const close = event => { if (!ref.current?.contains(event.target)) setOpen(false); }; document.addEventListener('mousedown', close); return () => document.removeEventListener('mousedown', close); }, []);
  return <div className={`detail-status-dropdown status-menu-${task.status}${open ? ' is-open' : ''}`} ref={ref}><button type="button" className="detail-status-trigger" onClick={() => setOpen(value => !value)}><span className="detail-status-icon">{current.icon}</span><span>{current.label}</span><span className="detail-status-chevron"><Icon name="down" /></span></button>{open && <div className="detail-status-menu">{options.map(([value, option]) => <button type="button" className={value === task.status ? 'active' : ''} key={value} onClick={() => { setOpen(false); onChange(value); }}><span className={`detail-status-icon detail-status-icon-${value}`}>{option.icon}</span><span>{option.label}</span>{value === task.status && <b><Icon name="check" /></b>}</button>)}</div>}</div>;
}
