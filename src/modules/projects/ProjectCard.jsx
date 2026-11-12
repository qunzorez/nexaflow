import { Link } from 'react-router-dom';

const statusLabels = { active: 'Активний', paused: 'Призупинений', completed: 'Завершений', draft: 'Чернетка' };
const roleLabels = { owner: 'Власник', admin: 'Адміністратор', manager: 'Менеджер', member: 'Учасник', viewer: 'Переглядач' };

export default function ProjectCard({ project }) {
  const status = project.status || 'active';
  return <Link className="project-card" to={`/projects/${project.id}`}><div className="project-card-top"><span className="project-icon">▣</span><span className={`project-status ${status}`}>{statusLabels[status] || status}</span></div><h2>{project.name}</h2><p>{project.description || 'Опис проєкту ще не додано.'}</p><div className="project-card-footer"><span>Власник: {project.owner_name}</span>{project.project_role && <b>{roleLabels[project.project_role] || project.project_role}</b>}<span className="project-open">Відкрити →</span></div></Link>;
}
