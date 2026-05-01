#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

const rootDir = path.resolve(__dirname, '..');
const sourceFile = path.join(rootDir, 'node_modules', '@iconify-json', 'hugeicons', 'icons.json');
const outFile = path.join(rootDir, 'public', 'assets', 'js', 'hugeicons-map.js');

if (!fs.existsSync(sourceFile)) {
  console.error('No se encontro:', sourceFile);
  console.error('Instala @iconify-json/hugeicons primero con: npm install @iconify-json/hugeicons');
  process.exit(1);
}

const iconData = JSON.parse(fs.readFileSync(sourceFile, 'utf8'));
const icons = iconData.icons || {};
const map = {};
const options = [];

function toTitle(slug) {
  return slug
    .split('-')
    .map((w) => (w ? w.charAt(0).toUpperCase() + w.slice(1) : w))
    .join(' ');
}

function inferCategory(name) {
  name = name.toLowerCase();
  if (name.includes('arrow') || name.includes('chevron') || name.includes('corner') || name.includes('bracket')) return 'general';
  if (name.includes('shopping') || name.includes('cart') || name.includes('receipt') || name.includes('tag') || name.includes('barcode') || name.includes('qr')) return 'ventas';
  if (name.includes('chart') || name.includes('graph') || name.includes('trending') || name.includes('coin') || name.includes('wallet') || name.includes('credit') || name.includes('percent') || name.includes('money')) return 'finanzas';
  if (name.includes('package') || name.includes('box') || name.includes('truck') || name.includes('warehouse') || name.includes('layout') || name.includes('grid') || name.includes('inbox')) return 'inventario';
  if (name.includes('user') || name.includes('profile') || name.includes('account') || name.includes('person') || name.includes('male') || name.includes('female')) return 'usuarios';
  if (name.includes('shield') || name.includes('lock') || name.includes('key') || name.includes('eye') || name.includes('checkbox') || name.includes('alert') || name.includes('warning') || name.includes('security')) return 'sistema';
  return 'general';
}

function isValidSvgBody(body) {
  if (!body || typeof body !== 'string') return false;

  // Detectar el patrón específico problemático: número negativo con doble decimal
  // Ej: -.128.87 en lugar de -.128 .87
  if (/-\.\d+\.\d+/.test(body)) return false;

  // Detectar paths inválidos obvios
  if (body.includes('NaN') || body.includes('Infinity')) return false;

  return true;
}

let processed = 0;
let skipped = 0;

for (const [iconName, iconData] of Object.entries(icons)) {
  const svgBody = iconData.body || '';

  // Validar SVG body antes de incluir
  if (!isValidSvgBody(svgBody)) {
    console.warn(`⚠️  SVG inválido ignorado: ${iconName}`);
    skipped++;
    continue;
  }

  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">${svgBody}</svg>`;

  map[iconName] = svg;
  options.push({
    name: toTitle(iconName),
    class: iconName,
    category: inferCategory(iconName),
    source: 'huge'
  });
  processed++;
}

options.sort((a, b) => a.name.localeCompare(b.name, 'es'));

const output =
  '/* Auto-generated from @iconify-json/hugeicons (con validación SVG) */\n' +
  'window.HUGEICONS_MAP = ' + JSON.stringify(map) + ';\n' +
  'window.HUGEICONS_OPTIONS = ' + JSON.stringify(options) + ';\n';

fs.writeFileSync(outFile, output);
console.log(`Generado ${outFile} con ${processed} iconos Huge válidos (${skipped} inválidos descartados).`);
