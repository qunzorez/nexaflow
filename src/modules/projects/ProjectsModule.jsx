import Icon from '../../components/Icon.jsx';
import { Link } from 'react-router-dom';
import { useEffect, useMemo, useState } from 'react';
import ProjectCard from './ProjectCard.jsx';
import './projects.css';
import './projects-empty-fix.css';

const canCreate = role => ['admin', 'owner', 'manager'].includes(role);

export default function ProjectsModule({ user, projects, onCreated }) {
  const isManager = canCreate(user?.role);
  const [search, setSearch] = useState(() => localStorage.getItem('nexaflow.projects.search') || '');
  const [status, setStatus] = useState(() => localStorage.getItem('nexaflow.projects.status') || 'all');
  const [visibility, setVisibility] = useState(() => localStorage.getItem('nexaflow.projects.visibility') || 'all');
  const [sort, setSort] = useState(() => localStorage.getItem('nexaflow.projects.sort') || 'date_desc');
  useEffect(() => { localStorage.setItem('nexaflow.projects.search', search); }, [search]);
  useEffect(() => { localStorage.setItem('nexaflow.projects.status', status); }, [status]);
  useEffect(() => { localStorage.setItem('nexaflow.projects.visibility', visibility); }, [visibility]);
  useEffect(() => { localStorage.setItem('nexaflow.projects.sort', sort); }, [sort]);
  const filteredProjects = useMemo(() => {
    const query = search.trim().toLowerCase();
    const filtered = (projects || []).filter(project => {
      const matchesSearch = !query || `${project.name || ''} ${project.description || ''} ${project.owner_name || ''}`.toLowerCase().includes(query);
      const matchesStatus = status === 'all' || (project.status || 'active') === status;
      const matchesVisibility = visibility === 'all' || (project.visibility || 'private') === visibility;
      return matchesSearch && matchesStatus && matchesVisibility;
    });
    return filtered.sort((left, right) => {
      if (sort === 'name_asc' || sort === 'name_desc') {
        const comparison = String(left.name || '').localeCompare(String(right.name || ''), 'uk');
        return sort === 'name_asc' ? comparison : -comparison;
      }
      const leftDate = new Date(left.created_at || 0).getTime();
      const rightDate = new Date(right.created_at || 0).getTime();
      return sort === 'date_asc' ? leftDate - rightDate : rightDate - leftDate;
    });
  }, [projects, search, status, visibility, sort]);
  const resetFilters = () => { setSearch(''); setStatus('all'); setVisibility('all'); setSort('date_desc'); };
  const statusLabel = { active: 'Активні', paused: 'Призупинені', completed: 'Завершені', draft: 'Чернетки' };
  return <section className="projects-module"><section className="welcome-row"><div><p className="eyebrow">РОБОЧИЙ ПРОСТІР</p><h1>Проєкти</h1><p className="subtext">Керуйте проєктами, командами та доступами в одному місці.</p></div>{isManager && <Link className="primary-action" to="/projects/new"><Icon name="plus" /> Створити проєкт</Link>}</section><section className="project-toolbar"><strong>{filteredProjects.length} з {projects.length} проєктів</strong><span>{isManager ? 'Ви можете створювати та запрошувати учасників.' : 'Ви бачите лише проєкти, до яких маєте доступ.'}</span></section><section className="project-filters"><label className="project-search"><Icon name="search" /><input type="search" value={search} onChange={event => setSearch(event.target.value)} placeholder="Пошук за назвою, описом або власником…" /></label><label><span>Статус</span><select value={status} onChange={event => setStatus(event.target.value)}><option value="all">Усі статуси</option>{Object.entries(statusLabel).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label><label><span>Видимість</span><select value={visibility} onChange={event => setVisibility(event.target.value)}><option value="all">Будь-яка</option><option value="private">Приватні</option><option value="team">Для команди</option></select></label><label><span>Сортування</span><select value={sort} onChange={event => setSort(event.target.value)}><option value="date_desc">Нові спочатку</option><option value="date_asc">Старі спочатку</option><option value="name_asc">Назва: А — Я</option><option value="name_desc">Назва: Я — А</option></select></label>{(search || status !== 'all' || visibility !== 'all' || sort !== 'date_desc') && <button type="button" className="filter-reset" onClick={resetFilters}>Скинути</button>}</section>{filteredProjects.length ? <section className="project-cards">{filteredProjects.map(project => <ProjectCard key={project.id} project={project} />)}</section> : <section className="panel projects-empty"><div className="empty-illustration"><Icon name="search" /></div><h2>{projects.length ? 'Нічого не знайдено' : 'Проєктів ще немає'}</h2><p>{projects.length ? 'Змініть запит або параметри фільтрації.' : (isManager ? 'Створіть перший проєкт і запросіть до нього команду.' : 'Менеджер або адміністратор ще не додав вас до проєкту.')}</p>{projects.length ? <button type="button" className="outline-btn" onClick={resetFilters}>Скинути фільтри</button> : isManager && <Link className="outline-btn" to="/projects/new"><Icon name="plus" /> Створити проєкт</Link>}</section>}</section>;
}
