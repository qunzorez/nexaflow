import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { faShieldHalved, faClock, faRotate, faChevronRight, faLock, faArrowRight } from '@fortawesome/free-solid-svg-icons';

const definitions = {
  shield: faShieldHalved,
  clock: faClock,
  refresh: faRotate,
  next: faChevronRight,
  lock: faLock,
  arrow: faArrowRight,
};

const entries = Object.entries(definitions).map(([name, definition]) => {
  const [width, height, , , path] = definition.icon;
  const paths = (Array.isArray(path) ? path : [path]).map(value => `<path fill="currentColor" d="${value}"/>`).join('');
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" aria-hidden="true" focusable="false">${paths}</svg>`;
  return `  '${name}' => ${JSON.stringify(svg)},`;
});

const output = `<?php\ndeclare(strict_types=1);\n// Generated from @fortawesome/free-solid-svg-icons. Do not edit by hand.\nreturn [\n${entries.join('\n')}\n];\n`;
writeFileSync(fileURLToPath(new URL('../api/maintenance-icons.php', import.meta.url)), output);
