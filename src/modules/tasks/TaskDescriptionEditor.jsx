import { useEffect, useRef } from 'react';
import DOMPurify from 'dompurify';

export default function TaskDescriptionEditor({ value = '', onChange }) {
  const editorRef = useRef(null);
  useEffect(() => { if (editorRef.current && editorRef.current.innerHTML !== value) editorRef.current.innerHTML = DOMPurify.sanitize(value, { USE_PROFILES: { html: true } }); }, [value]);
  function update() { onChange(editorRef.current?.innerHTML || ''); }
  function command(name) { editorRef.current?.focus(); document.execCommand(name, false); update(); }
  function insertFile(event) { const file = event.target.files?.[0]; event.target.value = ''; if (!file || !editorRef.current) return; if (file.size > 5 * 1024 * 1024) { window.alert('Файл завеликий. Максимальний розмір — 5 МБ.'); return; } const reader = new FileReader(); reader.onload = () => { editorRef.current.focus(); const safeName = file.name.replace(/[<>"']/g, ''); const html = file.type.startsWith('image/') ? `<img src="${reader.result}" alt="${safeName}">` : `<p><a href="${reader.result}" download="${safeName}">📎 ${safeName}</a></p>`; document.execCommand('insertHTML', false, html); update(); }; reader.readAsDataURL(file); }
  return <div className="task-editor"><div className="task-editor-toolbar"><button type="button" onClick={() => command('bold')}><b>B</b></button><button type="button" onClick={() => command('italic')}><i>I</i></button><button type="button" onClick={() => command('insertUnorderedList')}>• Список</button><button type="button" onClick={() => document.getElementById('task-description-file').click()}>＋ Фото або файл</button><input id="task-description-file" className="task-description-file" type="file" hidden accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt" onChange={insertFile} /></div><div ref={editorRef} className="task-editor-area" contentEditable suppressContentEditableWarning onInput={update} data-placeholder="Опишіть задачу, результат і важливі деталі..." /><input type="hidden" name="description" value={value} readOnly /></div>;
}
