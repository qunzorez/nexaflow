import Icon from '../../components/Icon.jsx';
import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import DateTimePicker from '../../components/DateTimePicker.jsx';
import './task-modal.css';
import './task-modal-layer.css';
import './reminder-modal.css';

export default function ReminderModal({ task, onSubmit, onClose }) {
  const [repeat, setRepeat] = useState(true);
  const defaultValue = task.reminder_at
    ? String(task.reminder_at).replace(' ', 'T').slice(0, 16)
    : new Date(Date.now() + 60 * 60 * 1000 - new Date().getTimezoneOffset() * 60 * 1000).toISOString().slice(0, 16);
  useEffect(() => { const previous = document.body.style.overflow; document.body.style.overflow = 'hidden'; return () => { document.body.style.overflow = previous; }; }, []);
  return createPortal(
    <div className="task-modal-backdrop" onMouseDown={event => event.target === event.currentTarget && onClose()}>
      <section className="task-modal reminder-modal" role="dialog" aria-modal="true" aria-labelledby="reminder-title">
        <button type="button" className="task-modal-close" onClick={onClose} aria-label="Закрити"><Icon name="close" /></button>
        <div className="task-modal-scroll reminder-modal-scroll">
          <div className="reminder-modal-heading">
            <span className="reminder-modal-icon" aria-hidden="true"><Icon name="clock" /></span>
            <div><p className="eyebrow">НАГАДУВАННЯ</p><h2 id="reminder-title">Налаштувати нагадування</h2></div>
          </div>
          <div className="reminder-task-summary">
            <span className="reminder-task-summary-icon" aria-hidden="true"><Icon name="check" /></span>
            <div><small>Задача</small><strong>{task.title}</strong></div>
          </div>
          <form className="reminder-form" onSubmit={event => {
            event.preventDefault();
            const form = new FormData(event.currentTarget);
            onSubmit(repeat ? form.get('reminder_at') : '');
          }}>
            <p className="reminder-form-question">Що зробити з цим нагадуванням?</p>
            <label className={`reminder-option${repeat ? ' is-selected' : ''}`}>
              <input type="radio" name="reminder_option" checked={repeat} onChange={() => setRepeat(true)} />
              <span className="reminder-option-radio" aria-hidden="true" />
              <span><strong>Нагадати ще раз</strong><small>Оберіть нову дату та час.</small></span>
            </label>
            {repeat && <div className="reminder-date-field"><span>Дата та час нагадування</span><DateTimePicker name="reminder_at" withTime required defaultValue={defaultValue} /></div>}
            <label className={`reminder-option${!repeat ? ' is-selected' : ''}`}>
              <input type="radio" name="reminder_option" checked={!repeat} onChange={() => setRepeat(false)} />
              <span className="reminder-option-radio" aria-hidden="true" />
              <span><strong>Більше не нагадувати</strong><small>Нагадування буде вимкнено для цієї задачі.</small></span>
            </label>
            <div className="reminder-form-actions"><button type="submit" className="primary-action">Зберегти зміни</button><button type="button" className="outline-btn" onClick={onClose}>Скасувати</button></div>
          </form>
        </div>
      </section>
    </div>,
    document.body,
  );
}
