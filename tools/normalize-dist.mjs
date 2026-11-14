import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { extname, join } from 'node:path';

const textExtensions = new Set(['.css', '.html', '.js', '.json', '.svg', '.txt', '.xml']);

function normalizeDirectory(directory) {
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) {
      normalizeDirectory(path);
    } else if (textExtensions.has(extname(entry.name).toLowerCase())) {
      const source = readFileSync(path, 'utf8');
      const normalized = source.replace(/\r\n?/g, '\n');
      if (normalized !== source) writeFileSync(path, normalized, 'utf8');
    }
  }
}

normalizeDirectory('dist');
