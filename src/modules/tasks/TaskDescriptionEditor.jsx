import { useEffect, useRef } from 'react';
import DOMPurify from 'dompurify';

export default function TaskDescriptionEditor({ value = '', onChange }) {
  const editorRef = useRef(null);
  useEffect(() => { if (editorRef.current && editorRef.current.innerHTML !== value) editorRef.current.innerHTML = DOMPurify.sanitize(value, { USE_PROFILES: { html: true } }); }, [value]);
  function update() { onChange(editorRef.current?.innerHTML || ''); }
  function command(name) { editorRef.current?.focus(); document.execCommand(name, false); update(); }
  function insertFile(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file || !editorRef.current) return;
    if (file.size > 10 * 1024 * 1024) { window.alert('Файл завеликий. Максимальний розмір — 10 МБ.'); return; }
    const safeName = file.name.replace(/[<>"']/g, '');
    const reader = new FileReader();
    reader.onload = () => {
      const insert = (dataUrl) => {
        editorRef.current.focus();
        const html = file.type.startsWith('image/')
          ? `<img src="${dataUrl}" alt="${safeName}">`
          : `<p><a href="${dataUrl}" download="${safeName}">📎 ${safeName}</a></p>`;
        document.execCommand('insertHTML', false, html);
        update();
      };
      if (!file.type.startsWith('image/')) return insert(reader.result);
      const image = new Image();
      image.onload = () => {
        const maxSide = 1600;
        const scale = Math.min(1, maxSide / Math.max(image.naturalWidth, image.naturalHeight));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
        canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
        insert(canvas.toDataURL('image/jpeg', 0.78));
      };
      image.onerror = () => insert(reader.result);
      image.src = reader.result;
    };
    reader.readAsDataURL(file);
  }
  return <div className="task-editor"><div className="task-editor-toolbar"><button type="button" onClick={() => command('bold')}><b>B</b></button><button type="button" onClick={() => command('italic')}><i>I</i></button><button type="button" onClick={() => command('insertUnorderedList')}>• Список</button><button type="button" onClick={() => document.getElementById('task-description-file').click()}>＋ Фото або файл</button><input id="task-description-file" className="task-description-file" type="file" hidden accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt" onChange={insertFile} /></div><div ref={editorRef} className="task-editor-area" contentEditable suppressContentEditableWarning onInput={update} data-placeholder="Опишіть задачу, результат і важливі деталі..." /><input type="hidden" name="description" value={value} readOnly /></div>;
}
