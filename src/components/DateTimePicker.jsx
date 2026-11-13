import Icon from './Icon.jsx';
import { useEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import "./date-time-picker.css";

const WEEKDAYS = ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Нд"];
const pad = (value) => String(value).padStart(2, "0");

function parseValue(value, withTime) {
  const raw = String(value || "").replace(" ", "T");
  const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}))?/);
  const now = new Date();
  return { year: match ? +match[1] : now.getFullYear(), month: match ? +match[2] - 1 : now.getMonth(), day: match ? +match[3] : now.getDate(), hour: withTime && match?.[4] ? +match[4] : 9, minute: withTime && match?.[5] ? +match[5] : 0 };
}

function toValue(parts, withTime) {
  const date = `${parts.year}-${pad(parts.month + 1)}-${pad(parts.day)}`;
  return withTime ? `${date}T${pad(parts.hour)}:${pad(parts.minute)}` : date;
}

function displayValue(value, withTime) {
  if (!value) return withTime ? "Оберіть дату та час" : "Оберіть дату";
  const parts = parseValue(value, withTime);
  const date = `${pad(parts.day)}.${pad(parts.month + 1)}.${parts.year}`;
  return withTime ? `${date} · ${pad(parts.hour)}:${pad(parts.minute)}` : date;
}

function monthDays(year, month) {
  const count = new Date(year, month + 1, 0).getDate();
  const firstDay = (new Date(year, month, 1).getDay() + 6) % 7;
  return [...Array(firstDay).fill(null), ...Array.from({ length: count }, (_, i) => i + 1)];
}

export default function DateTimePicker({ name, value, defaultValue = "", onChange, withTime = false, timeLabel = "Час", required = false }) {
  const initial = value ?? defaultValue;
  const [internalValue, setInternalValue] = useState(initial);
  const [open, setOpen] = useState(false);
  const [popoverStyle, setPopoverStyle] = useState({});
  const [view, setView] = useState(() => parseValue(initial, withTime));
  const rootRef = useRef(null);
  const triggerRef = useRef(null);
  const popoverRef = useRef(null);
  const currentValue = value ?? internalValue;
  const days = useMemo(() => monthDays(view.year, view.month), [view.year, view.month]);
  const monthName = new Intl.DateTimeFormat("uk-UA", { month: "long", year: "numeric" }).format(new Date(view.year, view.month, 1));

  useEffect(() => {
    const close = (event) => {
      if (rootRef.current?.contains(event.target) || popoverRef.current?.contains(event.target)) return;
      setOpen(false);
    };
    document.addEventListener("mousedown", close);
    return () => document.removeEventListener("mousedown", close);
  }, []);

  useEffect(() => {
    if (!open || !triggerRef.current) return undefined;
    const updatePosition = () => {
      const rect = triggerRef.current.getBoundingClientRect();
      const width = Math.min(300, window.innerWidth - 32);
      const estimatedHeight = withTime ? 405 : 345;
      const left = Math.min(Math.max(16, rect.left), window.innerWidth - width - 16);
      const below = rect.bottom + 9;
      const top = below + estimatedHeight <= window.innerHeight - 12
        ? below
        : Math.max(12, rect.top - estimatedHeight - 9);
      setPopoverStyle({ left, top, width });
    };
    updatePosition();
    window.addEventListener("resize", updatePosition);
    window.addEventListener("scroll", updatePosition, true);
    return () => {
      window.removeEventListener("resize", updatePosition);
      window.removeEventListener("scroll", updatePosition, true);
    };
  }, [open, withTime]);

  const setValue = (next) => { if (value === undefined) setInternalValue(next); onChange?.(next); };
  const selectDay = (day) => { const next = { ...view, day }; setValue(toValue(next, withTime)); setView(next); if (!withTime) setOpen(false); };
  const shiftMonth = (offset) => { const next = new Date(view.year, view.month + offset, 1); setView((current) => ({ ...current, year: next.getFullYear(), month: next.getMonth() })); };
  const updateTime = (key, nextValue) => { const next = { ...parseValue(currentValue, true), ...view, [key]: +nextValue }; setValue(toValue(next, true)); setView(next); };
  const today = () => { const now = new Date(); const next = { ...view, year: now.getFullYear(), month: now.getMonth(), day: now.getDate() }; setValue(toValue(next, withTime)); setView(next); };
  const selected = parseValue(currentValue, withTime);

  return <div className={`date-time-picker ${open ? "is-open" : ""}`} ref={rootRef}>
    <input type="hidden" name={name} value={currentValue || ""} required={required} readOnly />
    <button ref={triggerRef} type="button" className="date-time-trigger" onClick={() => setOpen((state) => !state)} aria-expanded={open}>
      <span className="date-time-icon">{withTime ? <Icon name="clock" /> : <Icon name="calendar" />}</span><span className={currentValue ? "date-time-value" : "date-time-placeholder"}>{displayValue(currentValue, withTime)}</span>
    </button>
    {open && createPortal(<div ref={popoverRef} className="date-time-popover" style={popoverStyle}>
      <div className="date-time-head"><button type="button" onClick={() => shiftMonth(-1)} aria-label="Попередня сторінка"><Icon name="left" /></button><strong>{monthName}</strong><button type="button" onClick={() => shiftMonth(1)} aria-label="Наступна сторінка"><Icon name="right" /></button></div>
      <div className="calendar-weekdays">{WEEKDAYS.map((day) => <span key={day}>{day}</span>)}</div>
      <div className="calendar-grid">{days.map((day, index) => day ? <button type="button" key={day} className={day === selected.day && selected.month === view.month && selected.year === view.year ? "selected" : ""} onClick={() => selectDay(day)}>{day}</button> : <span key={`empty-${index}`} />)}</div>
      {withTime && <div className="time-picker-row"><span className="time-picker-label">{timeLabel}</span><input className="time-number-input" type="number" min="0" max="23" value={pad(view.hour)} onChange={(event) => updateTime("hour", Math.min(23, Math.max(0, Number(event.target.value || 0))))} aria-label="Години" /><b>:</b><input className="time-number-input" type="number" min="0" max="59" value={pad(view.minute)} onChange={(event) => updateTime("minute", Math.min(59, Math.max(0, Number(event.target.value || 0))))} aria-label="Хвилини" /></div>}
      <div className="date-time-actions"><button type="button" className="date-time-today" onClick={today}>Сьогодні</button><button type="button" className="date-time-done" onClick={() => setOpen(false)}>Готово</button></div>
    </div>, document.body)}
  </div>;
}
