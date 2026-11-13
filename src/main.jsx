import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import App from './App.jsx';
import './styles.css';
import './overrides.css';
import './dashboard-tasks.css';
import './header-create.css';
import './dashboard-time.css';
import './toasts.css';

createRoot(document.getElementById('root')).render(<BrowserRouter><App /></BrowserRouter>);
