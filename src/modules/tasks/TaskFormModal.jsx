import Icon from '../../components/Icon.jsx';
import { useEffect, useState } from "react";
import { createPortal } from "react-dom";
import SearchableMultiSelect from "../../components/SearchableMultiSelect.jsx";
import TaskDescriptionEditor from "./TaskDescriptionEditor.jsx";
import PrioritySelect from "./PrioritySelect.jsx";
import TaskRecurrenceFields from "./TaskRecurrenceFields.jsx";
import DateTimePicker from "../../components/DateTimePicker.jsx";
import "./task-editor.css";
import "./priority-field.css";
import "./priority-field-fix.css";
import "./priority-field-polish.css";
import "./priority-dropdown.css";
import "./task-recurrence.css";
import "./task-permissions.css";
import "./task-modal-layer.css";

export default function TaskFormModal({
  data,
  editing,
  error,
  projectIds,
  assigneeIds,
  clientIds,
  initialDueDate = "",
  setProjectIds,
  setAssigneeIds,
  setClientIds,
  onSubmit,
  onClose,
}) {
  const [description, setDescription] = useState(editing?.description || "");
  const canManage = ["admin", "owner", "manager"].includes(data.user?.role);
  useEffect(() => {
    const previous = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    document.body.dataset.taskCanManage = canManage ? "true" : "false";
    return () => {
      document.body.style.overflow = previous;
      delete document.body.dataset.taskCanManage;
    };
  }, [canManage]);
  return createPortal(
    <div
      className="task-modal-backdrop"
      onMouseDown={(event) => event.target === event.currentTarget && onClose()}
    >
      <section className="task-modal">
        <button type="button" className="task-modal-close" onClick={onClose} aria-label="Закрити">
          <Icon name="close" />
        </button>
        <div className="task-modal-scroll">
          <p className="eyebrow">ЗАДАЧА</p>
          <h2>{editing ? "Редагувати задачу" : "Створити задачу"}</h2>
          <p className="task-modal-subtitle">
            Додайте деталі, проєкти та всіх відповідальних виконавців.
          </p>
          {error && <div className="task-modal-error" role="alert">{error}</div>}
          <form className="task-form" onSubmit={onSubmit}>
            <label>
              Назва задачі
              <input
                name="title"
                defaultValue={editing?.title || ""}
                required
                placeholder="Наприклад, Підготувати договір"
              />
            </label>
            <label>
              Опис
              <TaskDescriptionEditor
                value={description}
                onChange={setDescription}
              />
            </label>
            <label>
              Клієнти
              <SearchableMultiSelect
                name="client_ids"
                options={data.clients || []}
                value={clientIds || []}
                onChange={setClientIds}
                placeholder="Оберіть клієнтів"
              />
              <small className="field-hint">
                Можна вибрати кілька клієнтів
              </small>
            </label>
            <div className="task-form-grid">
              <label>
                Проєкти
                <SearchableMultiSelect
                  name="project_ids"
                  options={data.projects}
                  value={projectIds}
                  onChange={setProjectIds}
                  placeholder="Оберіть проєкти"
                />
                <small className="field-hint">
                  Можна вибрати кілька проєктів
                </small>
              </label>
              <label>
                Виконавці
                <SearchableMultiSelect
                  name="assignee_ids"
                  options={data.people}
                  value={assigneeIds}
                  onChange={setAssigneeIds}
                  placeholder="Оберіть виконавців"
                />
                <small className="field-hint">
                  Кожен виконавець отримає сповіщення
                </small>
              </label>
            </div>
            <div className="task-form-grid">
              <label className="priority-field">
                <span className="priority-field-label">
                  Пріоритет <small>Наскільки термінова задача</small>
                </span>
                <PrioritySelect value={editing?.priority || "normal"} />
              </label>
              {editing && (
                <label>
                  Статус
                  <select name="status" defaultValue={editing.status || "todo"}>
                    <option value="todo">До виконання</option>
                    <option value="in_progress">У роботі</option>
                    <option value="review">На перевірці</option>
                    <option value="done">Завершено</option>
                    <option value="cancelled">Скасовано</option>
                    <option value="archived">Архів</option>
                  </select>
                </label>
              )}
            </div>
            <div className="task-form-grid">
              <label>
                Дедлайн
                <DateTimePicker name="due_date" withTime timeLabel="Час дедлайну" defaultValue={editing?.due_date || initialDueDate || ""} />
              </label>
              <label>
                Нагадування
                <DateTimePicker name="reminder_at" withTime timeLabel="Час нагадування" defaultValue={editing?.reminder_at ? editing.reminder_at.replace(" ", "T").slice(0, 16) : ""} />
              </label>
            </div>
            <TaskRecurrenceFields
              initialRule={editing?.recurrence_rule || "none"}
            />
            {canManage && (
              <label className="task-reminder-visibility">
                <input
                  type="checkbox"
                  name="creator_reminder"
                  defaultChecked={Boolean(
                    Number(editing?.creator_reminder || 0),
                  )}
                />{" "}
                Показувати це нагадування мені на Dashboard
              </label>
            )}
            <div className="task-form-actions">
              <button className="primary-action">
                {editing ? "Зберегти зміни" : "Створити задачу"}
              </button>
              <button type="button" className="outline-btn" onClick={onClose}>
                Скасувати
              </button>
            </div>
          </form>
        </div>
      </section>
    </div>,
    document.body,
  );
}
