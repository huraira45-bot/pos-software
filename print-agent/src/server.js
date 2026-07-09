import express from 'express';
import cors from 'cors';
import { config } from './config.js';
import { printReceipt } from './printer.js';

const app = express();
app.use(cors());
app.use(express.json({ limit: '2mb' }));

/**
 * Health check for the checkout PWA to confirm this till has a reachable
 * print agent before offering "print" as an option in the UI.
 */
app.get('/health', (_req, res) => {
  res.json({ status: 'ok', printer: { type: config.printer.type, interface: config.printer.interface } });
});

/**
 * Body: the exact JSON the backend's GET /api/sales/{invoice}/receipt returns
 * (see App\Services\Receipt\ReceiptDataBuilder on the Laravel side). This
 * agent renders the FBR QR code and logo itself and drives the ESC/POS
 * printer - the backend never sends rendered images or raw ESC/POS bytes.
 */
app.post('/print', async (req, res) => {
  const receiptData = req.body;

  if (!receiptData || !receiptData.business || !receiptData.items) {
    return res.status(400).json({ error: 'Request body does not look like a receipt payload.' });
  }

  try {
    await printReceipt(receiptData);
    res.json({ status: 'printed' });
  } catch (err) {
    console.error('Print failed:', err);
    res.status(502).json({ error: err.message });
  }
});

app.listen(config.port, () => {
  console.log(`POS print agent listening on http://localhost:${config.port}`);
  console.log(`Printer: type=${config.printer.type} interface=${config.printer.interface}`);
});
