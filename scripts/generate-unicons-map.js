#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

const rootDir = path.resolve(__dirname, '..');
const uniconsDir = path.join(rootDir, 'node_modules', '@iconscout', 'unicons');
const dataFile = path.join(uniconsDir, 'json', 'line.json');
const outFile = path.join(rootDir, 'public', 'assets', 'js', 'unicons-map.js');

if (!fs.existsSync(dataFile)) {
  console.error('No se encontro:', dataFile);
  console.error('Instala @iconscout/unicons primero con: npm install @iconscout/unicons');
  process.exit(1);
}

const iconData = JSON.parse(fs.readFileSync(dataFile, 'utf8'));
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
  if (name.includes('arrow') || name.includes('chevron') || name.includes('corner') || name.includes('bracket') || name.includes('direction')) return 'general';
  if (name.includes('shopping') || name.includes('cart') || name.includes('receipt') || name.includes('tag') || name.includes('barcode') || name.includes('qr') || name.includes('buy') || name.includes('sale')) return 'ventas';
  if (name.includes('chart') || name.includes('graph') || name.includes('trending') || name.includes('coin') || name.includes('wallet') || name.includes('credit') || name.includes('percent') || name.includes('money') || name.includes('dollar') || name.includes('euro') || name.includes('yen')) return 'finanzas';
  if (name.includes('package') || name.includes('box') || name.includes('truck') || name.includes('warehouse') || name.includes('layout') || name.includes('grid') || name.includes('inbox') || name.includes('storage')) return 'inventario';
  if (name.includes('user') || name.includes('profile') || name.includes('account') || name.includes('person') || name.includes('male') || name.includes('female') || name.includes('group') || name.includes('team')) return 'usuarios';
  if (name.includes('shield') || name.includes('lock') || name.includes('key') || name.includes('eye') || name.includes('checkbox') || name.includes('alert') || name.includes('warning') || name.includes('security') || name.includes('verified') || name.includes('check')) return 'sistema';
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

  // Validar path data
  const pathMatch = svgContent.match(/d="([^"]*)"/);
  if (pathMatch) {
    const pathData = pathMatch[1];

    // Detectar comandos incompletos al final (m, l, h, v, c, s, q, t, a sin suficientes parámetros)
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

for (const iconEntry of iconData) {
  const iconName = String(iconEntry.name || '').trim();
  const svgPath = path.join(uniconsDir, iconEntry.svg || '');

  if (!fs.existsSync(svgPath)) {
    skipped++;
    continue;
  }

  try {
    const svgContent = fs.readFileSync(svgPath, 'utf8').trim();

    if (!isValidSvg(svgContent)) {
      skipped++;
      continue;
    }

    map[iconName] = svgContent;
    options.push({
      name: toTitle(iconName),
      class: iconName,
      category: inferCategory(iconName),
      source: 'unicons'
    });
    processed++;
  } catch (e) {
    skipped++;
  }
}

options.sort((a, b) => a.name.localeCompare(b.name, 'es'));

const output =
  '/* Auto-generated from @iconscout/unicons (line style) */\n' +
  'window.UNICONS_MAP = ' + JSON.stringify(map) + ';\n' +
  'window.UNICONS_OPTIONS = ' + JSON.stringify(options) + ';\n';

fs.writeFileSync(outFile, output);
console.log(`Generado ${outFile} con ${processed} iconos Unicons (${skipped} skipped).`);
