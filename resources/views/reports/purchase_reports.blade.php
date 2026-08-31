@extends('layouts.app')
@section('title', 'Purchase Reports')

@section('content')
<style>
@media print { .no-print { display: none !important; } }
.ref-link { text-decoration: none; font-weight: 500; }
.ref-link:hover { text-decoration: underline; }
</style>

@php
    $statusBadge = function ($s) {
        return match ($s) {
            'pending'    => 'badge bg-secondary',
            'in_transit' => 'badge bg-warning text-dark',
            'received'   => 'badge bg-success',
            default      => 'badge bg-light text-dark',
        };
    };
    $statusLabel = fn ($s) => ucwords(str_replace('_', ' ', $s ?? ''));
@endphp

<div class="tabs">
    <ul class="nav nav-tabs">
        <li class="nav-item">
            <a class="nav-link {{ $tab=='PUR' ? 'active' : '' }}" data-bs-toggle="tab" href="#PUR">Purchase Register</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab=='PR'  ? 'active' : '' }}" data-bs-toggle="tab" href="#PR">Purchase Returns</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab=='VWP' ? 'active' : '' }}" data-bs-toggle="tab" href="#VWP">Vendor-wise Purchases</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab=='STA' ? 'active' : '' }}" data-bs-toggle="tab" href="#STA">Status Overview</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab=='SAC' ? 'active' : '' }}" data-bs-toggle="tab" href="#SAC">Shortages & Additional Costs</a>
        </li>
    </ul>

    <div class="tab-content mt-3">

        {{-- ── PURCHASE REGISTER ──────────────────────────────── --}}
        <div id="PUR" class="tab-pane fade {{ $tab=='PUR' ? 'show active' : '' }}">
            <form method="GET" action="{{ route('reports.purchase') }}" class="no-print">
                <input type="hidden" name="tab" value="PUR">
                <div class="row g-3 mb-3">
                    <div class="col-md-2">
                        <label>From Date</label>
                        <input type="date" name="from_date" class="form-control" value="{{ request('from_date', $from) }}">
                    </div>
                    <div class="col-md-2">
                        <label>To Date</label>
                        <input type="date" name="to_date" class="form-control" value="{{ request('to_date', $to) }}">
                    </div>
                    <div class="col-md-3">
                        <label>Vendor</label>
                        <select name="vendor_id" class="form-control">
                            <option value="">-- All Vendors --</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor->id }}" {{ request('vendor_id')==$vendor->id ? 'selected' : '' }}>
                                    {{ $vendor->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="">-- All --</option>
                            <option value="pending" {{ request('status')=='pending' ? 'selected' : '' }}>Pending</option>
                            <option value="in_transit" {{ request('status')=='in_transit' ? 'selected' : '' }}>In Transit</option>
                            <option value="received" {{ request('status')=='received' ? 'selected' : '' }}>Received</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                        <button type="button" class="btn btn-danger"
                                onclick="exportPDF('pur-table', 'Purchase Register', '{{ request('from_date', $from) }} to {{ request('to_date', $to) }}')">
                            <i class="fas fa-file-pdf"></i>
                        </button>
                    </div>
                </div>
            </form>

            @php
                $grandTotal = $purchaseRegister->sum('total');
                $grandQty   = $purchaseRegister->sum('quantity');
            @endphp
            <div class="mb-3 text-end no-print">
                <h5>Total Qty: <span class="text-primary">{{ $grandQty }}</span></h5>
                <h3>Total Purchase: <span class="text-danger">{{ number_format($grandTotal, 2) }}</span></h3>
            </div>

            <div id="pur-table">
                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th><th>Invoice #</th><th>Vendor Bill #</th><th>Vendor</th><th>Status</th>
                            <th>Item</th><th>Variation</th>
                            <th class="text-end">Ordered Qty</th><th class="text-end">Received Qty</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Total</th>
                            <th class="no-print text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($purchaseRegister as $pur)
                        <tr>
                            <td>{{ $pur->date }}</td>
                            <td>
                                <a href="{{ route('purchase_invoices.show', $pur->invoice_id) }}"
                                   target="_blank" class="ref-link text-success">
                                    PI-{{ $pur->invoice_no }}
                                </a>
                            </td>
                            <td>{{ $pur->vendor_bill_no ?? '—' }}</td>
                            <td>{{ $pur->vendor_name }}</td>
                            <td><span class="{{ $statusBadge($pur->status) }}">{{ $statusLabel($pur->status) }}</span></td>
                            <td>{{ $pur->item_name }}</td>
                            <td>{{ $pur->variation }}</td>
                            <td class="text-end">{{ number_format($pur->quantity, 2) }}</td>
                            <td class="text-end">{{ $pur->received_quantity !== null ? number_format($pur->received_quantity, 2) : '—' }}</td>
                            <td class="text-end">{{ number_format($pur->rate, 2) }}</td>
                            <td class="text-end fw-bold">{{ number_format($pur->total, 2) }}</td>
                            <td class="text-center no-print">
                                <a href="{{ route('purchase_invoices.print', $pur->invoice_id) }}"
                                   target="_blank" class="btn btn-outline-success btn-sm" title="Print">
                                    <i class="fas fa-print"></i>
                                </a>
                                @if($pur->status === 'pending')
                                <a href="{{ route('purchase_invoices.edit', $pur->invoice_id) }}"
                                   class="btn btn-outline-primary btn-sm ms-1" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="12" class="text-center text-muted">No purchase records found.</td></tr>
                    @endforelse
                    </tbody>
                    @if(count($purchaseRegister))
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="7" class="text-end">Grand Total</td>
                            <td class="text-end">{{ number_format($grandQty, 2) }}</td>
                            <td class="text-end">—</td>
                            <td class="text-end">—</td>
                            <td class="text-end">{{ number_format($grandTotal, 2) }}</td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ── PURCHASE RETURNS ────────────────────────────────── --}}
        <div id="PR" class="tab-pane fade {{ $tab=='PR' ? 'show active' : '' }}">
            <form method="GET" action="{{ route('reports.purchase') }}" class="no-print">
                <input type="hidden" name="tab" value="PR">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label>From Date</label>
                        <input type="date" name="from_date" class="form-control" value="{{ request('from_date', $from) }}">
                    </div>
                    <div class="col-md-3">
                        <label>To Date</label>
                        <input type="date" name="to_date" class="form-control" value="{{ request('to_date', $to) }}">
                    </div>
                    <div class="col-md-3">
                        <label>Vendor</label>
                        <select name="vendor_id" class="form-control">
                            <option value="">-- All Vendors --</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor->id }}" {{ request('vendor_id')==$vendor->id ? 'selected' : '' }}>
                                    {{ $vendor->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                        <button type="button" class="btn btn-danger"
                                onclick="exportPDF('pr-table', 'Purchase Returns', '{{ request('from_date', $from) }} to {{ request('to_date', $to) }}')">
                            <i class="fas fa-file-pdf"></i>
                        </button>
                    </div>
                </div>
            </form>

            @php
                $returnTotal = $purchaseReturns->sum('total');
                $returnQty   = $purchaseReturns->sum('quantity');
            @endphp
            <div class="mb-3 text-end no-print">
                <h5>Total Qty Returned: <span class="text-warning">{{ $returnQty }}</span></h5>
                <h3>Total Returns: <span class="text-danger">{{ number_format($returnTotal, 2) }}</span></h3>
            </div>

            <div id="pr-table">
                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th><th>Return No</th><th>Vendor</th><th>Item</th>
                            <th class="text-end">Qty</th><th class="text-end">Rate</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($purchaseReturns as $pr)
                        <tr>
                            <td>{{ $pr->date }}</td>
                            <td>PR-{{ $pr->return_id }}</td>
                            <td>{{ $pr->vendor_name }}</td>
                            <td>{{ $pr->item_name }}</td>
                            <td class="text-end">{{ $pr->quantity }}</td>
                            <td class="text-end">{{ number_format($pr->rate, 2) }}</td>
                            <td class="text-end fw-bold">{{ number_format($pr->total, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted">No purchase return records found.</td></tr>
                    @endforelse
                    </tbody>
                    @if(count($purchaseReturns))
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="4" class="text-end">Grand Total</td>
                            <td class="text-end">{{ $returnQty }}</td>
                            <td class="text-end">—</td>
                            <td class="text-end">{{ number_format($returnTotal, 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ── VENDOR-WISE PURCHASE ─────────────────────────────── --}}
        <div id="VWP" class="tab-pane fade {{ $tab=='VWP' ? 'show active' : '' }}">
            <form method="GET" action="{{ route('reports.purchase') }}" class="no-print">
                <input type="hidden" name="tab" value="VWP">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label>Vendor</label>
                        <select name="vendor_id" class="form-control">
                            <option value="">-- All Vendors --</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor->id }}" {{ request('vendor_id')==$vendor->id ? 'selected' : '' }}>
                                    {{ $vendor->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label>From Date</label>
                        <input type="date" name="from_date" class="form-control" value="{{ request('from_date', $from) }}">
                    </div>
                    <div class="col-md-2">
                        <label>To Date</label>
                        <input type="date" name="to_date" class="form-control" value="{{ request('to_date', $to) }}">
                    </div>
                    <div class="col-md-2">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="">-- All --</option>
                            <option value="pending" {{ request('status')=='pending' ? 'selected' : '' }}>Pending</option>
                            <option value="in_transit" {{ request('status')=='in_transit' ? 'selected' : '' }}>In Transit</option>
                            <option value="received" {{ request('status')=='received' ? 'selected' : '' }}>Received</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                        <button type="button" class="btn btn-danger"
                                onclick="exportPDF('vwp-table', 'Vendor-wise Purchases', '{{ request('from_date', $from) }} to {{ request('to_date', $to) }}')">
                            <i class="fas fa-file-pdf"></i>
                        </button>
                    </div>
                </div>
            </form>

            <div class="mb-3 text-end no-print">
                <h5>Total Qty: <span class="text-primary">{{ $vendorWisePurchase->sum('total_qty') }}</span></h5>
                <h3>Total Purchases: <span class="text-success">{{ number_format($vendorWisePurchase->sum('total_amount'), 2) }}</span></h3>
            </div>

            <div id="vwp-table">
                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Vendor</th><th>Invoice Date</th><th>Invoice #</th><th>Status</th>
                            <th>Item</th><th>Variation</th>
                            <th class="text-end">Qty</th><th class="text-end">Rate</th>
                            <th class="text-end">Total</th>
                            <th class="no-print text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($vendorWisePurchase as $vendorData)
                        <tr class="table-secondary">
                            <td colspan="10"><strong>{{ $vendorData->vendor_name }}</strong></td>
                        </tr>
                        @foreach($vendorData->items as $item)
                        <tr>
                            <td></td>
                            <td>{{ $item->invoice_date }}</td>
                            <td>
                                <a href="{{ route('purchase_invoices.show', $item->invoice_id ?? 0) }}"
                                   target="_blank" class="ref-link text-success">
                                    PI-{{ $item->invoice_no }}
                                </a>
                            </td>
                            <td><span class="{{ $statusBadge($item->status) }}">{{ $statusLabel($item->status) }}</span></td>
                            <td>{{ $item->item_name }}</td>
                            <td>{{ $item->variation }}</td>
                            <td class="text-end">{{ number_format($item->quantity, 2) }}</td>
                            <td class="text-end">{{ number_format($item->rate, 2) }}</td>
                            <td class="text-end fw-bold">{{ number_format($item->total, 2) }}</td>
                            <td class="text-center no-print">
                                @if(isset($item->invoice_id))
                                <a href="{{ route('purchase_invoices.print', $item->invoice_id) }}"
                                   target="_blank" class="btn btn-outline-success btn-sm" title="Print">
                                    <i class="fas fa-print"></i>
                                </a>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                        <tr class="fw-bold table-light">
                            <td colspan="6" class="text-end">Vendor Total</td>
                            <td class="text-end">{{ number_format($vendorData->total_qty, 2) }}</td>
                            <td class="text-end">—</td>
                            <td class="text-end">{{ number_format($vendorData->total_amount, 2) }}</td>
                            <td class="no-print"></td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-muted">No vendor purchase data found.</td></tr>
                    @endforelse
                    </tbody>
                    @if(count($vendorWisePurchase))
                    <tfoot class="table-dark fw-bold">
                        <tr>
                            <td colspan="6" class="text-end">Grand Total</td>
                            <td class="text-end">{{ number_format($vendorWisePurchase->sum('total_qty'), 2) }}</td>
                            <td class="text-end">—</td>
                            <td class="text-end">{{ number_format($vendorWisePurchase->sum('total_amount'), 2) }}</td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ── STATUS OVERVIEW (NEW) ─────────────────────────────── --}}
        <div id="STA" class="tab-pane fade {{ $tab=='STA' ? 'show active' : '' }}">
            <form method="GET" action="{{ route('reports.purchase') }}" class="no-print">
                <input type="hidden" name="tab" value="STA">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label>Vendor</label>
                        <select name="vendor_id" class="form-control">
                            <option value="">-- All Vendors --</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor->id }}" {{ request('vendor_id')==$vendor->id ? 'selected' : '' }}>
                                    {{ $vendor->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>From Date</label>
                        <input type="date" name="from_date" class="form-control" value="{{ request('from_date', $from) }}">
                    </div>
                    <div class="col-md-3">
                        <label>To Date</label>
                        <input type="date" name="to_date" class="form-control" value="{{ request('to_date', $to) }}">
                    </div>
                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                        <button type="button" class="btn btn-danger"
                                onclick="exportPDF('sta-table', 'Purchase Status Overview', '{{ request('from_date', $from) }} to {{ request('to_date', $to) }}')">
                            <i class="fas fa-file-pdf"></i>
                        </button>
                    </div>
                </div>
            </form>

            <p class="text-muted small no-print">
                <i class="fas fa-info-circle"></i> Based on Invoice Date, regardless of when each status change happened.
            </p>

            <div id="sta-table">
                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Status</th><th class="text-end">Invoice Count</th>
                            <th class="text-end">Total Value</th>
                            <th class="text-end">Bilty Charges</th>
                            <th class="text-end">Labor Charges</th>
                            <th class="text-end">Other Charges</th>
                            <th class="text-end">Landed Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($statusOverview as $row)
                        <tr>
                            <td><span class="{{ $statusBadge($row->status) }}">{{ $row->label }}</span></td>
                            <td class="text-end">{{ $row->count }}</td>
                            <td class="text-end">{{ number_format($row->total_amount, 2) }}</td>
                            <td class="text-end">{{ number_format($row->bilty_charges, 2) }}</td>
                            <td class="text-end">{{ number_format($row->labor_charges, 2) }}</td>
                            <td class="text-end">{{ number_format($row->other_charges, 2) }}</td>
                            <td class="text-end fw-bold">
                                {{ number_format($row->total_amount + $row->bilty_charges + $row->labor_charges + $row->other_charges, 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted">No data for this period.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ── SHORTAGES & ADDITIONAL COSTS (NEW) ────────────────── --}}
        <div id="SAC" class="tab-pane fade {{ $tab=='SAC' ? 'show active' : '' }}">
            <form method="GET" action="{{ route('reports.purchase') }}" class="no-print">
                <input type="hidden" name="tab" value="SAC">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label>Vendor</label>
                        <select name="vendor_id" class="form-control">
                            <option value="">-- All Vendors --</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor->id }}" {{ request('vendor_id')==$vendor->id ? 'selected' : '' }}>
                                    {{ $vendor->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>Received From</label>
                        <input type="date" name="from_date" class="form-control" value="{{ request('from_date', $from) }}">
                    </div>
                    <div class="col-md-3">
                        <label>Received To</label>
                        <input type="date" name="to_date" class="form-control" value="{{ request('to_date', $to) }}">
                    </div>
                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                        <button type="button" class="btn btn-danger"
                                onclick="exportPDF('sac-table', 'Shortages & Additional Costs', '{{ request('from_date', $from) }} to {{ request('to_date', $to) }}')">
                            <i class="fas fa-file-pdf"></i>
                        </button>
                    </div>
                </div>
            </form>

            <p class="text-muted small no-print">
                <i class="fas fa-info-circle"></i> Only Received invoices with a recorded shortage or additional
                receiving cost (Bilty/Labor/Other) appear here.
            </p>

            @php
                $totalShortageValue = $shortagesAndCosts->sum('shortage_value');
                $totalAdditional    = $shortagesAndCosts->sum('total_additional');
            @endphp
            <div class="mb-3 text-end no-print">
                <h5>Total Shortage Value: <span class="text-danger">{{ number_format($totalShortageValue, 2) }}</span></h5>
                <h5>Total Additional Costs: <span class="text-warning">{{ number_format($totalAdditional, 2) }}</span></h5>
            </div>

            <div id="sac-table">
                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Received Date</th><th>Invoice #</th><th>Vendor</th>
                            <th class="text-end">Dispatched Qty</th>
                            <th class="text-end">Received Qty</th>
                            <th class="text-end">Short Qty</th>
                            <th class="text-end">Shortage Value</th>
                            <th class="text-end">Bilty</th>
                            <th class="text-end">Labor</th>
                            <th class="text-end">Other</th>
                            <th class="text-end">Total Additional</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($shortagesAndCosts as $row)
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($row->received_at)->format('d-M-Y') }}</td>
                            <td>
                                <a href="{{ route('purchase_invoices.show', $row->invoice_id) }}" target="_blank" class="ref-link text-success">
                                    PI-{{ $row->invoice_no }}
                                </a>
                            </td>
                            <td>{{ $row->vendor_name }}</td>
                            <td class="text-end">{{ number_format($row->dispatched_qty, 2) }}</td>
                            <td class="text-end">{{ number_format($row->received_qty, 2) }}</td>
                            <td class="text-end {{ $row->short_qty > 0 ? 'text-danger fw-bold' : '' }}">{{ number_format($row->short_qty, 2) }}</td>
                            <td class="text-end {{ $row->shortage_value > 0 ? 'text-danger' : '' }}">{{ number_format($row->shortage_value, 2) }}</td>
                            <td class="text-end">{{ number_format($row->bilty_charges, 2) }}</td>
                            <td class="text-end">{{ number_format($row->labor_charges, 2) }}</td>
                            <td class="text-end">{{ number_format($row->other_charges, 2) }}</td>
                            <td class="text-end fw-bold">{{ number_format($row->total_additional, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="11" class="text-center text-muted">No shortages or additional costs in this period.</td></tr>
                    @endforelse
                    </tbody>
                    @if(count($shortagesAndCosts))
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="6" class="text-end">Totals</td>
                            <td class="text-end">{{ number_format($totalShortageValue, 2) }}</td>
                            <td colspan="3" class="text-end"></td>
                            <td class="text-end">{{ number_format($totalAdditional, 2) }}</td>
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
    clone.querySelectorAll('a').forEach(a => {
        const span = document.createElement('span');
        span.textContent = a.textContent;
        a.replaceWith(span);
    });
    clone.querySelectorAll('.badge').forEach(b => {
        const span = document.createElement('span');
        span.textContent = b.textContent.trim();
        b.replaceWith(span);
    });
    const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>${title}</title>
    <style>
        body{font-family:Arial,sans-serif;font-size:11px;margin:20px}
        h2{font-size:14px;margin-bottom:4px}p{font-size:10px;color:#555;margin:0 0 10px}
        table{width:100%;border-collapse:collapse}
        th{background:#1a1a2e;color:#fff;padding:5px 7px;text-align:left}
        td{padding:4px 7px;border-bottom:0.5px solid #ddd}
        tr:nth-child(even) td{background:#f9f9f9}
        .text-end{text-align:right}.fw-bold{font-weight:bold}
        tfoot td{background:#f0f0f0;font-weight:bold}
    </style></head><body>
    <h2>${title}</h2><p>${period}</p>
    ${clone.innerHTML}
    <script>window.onload=function(){window.print();}<\/script>
    </body></html>`;
    const win = window.open('', '_blank', 'width=900,height=700');
    win.document.write(html);
    win.document.close();
}
</script>
@endsection