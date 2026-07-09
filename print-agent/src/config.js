import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const rootDir = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const configPath = path.join(rootDir, 'config.json');

const defaults = {
  port: 9100,
  printer: {
    type: 'epson',
    interface: 'printer:auto',
    charactersPerLine: 42,
  },
  fbrLogoPath: path.join(rootDir, 'assets', 'fbr-pos-logo.png'),
};

function loadFileConfig() {
  if (!existsSync(configPath)) {
    return {};
  }
  try {
    return JSON.parse(readFileSync(configPath, 'utf8'));
  } catch (err) {
    console.error(`Failed to parse config.json: ${err.message}. Falling back to defaults.`);
    return {};
  }
}

const fileConfig = loadFileConfig();

export const config = {
  port: Number(process.env.PRINT_AGENT_PORT ?? fileConfig.port ?? defaults.port),
  printer: {
    type: process.env.PRINTER_TYPE ?? fileConfig.printer?.type ?? defaults.printer.type,
    interface: process.env.PRINTER_INTERFACE ?? fileConfig.printer?.interface ?? defaults.printer.interface,
    charactersPerLine: Number(
      process.env.PRINTER_CHARS_PER_LINE ?? fileConfig.printer?.charactersPerLine ?? defaults.printer.charactersPerLine,
    ),
  },
  fbrLogoPath: fileConfig.fbrLogoPath
    ? path.resolve(rootDir, fileConfig.fbrLogoPath)
    : defaults.fbrLogoPath,
};

if (!existsSync(configPath)) {
  console.warn(
    `No config.json found at ${configPath} - using defaults (printer.interface="printer:auto"). ` +
    'Copy config.example.json to config.json and set your printer connection details.',
  );
}
