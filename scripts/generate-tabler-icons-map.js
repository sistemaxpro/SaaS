#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

const rootDir = path.resolve(__dirname, '..');
const sourceDir = path.join(rootDir, 'node_modules', 'tabler-icons', 'icons');
const outFile = path.join(rootDir, 'public', 'assets', 'js', 'tabler-icons-map.js');

if (!fs.existsSync(sourceDir)) {
  console.error('No se encontro el directorio:', sourceDir);
  console.error('Instala tabler-icons primero con: npm install tabler-icons');
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

function inferCategory(name) {
  name = name.toLowerCase();
  if (name.includes('arrow') || name.includes('chevron') || name.includes('corner')) return 'general';
  if (name.includes('shopping') || name.includes('cart') || name.includes('receipt') || name.includes('tag') || name.includes('barcode') || name.includes('qrcode')) return 'ventas';
  if (name.includes('chart') || name.includes('graph') || name.includes('trending') || name.includes('coins') || name.includes('wallet') || name.includes('credit') || name.includes('percent') || name.includes('cash')) return 'finanzas';
  if (name.includes('package') || name.includes('box') || name.includes('truck') || name.includes('warehouse') || name.includes('layout') || name.includes('grid')) return 'inventario';
  if (name.includes('user') || name.includes('users') || name.includes('profile') || name.includes('account') || name.includes('person')) return 'usuarios';
  if (name.includes('shield') || name.includes('lock') || name.includes('key') || name.includes('eye') || name.includes('checkbox') || name.includes('alert')) return 'sistema';
  return 'general';
}

function isValidSvg(svgContent) {
  if (!svgContent || typeof svgContent !== 'string') return false;

  // Detectar números malformados con múltiples puntos decimales (ej: -.128.87)
  if (/[\d]\.\d+\.\d+|[\s\-]\.\d+\.|\.\d+\./.test(svgContent)) return false;

  // Detectar paths incompletos o valores inválidos
  if (svgContent.includes('NaN') || svgContent.includes('Infinity')) return false;

  // Validación básica: debe tener tags svg
  if (!svgContent.includes('<svg') || !svgContent.includes('</svg>')) return false;

  // Validar path data si existe
  const pathMatch = svgContent.match(/d="([^"]*)"/);
  if (pathMatch) {
    const pathData = pathMatch[1];

    // Detectar comandos incompletos al final
    if (/[mlhvcsqtaz]\s*-?\d+(?:\s|$)/i.test(pathData.slice(-20))) {
      const lastCommand = pathData.match(/([mlhvcsqtaz])[^mlhvcsqtaz]*$/i);
      if (lastCommand) {
        const cmd = lastCommand[1].toLowerCase();
        const afterCmd = pathData.slice(pathData.lastIndexOf(cmd) + 1).trim();

        // Validar cantidad de parámetros según el comando
        const paramCounts = {
          m: [2, 2], l: [2, 2], h: [1, 1], v: [1, 1],
          c: [6, 6], s: [4, 4], q: [4, 4], t: [2, 2],
          a: [7, 7], z: [0, 0]
        };

        if (paramCounts[cmd]) {
          const params = afterCmd.split(/[\s,]+/).filter(p => p && !isNaN(p));
          if (params.length > 0 && params.length < paramCounts[cmd][0]) {
            return false;
          }
        }
      }
    }

    // Detectar rutas incompletas que terminan con números sueltos
    if (/\s\d+\s*$/.test(pathData) && pathData.split(/\s+/).pop().match(/^\d+$/)) {
      return false;
    }
  }

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

  // Validar SVG antes de incluir
  if (!isValidSvg(content)) {
    console.warn(`⚠️  SVG inválido ignorado: ${iconName}`);
    skipped++;
    continue;
  }

  map[iconName] = content;
  options.push({
    name: toTitle(iconName),
    class: iconName,
    category: inferCategory(iconName),
    source: 'tabler'
  });
  processed++;
}

const output =
  '/* Auto-generated from node_modules/tabler-icons (con validación SVG) */\n' +
  'window.TABLER_ICONS_MAP = ' + JSON.stringify(map) + ';\n' +
  'window.TABLER_ICONS_OPTIONS = ' + JSON.stringify(options) + ';\n';

fs.writeFileSync(outFile, output);
console.log(`Generado ${outFile} con ${processed} iconos Tabler válidos (${skipped} inválidos descartados).`);
