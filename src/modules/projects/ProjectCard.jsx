import { Link } from 'react-router-dom';

export default function ProjectCard({ project }) {
  return <Link className="project-card" to={`/projects/${project.id}`}><div className="project-card-top"><span className="project-icon">▣</span><span className="project-status">Активний</span></div><h2>{project.name}</h2><p>{project.description || 'Опис проєкту ще не додано.'}</p><div className="project-card-footer"><span>Власник: {project.owner_name}</span>{project.project_role && <b>{project.project_role}</b>}<span className="project-open">Відкрити →</span></div></Link>;
}
