# POS Print Agent

Small local service that runs on each till alongside the browser. It receives
a receipt as JSON from the checkout PWA, renders the FBR QR code and logo
itself, and drives the 80mm ESC/POS thermal printer directly - the Laravel
backend never sends rendered images or raw ESC/POS bytes, only structured
receipt data (see `App\Services\Receipt\ReceiptDataBuilder` in `backend/`).

## Setup

```bash
npm install
cp config.example.json config.json
```

Edit `config.json`:

- `printer.interface`: `"tcp://<printer-ip>"` for a network/WiFi printer
  (recommended - no extra native dependency needed), or `"printer:<name>"` to
  print through the OS print spooler for a USB printer (requires
  `npm install printer`, which needs native build tools - Python + a C++
  toolchain, or Visual Studio Build Tools on Windows).
- `printer.type`: `"epson"` or `"star"`, depending on the printer's command set.
- `fbrLogoPath`: replace `assets/fbr-pos-logo.png` with the real FBR POS
  invoicing system logo before going live - this repo ships a placeholder.

## Run

```bash
npm start
```

Listens on `http://localhost:9100` by default (`PRINT_AGENT_PORT` env var or
`port` in config.json to change it).

## Endpoints

- `GET /health` - reachability check the checkout PWA can use before offering
  a "print" action.
- `POST /print` - body is the JSON from `GET /api/sales/{invoice}/receipt` on
  the backend. Prints synchronously; responds `{"status":"printed"}` on
  success or `{"error": "..."}` with a 4xx/5xx on failure (unreachable
  printer, missing driver, malformed payload).

## Notes

- If an invoice hasn't synced to FBR yet (`fiscal.is_pending: true`), the
  receipt prints with a "FBR SYNC PENDING" banner instead of the fiscal
  number and QR code - reprint the same invoice once it syncs to get the
  compliant version with the QR code.
- Every attempt to `POST /print` is independent and stateless; this agent
  keeps no record of what it has printed. Reprint history/audit lives on the
  backend (`invoices.fiscal_status`, `synced_at`), not here.
