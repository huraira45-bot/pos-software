<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Invoice {{ $data['invoice']['usin'] }}</title>
<style>
    @page {
        margin: {{ $paperSize === '80mm' ? '2mm' : '15mm' }};
        size: {{ $paperSize === '80mm' ? '80mm 297mm' : 'A4 portrait' }};
    }
    body {
        font-family: "DejaVu Sans", sans-serif;
        font-size: {{ $paperSize === '80mm' ? '9px' : '12px' }};
        color: #000;
    }
    .center { text-align: center; }
    .right { text-align: right; }
    .bold { font-weight: bold; }
    .business-name { font-size: {{ $paperSize === '80mm' ? '13px' : '18px' }}; font-weight: bold; }
    hr { border: none; border-top: 1px dashed #000; margin: 4px 0; }
    table { width: 100%; border-collapse: collapse; }
    td, th { padding: 2px 0; vertical-align: top; }
    .items-table th { border-bottom: 1px solid #000; text-align: left; }
    .totals-table td { padding: 1px 0; }
    .pending-banner {
        border: 1px solid #000;
        padding: 4px;
        text-align: center;
        font-weight: bold;
        margin: 6px 0;
    }
    .qr-wrap { text-align: center; margin-top: 8px; }
    .footer-statement { font-size: {{ $paperSize === '80mm' ? '7px' : '10px' }}; text-align: center; margin-top: 6px; }
</style>
</head>
<body>

<div class="center">
    <div class="business-name">{{ $data['business']['name'] }}</div>
    <div>{{ $data['business']['branch_name'] }}</div>
    <div>{{ $data['business']['address'] }}</div>
    <div>NTN: {{ $data['business']['ntn'] }} @if($data['business']['strn']) | STRN: {{ $data['business']['strn'] }} @endif</div>
    <div>{{ $data['business']['tax_office_name'] }}</div>
    <div>POS Registration No: {{ $data['business']['pos_registration_number'] }}</div>
</div>

<hr>

<table>
    <tr>
        <td><span class="bold">{{ $data['invoice']['is_credit'] ? 'Credit Note' : 'Invoice' }} #:</span> {{ $data['invoice']['usin'] }}</td>
        <td class="right">{{ $data['invoice']['date_time_display'] }}</td>
    </tr>
    @if($data['invoice']['is_credit'] && $data['invoice']['ref_usin'])
    <tr><td colspan="2">Ref. Invoice USIN: {{ $data['invoice']['ref_usin'] }}</td></tr>
    @endif
    <tr><td colspan="2">Payment Mode: {{ $data['invoice']['payment_mode_label'] }}</td></tr>
    @if($data['invoice']['buyer_name'])
    <tr><td colspan="2">Buyer: {{ $data['invoice']['buyer_name'] }} @if($data['invoice']['buyer_ntn']) (NTN: {{ $data['invoice']['buyer_ntn'] }}) @endif</td></tr>
    @endif
    @if($data['invoice']['cashier_name'])
    <tr><td colspan="2">Cashier: {{ $data['invoice']['cashier_name'] }}</td></tr>
    @endif
</table>

<hr>

<table class="items-table">
    <thead>
        <tr>
            <th>Item</th>
            <th class="right">Qty</th>
            <th class="right">Price</th>
            <th class="right">Tax%</th>
            <th class="right">Total</th>
        </tr>
    </thead>
    <tbody>
    @foreach($data['items'] as $item)
        <tr>
            <td>{{ $item['name'] }}</td>
            <td class="right">{{ rtrim(rtrim($item['quantity'], '0'), '.') ?: '0' }}</td>
            <td class="right">{{ $data['footer']['currency_symbol'] }}{{ $item['unit_price_excl_tax'] }}</td>
            <td class="right">{{ $item['tax_rate'] }}%</td>
            <td class="right">{{ $data['footer']['currency_symbol'] }}{{ $item['total_amount'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<hr>

<table class="totals-table">
    <tr><td>Sale Value (excl. tax)</td><td class="right">{{ $data['footer']['currency_symbol'] }}{{ $data['totals']['total_sale_value'] }}</td></tr>
    <tr><td>Tax Charged</td><td class="right">{{ $data['footer']['currency_symbol'] }}{{ $data['totals']['total_tax_charged'] }}</td></tr>
    @if(bccomp($data['totals']['discount'], '0', 2) > 0)
    <tr><td>Discount</td><td class="right">-{{ $data['footer']['currency_symbol'] }}{{ $data['totals']['discount'] }}</td></tr>
    @endif
    @if(bccomp($data['totals']['further_tax'], '0', 2) > 0)
    <tr><td>Further Tax</td><td class="right">{{ $data['footer']['currency_symbol'] }}{{ $data['totals']['further_tax'] }}</td></tr>
    @endif
    <tr class="bold"><td>Total Bill Amount</td><td class="right">{{ $data['footer']['currency_symbol'] }}{{ $data['totals']['total_bill_amount'] }}</td></tr>
</table>

<hr>

@if($data['fiscal']['is_pending'])
    <div class="pending-banner">FBR sync pending - reprint after sync for the official FBR invoice number and QR code.</div>
@else
    <div class="center bold">FBR Invoice No: {{ $data['fiscal']['fbr_invoice_number'] }}</div>
    <div class="qr-wrap">
        @if($logoDataUri)
            <img src="{{ $logoDataUri }}" style="height: 10mm;" alt="FBR POS logo">
        @endif
        @if($qrDataUri)
            <div><img src="{{ $qrDataUri }}" style="width: {{ $data['footer']['qr_size_mm'] }}mm; height: {{ $data['footer']['qr_size_mm'] }}mm;" alt="FBR verification QR"></div>
        @endif
    </div>
@endif

<div class="footer-statement">{{ $data['footer']['fbr_statement'] }}</div>

</body>
</html>
