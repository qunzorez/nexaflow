import Icon from '../../components/Icon.jsx';
import { useEffect, useState } from 'react';
import DateTimePicker from '../../components/DateTimePicker.jsx';

export default function ReminderModal({ task, onSubmit, onClose }) {
  const [repeat, setRepeat] = useState(true);
  const defaultValue = task.reminder_at ? String(task.reminder_at).replace(' ', 'T').slice(0, 16) : '';
  useEffect(() => { const previous = document.body.style.overflow; document.body.style.overflow = 'hidden'; return () => { document.body.style.overflow = previous; }; }, []);
  return <div className="task-modal-backdrop" onMouseDown={event => event.target === event.currentTarget && onClose()}><section className="task-modal reminder-modal"><button type="button" className="task-modal-close" onClick={onClose} aria-label="Закрити"><Icon name="close" /></button><div className="task-modal-scroll"><p className="eyebrow">НАГАДУВАННЯ</p><h2>{task.title}</h2><p className="task-modal-subtitle">Потрібно нагадати про цю задачу ще раз?</p><form className="task-form" onSubmit={event => { event.preventDefault(); const form = new FormData(event.currentTarget); onSubmit(repeat ? form.get('reminder_at') : ''); }}><label className="reminder-choice"><input type="radio" checked={repeat} onChange={() => setRepeat(true)} /> Так, нагадати ще раз</label>{repeat && <label>Дата та час<DateTimePicker name="reminder_at" withTime required defaultValue={defaultValue} /></label>}<label className="reminder-choice"><input type="radio" checked={!repeat} onChange={() => setRepeat(false)} /> Ні, більше не нагадувати</label><div className="task-form-actions"><button className="primary-action">Зберегти</button><button type="button" className="outline-btn" onClick={onClose}>Скасувати</button></div></form></div></section></div>;
}
