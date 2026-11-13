import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
  faHouse, faUsers, faUser, faFolderOpen, faListCheck, faBell,
  faCalendarDays, faClock, faFileLines, faChartLine, faRightFromBracket,
  faGear, faPlus, faXmark, faCheck, faMagnifyingGlass, faChevronDown,
  faChevronLeft, faChevronRight, faArrowLeft, faArrowRight, faArrowUp,
  faArrowDown, faMinus, faExclamation, faCircle, faCircleHalfStroke,
  faBoxArchive, faPenToSquare, faWandMagicSparkles, faShieldHalved,
  faGem, faAddressBook, faHourglassHalf, faRotate, faPaperclip,
  faEye, faEyeSlash, faBold, faItalic, faListUl, faSpinner, faCircleQuestion,
} from '@fortawesome/free-solid-svg-icons';
import './icons.css';

const icons = {
  home: faHouse, users: faUsers, user: faUser, project: faFolderOpen,
  tasks: faListCheck, bell: faBell, calendar: faCalendarDays, clock: faClock,
  document: faFileLines, chart: faChartLine, logout: faRightFromBracket,
  settings: faGear, plus: faPlus, close: faXmark, check: faCheck,
  search: faMagnifyingGlass, down: faChevronDown, left: faChevronLeft,
  right: faChevronRight, 'chevron-left': faChevronLeft, 'chevron-right': faChevronRight,
  back: faArrowLeft, forward: faArrowRight,
  high: faArrowUp, low: faArrowDown, normal: faMinus, urgent: faExclamation,
  todo: faCircle, progress: faCircleHalfStroke, archive: faBoxArchive,
  edit: faPenToSquare, sparkle: faWandMagicSparkles, security: faShieldHalved,
  subscription: faGem, contact: faAddressBook, hourglass: faHourglassHalf,
  refresh: faRotate, attachment: faPaperclip, eye: faEye, eyeSlash: faEyeSlash,
  bold: faBold, italic: faItalic, list: faListUl, loading: faSpinner,
  question: faCircleQuestion, 'circle-question': faCircleQuestion,
};

export default function Icon({ name, className = '' }) {
  return <span className={`nf-icon ${className}`} aria-hidden="true"><FontAwesomeIcon icon={icons[name] || faCircle} /></span>;
}
