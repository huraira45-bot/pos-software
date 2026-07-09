import { printer as ThermalPrinter, types as PrinterTypes } from 'node-thermal-printer';
import QRCode from 'qrcode';
import { existsSync } from 'node:fs';
import { unlink } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { config } from './config.js';

const TYPE_MAP = {
  epson: PrinterTypes.EPSON,
  star: PrinterTypes.STAR,
};

/**
 * Only a `printer:NAME` interface (OS print-spooler, typical for a USB
 * thermal printer on Windows/Linux/macOS) needs a driver - it's the optional
 * native `printer` package, not installed by default so that deployments
 * using a network printer (interface: "tcp://<ip>", no OS spooler involved)
 * never need node-gyp/build tools on the till at all.
 */
async function loadDriverIfNeeded() {
  if (!config.printer.interface.startsWith('printer:')) {
    return undefined;
  }
  try {
    const { default: printerDriver } = await import('printer');
    return printerDriver;
  } catch {
    throw new Error(
      'printer.interface is set to an OS-spooler printer ("printer:...") but the optional ' +
      '"printer" package is not installed. Run `npm install printer` in print-agent/ (requires ' +
      'native build tools), or switch config.json to a network interface like "tcp://<printer-ip>" instead.',
    );
  }
}

async function createPrinter() {
  const driver = await loadDriverIfNeeded();
  return new ThermalPrinter({
    type: TYPE_MAP[config.printer.type] ?? PrinterTypes.EPSON,
    interface: config.printer.interface,
    width: config.printer.charactersPerLine,
    removeSpecialCharacters: false,
    driver,
  });
}

/**
 * Builds and prints one receipt from the JSON payload the Laravel backend's
 * ReceiptController returns (GET /api/sales/{invoice}/receipt). This is the
 * one place that renders the FBR QR code and logo onto the physical receipt,
 * per the architecture: the backend only ever hands over structured data,
 * never a rendered image or ESC/POS bytes.
 */
export async function printReceipt(receiptData) {
  const printerInstance = await createPrinter();

  const isConnected = await printerInstance.isPrinterConnected().catch(() => false);
  if (!isConnected) {
    throw new Error(`Printer not reachable at interface "${config.printer.interface}".`);
  }

  const { business, invoice, items, totals, fiscal, footer } = receiptData;

  printerInstance.alignCenter();
  printerInstance.bold(true);
  printerInstance.setTextSize(1, 1);
  printerInstance.println(business.name);
  printerInstance.bold(false);
  printerInstance.setTextSize(0, 0);
  printerInstance.println(business.branch_name);
  printerInstance.println(business.address);
  printerInstance.println(`NTN: ${business.ntn}${business.strn ? ` | STRN: ${business.strn}` : ''}`);
  printerInstance.println(business.tax_office_name);
  printerInstance.println(`POS Registration No: ${business.pos_registration_number}`);
  printerInstance.drawLine();

  printerInstance.alignLeft();
  printerInstance.println(`${invoice.is_credit ? 'Credit Note' : 'Invoice'} #: ${invoice.usin}`);
  if (invoice.is_credit && invoice.ref_usin) {
    printerInstance.println(`Ref. Invoice USIN: ${invoice.ref_usin}`);
  }
  printerInstance.println(invoice.date_time_display);
  printerInstance.println(`Payment: ${invoice.payment_mode_label}`);
  if (invoice.buyer_name) {
    printerInstance.println(`Buyer: ${invoice.buyer_name}${invoice.buyer_ntn ? ` (NTN: ${invoice.buyer_ntn})` : ''}`);
  }
  if (invoice.cashier_name) {
    printerInstance.println(`Cashier: ${invoice.cashier_name}`);
  }
  printerInstance.drawLine();

  printerInstance.tableCustom([
    { text: 'Item', align: 'LEFT', width: 0.4 },
    { text: 'Qty', align: 'RIGHT', width: 0.15 },
    { text: 'Price', align: 'RIGHT', width: 0.2 },
    { text: 'Total', align: 'RIGHT', width: 0.25 },
  ]);
  for (const item of items) {
    printerInstance.tableCustom([
      { text: item.name, align: 'LEFT', width: 0.4 },
      { text: trimTrailingZeros(item.quantity), align: 'RIGHT', width: 0.15 },
      { text: item.unit_price_excl_tax, align: 'RIGHT', width: 0.2 },
      { text: item.total_amount, align: 'RIGHT', width: 0.25 },
    ]);
  }
  printerInstance.drawLine();

  printLineItem(printerInstance, 'Sale Value (excl. tax)', totals.total_sale_value, footer.currency_symbol);
  printLineItem(printerInstance, 'Tax Charged', totals.total_tax_charged, footer.currency_symbol);
  if (Number(totals.discount) > 0) {
    printLineItem(printerInstance, 'Discount', `-${totals.discount}`, footer.currency_symbol);
  }
  if (Number(totals.further_tax) > 0) {
    printLineItem(printerInstance, 'Further Tax', totals.further_tax, footer.currency_symbol);
  }
  printerInstance.bold(true);
  printLineItem(printerInstance, 'Total Bill Amount', totals.total_bill_amount, footer.currency_symbol);
  printerInstance.bold(false);
  printerInstance.drawLine();

  printerInstance.alignCenter();
  if (fiscal.is_pending) {
    printerInstance.bold(true);
    printerInstance.println('*** FBR SYNC PENDING ***');
    printerInstance.bold(false);
    printerInstance.println('Reprint after sync for the FBR invoice number and QR code.');
  } else {
    printerInstance.bold(true);
    printerInstance.println(`FBR Invoice No: ${fiscal.fbr_invoice_number}`);
    printerInstance.bold(false);

    if (existsSync(config.fbrLogoPath)) {
      await printerInstance.printImage(config.fbrLogoPath).catch((err) => {
        console.error('Failed to print FBR logo (continuing without it):', err.message);
      });
    }

    if (fiscal.qr_payload) {
      const qrPngPath = await renderQrToTempFile(fiscal.qr_payload);
      await printerInstance.printImage(qrPngPath).catch((err) => {
        console.error('Failed to print FBR QR code (continuing without it):', err.message);
      });
      // Printing is done with the temp file now - a busy till would otherwise
      // accumulate one PNG per receipt in the OS temp dir forever.
      await unlink(qrPngPath).catch(() => {});
    }
  }

  printerInstance.newLine();
  printerInstance.println(footer.fbr_statement);
  printerInstance.cut();

  await printerInstance.execute();
}

function printLineItem(printerInstance, label, amount, currencySymbol) {
  printerInstance.tableCustom([
    { text: label, align: 'LEFT', width: 0.6 },
    { text: `${currencySymbol}${amount}`, align: 'RIGHT', width: 0.4 },
  ]);
}

function trimTrailingZeros(numericString) {
  return String(Number(numericString));
}

async function renderQrToTempFile(payload) {
  const filePath = path.join(tmpdir(), `fbr-qr-${Date.now()}.png`);
  await QRCode.toFile(filePath, payload, { width: 200, margin: 1 });
  return filePath;
}
