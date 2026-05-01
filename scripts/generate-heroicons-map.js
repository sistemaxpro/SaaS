#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

const rootDir = path.resolve(__dirname, '..');
const sourceDir = path.join(rootDir, 'node_modules', 'heroicons', '24', 'outline');
const outFile = path.join(rootDir, 'public', 'assets', 'js', 'heroicons-outline-map.js');

if (!fs.existsSync(sourceDir)) {
  console.error('No se encontro el directorio:', sourceDir);
  console.error('Instala dependencias primero con: npm install');
  process.exit(1);
}

const files = fs.readdirSync(sourceDir).filter((f) => f.endsWith('.svg')).sort();
const map = {};
const options = [];

function toTitle(slug) {
  return slug
    .split('-')
    .map((w) => (w ? w.charAt(0).toUpperCase() + w.slice(1) : w))
    .join(' ');
}

function isValidSvg(svgContent) {
  if (!svgContent || typeof svgContent !== 'string') return false;
  // Detectar el patrón específico problemático: número negativo con doble decimal
  if (/-\.\d+\.\d+/.test(svgContent)) return false;
  // Detectar paths inválidos obvios
  if (svgContent.includes('NaN') || svgContent.includes('Infinity')) return false;
  return true;
}

let processed = 0;
let skipped = 0;

for (const file of files) {
  const iconName = file.replace(/\.svg$/, '');
  const content = fs
    .readFileSync(path.join(sourceDir, file), 'utf8')
    .trim()
    .replace(/\r?\n|\t/g, ' ')
    .replace(/\s{2,}/g, ' ');

  if (!isValidSvg(content)) {
    console.warn(`⚠️  SVG inválido ignorado: ${iconName}`);
    skipped++;
    continue;
  }

  map[iconName] = content;
  options.push({
    name: toTitle(iconName),
    class: iconName,
    category: 'general',
    source: 'heroicon'
  });
  processed++;
}

const output =
  '/* Auto-generated from node_modules/heroicons/24/outline (con validación SVG) */\n' +
  'window.HEROICONS_OUTLINE_MAP = ' + JSON.stringify(map) + ';\n' +
  'window.HEROICONS_OUTLINE_OPTIONS = ' + JSON.stringify(options) + ';\n';

fs.writeFileSync(outFile, output);
console.log(`Generado ${outFile} con ${processed} iconos válidos (${skipped} inválidos descartados).`);
