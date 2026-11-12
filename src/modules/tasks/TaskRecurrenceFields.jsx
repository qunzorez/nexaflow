import { useState } from 'react';

const options = [
  ['hourly', 'Щогодини'],
  ['daily', 'Щодня'],
  ['weekly', 'Щотижня'],
  ['monthly', 'Щомісяця'],
  ['quarterly', 'Щокварталу'],
  ['yearly', 'Щороку'],
];

export default function TaskRecurrenceFields({ initialRule = 'none' }) {
  const [enabled, setEnabled] = useState(initialRule !== 'none');
  const [rule, setRule] = useState(initialRule === 'none' ? 'monthly' : initialRule);

  return <div className="task-recurrence">
    <label className="task-recurrence-toggle"><input type="checkbox" checked={enabled} onChange={event => setEnabled(event.target.checked)} /> Повторювати цю задачу</label>
    <input type="hidden" name="recurrence_rule" value={enabled ? rule : 'none'} readOnly />
    {enabled && <label className="task-recurrence-select">Період повторення<select value={rule} onChange={event => setRule(event.target.value)}>{options.map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select><small>Наступна задача створиться автоматично після завершення поточної.</small></label>}
  </div>;
}
