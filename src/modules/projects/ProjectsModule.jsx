import { Link } from 'react-router-dom';
import ProjectCard from './ProjectCard.jsx';
import './projects.css';
import './projects-empty-fix.css';

const canCreate = role => ['admin', 'owner', 'manager'].includes(role);

export default function ProjectsModule({ user, projects, onCreated }) {
  const isManager = canCreate(user?.role);
  return <section className="projects-module"><section className="welcome-row"><div><p className="eyebrow">РОБОЧИЙ ПРОСТІР</p><h1>Проєкти</h1><p className="subtext">Керуйте проєктами, командами та доступами в одному місці.</p></div>{isManager && <Link className="primary-action" to="/projects/new">＋ Створити проєкт</Link>}</section><section className="project-toolbar"><strong>{projects.length} проєктів</strong><span>{isManager ? 'Ви можете створювати та запрошувати учасників.' : 'Ви бачите лише проєкти, до яких маєте доступ.'}</span></section>{projects.length ? <section className="project-cards">{projects.map(project => <ProjectCard key={project.id} project={project} />)}</section> : <section className="panel projects-empty"><div className="empty-illustration">▣</div><h2>Проєктів ще немає</h2><p>{isManager ? 'Створіть перший проєкт і запросіть до нього команду.' : 'Менеджер або адміністратор ще не додав вас до проєкту.'}</p>{isManager && <Link className="outline-btn" to="/projects/new">＋ Створити проєкт</Link>}</section>}</section>;
}
