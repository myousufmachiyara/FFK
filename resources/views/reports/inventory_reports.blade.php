@extends('layouts.app')
@section('title', 'Inventory Reports')

@section('content')
<style>
@media print { .no-print { display: none !important; } }
.ref-link { text-decoration: none; font-weight: 500; }
.ref-link:hover { text-decoration: underline; }
</style>

<div class="tabs">
    <ul class="nav nav-tabs card-header-tabs">
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'IL' ? 'active fw-bold' : '' }}"
               href="{{ request()->fullUrlWithQuery(['tab' => 'IL']) }}">Item Ledger</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'SR' ? 'active fw-bold' : '' }}"
               href="{{ request()->fullUrlWithQuery(['tab' => 'SR']) }}">Stock In Hand</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'IT' ? 'active fw-bold' : '' }}"
               href="{{ request()->fullUrlWithQuery(['tab' => 'IT']) }}">Stock In Transit</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'CIT' ? 'active fw-bold' : '' }}"
               href="{{ request()->fullUrlWithQuery(['tab' => 'CIT']) }}">Commission Goods In Transit</a>
        </li>
    </ul>

    <div class="tab-content pt-3">

        {{-- ITEM LEDGER --}}
        <div id="IL" class="tab-pane fade {{ $tab === 'IL' ? 'show active' : '' }}">
            <form method="GET" class="border p-3 bg-light rounded mb-3 no-print">
                <input type="hidden" name="tab" value="IL">
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="small fw-bold">Product <span class="text-danger">*</span></label>
                        <select name="item_id" class="form-select form-select-sm" required>
                            <option value="">-- Select Product --</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}"
                                    {{ request('item_id') == $product->id ? 'selected' : '' }}>
                                    {{ $product->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="small fw-bold">From</label>
                        <input type="date" name="from_date" value="{{ $from }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <label class="small fw-bold">To</label>
                        <input type="date" name="to_date" value="{{ $to }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="button" class="btn btn-danger btn-sm w-100"
                                onclick="exportPDF('il-table', 'Item Ledger', '{{ $from }} to {{ $to }}')">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                    </div>
                </div>
            </form>

            <p class="text-muted small no-print mb-2">
                <i class="fas fa-info-circle"></i> Purchases only appear once a Purchase Invoice reaches
                <strong>Received</strong> status, dated by the actual receiving date — not the order date.
            </p>

            <div id="il-table">
                <table class="table table-sm table-bordered">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th><th>Type</th><th>Reference</th>
                            <th class="text-end">Qty In</th>
                            <th class="text-end">Qty Out</th>
                            <th class="text-end">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                    @if (request('item_id'))
                        <tr class="table-info">
                            <td>{{ $from }}</td>
                            <td colspan="2" class="fw-bold">Opening Balance</td>
                            <td class="text-end">—</td><td class="text-end">—</td>
                            <td class="text-end fw-bold">{{ number_format($openingQty, 2) }}</td>
                        </tr>
                        @php $runningBalance = $openingQty; @endphp
                        @forelse ($itemLedger as $row)
                            @php
                                $qtyIn  = (float) $row['qty_in'];
                                $qtyOut = (float) $row['qty_out'];
                                $runningBalance += ($qtyIn - $qtyOut);
                                $desc = $row['description'];
                                $badgeClass = match ($row['type']) {
                                    'Purchase'        => 'bg-success',
                                    'Shortage'        => 'bg-dark',
                                    'Sale'            => 'bg-danger',
                                    'Purchase Return' => 'bg-warning text-dark',
                                    'Sale Return'     => 'bg-info text-dark',
                                    default           => 'bg-secondary',
                                };
                            @endphp
                            <tr>
                                <td>{{ $row['date'] }}</td>
                                <td><span class="badge {{ $badgeClass }}">{{ $row['type'] }}</span></td>
                                <td>
                                    @if (str_starts_with($desc, 'PI-'))
                                        @php $pid = (int) filter_var(explode(' ', $desc)[0], FILTER_SANITIZE_NUMBER_INT); @endphp
                                        {{-- description may be 'PI-000012' or 'PI-000012 (Shortage)' — pull the invoice number segment only --}}
                                        @php
                                            preg_match('/PI-(\d+)/', $desc, $m);
                                            $pid = $m[1] ?? null;
                                        @endphp
                                        @if ($pid)
                                            <a href="{{ route('purchase_invoices.show', $pid) }}"
                                               target="_blank" class="ref-link text-success">{{ $desc }}</a>
                                        @else
                                            {{ $desc }}
                                        @endif
                                    @elseif (str_starts_with($desc, 'SI-'))
                                        @php $sid = (int) str_replace('SI-', '', $desc); @endphp
                                        <a href="{{ route('sale_invoices.print', $sid) }}"
                                           target="_blank" class="ref-link text-primary">{{ $desc }}</a>
                                        <a href="{{ route('sale_invoices.edit', $sid) }}"
                                           class="ms-1 no-print text-secondary"><i class="fas fa-edit fa-xs"></i></a>
                                    @elseif (str_starts_with($desc, 'PR-'))
                                        <span class="text-warning">{{ $desc }}</span>
                                    @elseif (str_starts_with($desc, 'SR-'))
                                        <span class="text-info">{{ $desc }}</span>
                                    @else
                                        {{ $desc }}
                                    @endif
                                </td>
                                <td class="text-end text-success">{{ $qtyIn  > 0 ? number_format($qtyIn,  2) : '—' }}</td>
                                <td class="text-end text-danger">{{ $qtyOut > 0 ? number_format($qtyOut, 2) : '—' }}</td>
                                <td class="text-end fw-bold">{{ number_format($runningBalance, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center py-3 text-muted">No transactions in this period.</td></tr>
                        @endforelse
                        @if ($itemLedger->count() > 0)
                            <tr class="table-secondary fw-bold">
                                <td colspan="5" class="text-end">Closing Balance</td>
                                <td class="text-end">{{ number_format($runningBalance, 2) }}</td>
                            </tr>
                        @endif
                    @else
                        <tr><td colspan="6" class="text-center text-muted py-3">Please select a product to generate the ledger.</td></tr>
                    @endif
                    </tbody>
                </table>
            </div>
        </div>

        {{-- STOCK IN HAND --}}
        <div id="SR" class="tab-pane fade {{ $tab === 'SR' ? 'show active' : '' }}">
            <form method="GET" class="border p-3 bg-light rounded mb-3 no-print">
                <input type="hidden" name="tab" value="SR">
                <div class="row g-2">
                    <div class="col-md-5">
                        <label class="small fw-bold">Product (leave blank for all)</label>
                        <select name="item_id" class="form-select form-select-sm">
                            <option value="">-- All Products --</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}"
                                    {{ request('item_id') == $product->id ? 'selected' : '' }}>
                                    {{ $product->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-success btn-sm w-100">
                            <i class="fas fa-boxes"></i> Show Stock
                        </button>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="button" class="btn btn-danger btn-sm w-100"
                                onclick="exportPDF('sr-table', 'Stock In Hand', 'As of {{ now()->format('d-M-Y') }}')">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                    </div>
                </div>
            </form>

            <p class="text-muted small no-print mb-2">
                <i class="fas fa-info-circle"></i> Only <strong>Received</strong> purchases count toward stock here.
                Goods still Pending or In Transit appear on the <a href="{{ request()->fullUrlWithQuery(['tab' => 'IT']) }}">Stock In Transit</a> tab instead.
            </p>

            <div id="sr-table">
                <table class="table table-sm table-striped table-bordered align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Product</th><th>Variation (SKU)</th>
                            <th class="text-end">Current Stock</th><th>Unit</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($stockInHand as $stock)
                        <tr>
                            <td><strong>{{ $stock['product'] }}</strong></td>
                            <td>{{ $stock['variation'] }}</td>
                            <td class="text-end fw-bold {{ $stock['quantity'] <= 0 ? 'text-danger' : 'text-success' }}">
                                {{ number_format($stock['quantity'], 2) }}
                            </td>
                            <td>{{ $stock['unit'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center py-3 text-muted">No stock found. Click "Show Stock" to load.</td></tr>
                    @endforelse
                    </tbody>
                    @if ($stockInHand->isNotEmpty())
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="2" class="text-end">Total Units In Stock:</td>
                            <td class="text-end">{{ number_format($stockInHand->sum('quantity'), 2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- STOCK IN TRANSIT (Purchase) --}}
        <div id="IT" class="tab-pane fade {{ $tab === 'IT' ? 'show active' : '' }}">
            <form method="GET" class="border p-3 bg-light rounded mb-3 no-print">
                <input type="hidden" name="tab" value="IT">
                <div class="row g-2">
                    <div class="col-md-5">
                        <label class="small fw-bold">Product (leave blank for all)</label>
                        <select name="item_id" class="form-select form-select-sm">
                            <option value="">-- All Products --</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}"
                                    {{ request('item_id') == $product->id ? 'selected' : '' }}>
                                    {{ $product->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-warning btn-sm w-100">
                            <i class="fas fa-truck"></i> Show
                        </button>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="button" class="btn btn-danger btn-sm w-100"
                                onclick="exportPDF('it-table', 'Stock In Transit', 'As of {{ now()->format('d-M-Y') }}')">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                    </div>
                </div>
            </form>

            <p class="text-muted small no-print mb-2">
                <i class="fas fa-info-circle"></i> Goods dispatched by the Vendor but not yet physically received.
                Not counted in Stock In Hand — see the Purchase module's Inventory In Transit account for the accounting value.
            </p>

            <div id="it-table">
                <table class="table table-sm table-bordered">
                    <thead class="table-dark">
                        <tr>
                            <th>PI #</th><th>Date</th><th>Vendor</th><th>Vendor Bill #</th><th>Bilty #</th>
                            <th>Product</th><th>Variation</th>
                            <th class="text-end">Dispatched Qty</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($stockInTransit as $row)
                        <tr>
                            <td>
                                <a href="{{ route('purchase_invoices.show', $row->invoice_id) }}" target="_blank" class="ref-link text-warning">
                                    PI-{{ $row->invoice_no }}
                                </a>
                            </td>
                            <td>{{ \Carbon\Carbon::parse($row->invoice_date)->format('d-M-Y') }}</td>
                            <td>{{ $row->vendor_name }}</td>
                            <td>{{ $row->vendor_bill_no ?? '—' }}</td>
                            <td>{{ $row->bilty_no ?? '—' }}</td>
                            <td>{{ $row->product_name }}</td>
                            <td>{{ $row->variation_sku ?? '—' }}</td>
                            <td class="text-end">{{ number_format($row->dispatched_quantity, 2) }}</td>
                            <td class="text-end">{{ number_format($row->price, 2) }}</td>
                            <td class="text-end fw-bold">{{ number_format($row->dispatched_value, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center py-3 text-muted">Nothing currently In Transit.</td></tr>
                    @endforelse
                    </tbody>
                    @if ($stockInTransit->isNotEmpty())
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="9" class="text-end">Total In Transit Value:</td>
                            <td class="text-end">{{ number_format($stockInTransit->sum('dispatched_value'), 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- COMMISSION GOODS IN TRANSIT --}}
        <div id="CIT" class="tab-pane fade {{ $tab === 'CIT' ? 'show active' : '' }}">
            <form method="GET" class="border p-3 bg-light rounded mb-3 no-print">
                <input type="hidden" name="tab" value="CIT">
                <div class="row g-2">
                    <div class="col-md-5">
                        <label class="small fw-bold">Product (leave blank for all)</label>
                        <select name="item_id" class="form-select form-select-sm">
                            <option value="">-- All Products --</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}"
                                    {{ request('item_id') == $product->id ? 'selected' : '' }}>
                                    {{ $product->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-info btn-sm w-100 text-white">
                            <i class="fas fa-handshake"></i> Show
                        </button>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="button" class="btn btn-danger btn-sm w-100"
                                onclick="exportPDF('cit-table', 'Commission Goods In Transit', 'As of {{ now()->format('d-M-Y') }}')">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                    </div>
                </div>
            </form>

            <p class="text-muted small no-print mb-2">
                <i class="fas fa-info-circle"></i> Goods procured for a specific Customer under a Commission Invoice,
                dispatched but not yet delivered. Never counted as company inventory — tracked separately by design.
            </p>

            <div id="cit-table">
                <table class="table table-sm table-bordered">
                    <thead class="table-dark">
                        <tr>
                            <th>CI #</th><th>Date</th><th>Vendor</th><th>Customer</th><th>Transport</th><th>Bilty #</th>
                            <th>Product</th><th>Variation</th>
                            <th class="text-end">Qty</th><th class="text-end">Weight</th>
                            <th class="text-end">Purchase Value</th>
                            <th class="text-end">Sale Value</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($commissionInTransit as $row)
                        <tr>
                            <td>
                                <a href="{{ route('commission_invoices.show', $row->invoice_id) }}" target="_blank" class="ref-link text-info">
                                    CI-{{ $row->invoice_no }}
                                </a>
                            </td>
                            <td>{{ \Carbon\Carbon::parse($row->invoice_date)->format('d-M-Y') }}</td>
                            <td>{{ $row->vendor_name }}</td>
                            <td>{{ $row->customer_name }}</td>
                            <td>{{ $row->transport_name ?? '—' }}</td>
                            <td>{{ $row->bilty_no ?? '—' }}</td>
                            <td>{{ $row->product_name }}</td>
                            <td>{{ $row->variation_sku ?? '—' }}</td>
                            <td class="text-end">{{ number_format($row->quantity, 2) }}</td>
                            <td class="text-end">{{ number_format($row->weight, 2) }}</td>
                            <td class="text-end">{{ number_format($row->purchase_total, 2) }}</td>
                            <td class="text-end fw-bold">{{ number_format($row->sale_total, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="12" class="text-center py-3 text-muted">Nothing currently In Transit.</td></tr>
                    @endforelse
                    </tbody>
                    @if ($commissionInTransit->isNotEmpty())
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="10" class="text-end">Totals:</td>
                            <td class="text-end">{{ number_format($commissionInTransit->sum('purchase_total'), 2) }}</td>
                            <td class="text-end">{{ number_format($commissionInTransit->sum('sale_total'), 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

    </div>
</div>

<script>
function exportPDF(tableId, title, period) {
    const el = document.getElementById(tableId);
    if (!el) return;
    const clone = el.cloneNode(true);
    clone.querySelectorAll('.no-print').forEach(e => e.remove());
    clone.querySelectorAll('.badge').forEach(b => {
        b.replaceWith(document.createTextNode(b.textContent.trim()));
    });
    clone.querySelectorAll('a').forEach(a => {
        a.replaceWith(document.createTextNode(a.textContent.trim()));
    });
    const html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + title + '</title>'
        + '<style>body{font-family:Arial,sans-serif;font-size:11px;margin:20px}'
        + 'h2{font-size:14px;margin-bottom:4px}p{font-size:10px;color:#555;margin:0 0 10px}'
        + 'table{width:100%;border-collapse:collapse}'
        + 'th{background:#1a1a2e;color:#fff;padding:5px 7px;text-align:left}'
        + 'td{padding:4px 7px;border-bottom:0.5px solid #ddd}'
        + 'tr:nth-child(even) td{background:#f9f9f9}'
        + 'tfoot td{background:#e9ecef;font-weight:bold}'
        + '.text-end{text-align:right}.fw-bold{font-weight:bold}</style></head><body>'
        + '<h2>' + title + '</h2><p>' + period + '</p>'
        + clone.innerHTML
        + '<script>window.onload=function(){window.print();}<\/script>'
        + '</body></html>';
    const win = window.open('', '_blank', 'width=900,height=700');
    win.document.write(html);
    win.document.close();
}
</script>
@endsection