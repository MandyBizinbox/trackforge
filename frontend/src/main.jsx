import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import App from './App.jsx';
import './styles.css';

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/1dqm/sw.js', {
      scope: '/1dqm/',
    });
  });
}

createRoot(document.getElementById('root')).render(
  <BrowserRouter basename="/1dqm">
    <App />
  </BrowserRouter>
);
