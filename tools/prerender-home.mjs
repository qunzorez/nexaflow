import { createServer } from 'vite';
import { readFileSync, writeFileSync } from 'node:fs';
import React from 'react';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';

const server = await createServer({ server: { middlewareMode: true }, appType: 'custom' });
try {
  const { default: Landing } = await server.ssrLoadModule('/src/pages/Landing.jsx');
  const html = renderToString(React.createElement(MemoryRouter, { initialEntries: ['/'] }, React.createElement(Landing))).replace(/class="(landing-hero|landing-section|landing-cta)([^"]*)"/g, 'class="$1$2 section-visible"');
  const path = 'dist/index.html';
  const template = readFileSync(path, 'utf8');
  if (!template.includes('<div id="root"></div>')) throw new Error('Missing root for homepage prerender');
  writeFileSync(path, template.replace('<div id="root"></div>', () => `<div id="root">${html}</div>`));
  console.log('Homepage HTML prerendered from Landing.jsx');
} finally {
  await server.close();
}
