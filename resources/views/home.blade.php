@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div>
    <h2 class="text-dark"><strong id="currentDate"></strong></h2>
</div>

{{-- ═══════════════════════════════════════════════════════════════
     FINANCIAL SNAPSHOT
     ═══════════════════════════════════════════════════════════════ --}}
<div class="row mt-2">
    <div class="col-12 col-md-3 mb-2">
        <section class="card card-featured-left card-featured-primary">
            <div class="card-body icon-container data-container">
                <h3 class="amount text-dark"><strong>Cash + Bank Balance</strong></h3>
                <h2 class="amount m-0 text-primary">
                    <strong>{{ number_format($cashBankBalance, 0) }}</strong>
                    <span class="title text-end text-dark h6"> PKR</span>
                </h2>
                <div class="summary-footer">
                    <a class="text-primary text-uppercase" href="{{ route('reports.accounts', ['tab' => 'cash_book']) }}">View Cash Book</a>
                </div>
            </div>
        </section>
    </div>
    <div class="col-12 col-md-3 mb-2">
        <section class="card card-featured-left card-featured-danger">
            <div class="card-body icon-container data-container">
                <h3 class="amount text-dark"><strong>Total Receivables</strong></h3>
                <h2 class="amount m-0 text-danger">
                    <strong>{{ number_format($totalReceivables, 0) }}</strong>
                    <span class="title text-end text-dark h6"> PKR</span>
                </h2>
                <div class="summary-footer">
                    <a class="text-danger text-uppercase" href="{{ route('reports.sale', ['tab' => 'OUT']) }}">View Details</a>
                </div>
            </div>
        </section>
    </div>
    <div class="col-12 col-md-3 mb-2">
        <section class="card card-featured-left card-featured-tertiary">
            <div class="card-body icon-container data-container">
                <h3 class="amount text-dark"><strong>Total Payables</strong></h3>
                <h2 class="amount m-0 text-tertiary">
                    <strong>{{ number_format($totalPayables, 0) }}</strong>
                    <span class="title text-end text-dark h6"> PKR</span>
                </h2>
                <div class="summary-footer">
                    <a class="text-tertiary text-uppercase" href="{{ route('reports.accounts', ['tab' => 'payables']) }}">View Details</a>
                </div>
            </div>
        </section>
    </div>
    <div class="col-12 col-md-3 mb-2">
        <section class="card card-featured-left card-featured-success">
            <div class="card-body icon-container data-container">
                <h3 class="amount text-dark"><strong>Commission Income (Month)</strong></h3>
                <h2 class="amount m-0 text-success">
                    <strong>{{ number_format($commissionIncomeMonth, 0) }}</strong>
                    <span class="title text-end text-dark h6"> PKR</span>
                </h2>
                <div class="summary-footer">
                    <a class="text-success text-uppercase" href="{{ route('commission_invoices.index') }}">View Commission</a>
                </div>
            </div>
        </section>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════
     SALES SNAPSHOT
     ═══════════════════════════════════════════════════════════════ --}}
<div class="row">
    <div class="col-12 col-md-3 mb-2">
        <section class="card card-featured-left card-featured-success">
            <div class="card-body icon-container data-container">
                <h3 class="amount text-dark"><strong>Today's Sales</strong></h3>
                <h2 class="amount m-0 text-success">
                    <strong>{{ number_format($todaySales, 0) }}</strong>
                    <span class="title text-end text-dark h6"> PKR</span>
                </h2>
                <div class="summary-footer">
                    <a class="text-success text-uppercase" href="{{ route('sale_invoices.index') }}">View Details</a>
                </div>
            </div>
        </section>
    </div>
    <div class="col-12 col-md-3 mb-2">
        <section class="card card-featured-left card-featured-primary">
            <div class="card-body icon-container data-container">
                <h3 class="amount text-dark"><strong>This Month's Sales</strong></h3>
                <h2 class="amount m-0 text-primary">
                    <strong>{{ number_format($monthSales, 0) }}</strong>
                    <span class="title text-end text-dark h6"> PKR</span>
                </h2>
                <div class="summary-footer">
                    <span class="text-muted small">Cash: {{ number_format($cashSalesMonth, 0) }} | Credit: {{ number_format($creditSalesMonth, 0) }}</span>
                </div>
            </div>
        </section>
    </div>
    <div class="col-12 col-md-3 mb-2">
        <section class="card card-featured-left card-featured-warning">
            <div class="card-body icon-container data-container">
                <h3 class="amount text-dark"><strong>This Month's COGS</strong></h3>
                <h2 class="amount m-0 text-warning">
                    <strong>{{ number_format($monthCogs, 0) }}</strong>
                    <span class="title text-end text-dark h6"> PKR</span>
                </h2>
                <div class="summary-footer">
                    <span class="text-muted small">Sales Register &gt; Product-wise for detail</span>
                </div>
            </div>
        </section>
    </div>
    <div class="col-12 col-md-3 mb-2">
        <section class="card card-featured-left {{ $monthGrossProfit >= 0 ? 'card-featured-success' : 'card-featured-danger' }}">
            <div class="card-body icon-container data-container">
                <h3 class="amount text-dark"><strong>This Month's Gross Profit</strong></h3>
                <h2 class="amount m-0 {{ $monthGrossProfit >= 0 ? 'text-success' : 'text-danger' }}">
                    <strong>{{ number_format($monthGrossProfit, 0) }}</strong>
                    <span class="title text-end text-dark h6"> PKR</span>
                </h2>
                <div class="summary-footer">
                    <a class="{{ $monthGrossProfit >= 0 ? 'text-success' : 'text-danger' }} text-uppercase" href="{{ route('reports.accounts', ['tab' => 'profit_loss']) }}">View P&amp;L</a>
                </div>
            </div>
        </section>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════
     PURCHASE PIPELINE
     ═══════════════════════════════════════════════════════════════ --}}
<h4 class="mt-3 mb-2 text-dark"><i class="fa fa-shopping-cart"></i> Purchase Pipeline</h4>
<div class="row">
    <div class="col-12 col-md-3 mb-2">
        <section class="card card-featured-left card-featured-secondary">
            <div class="card-body icon-container data-container">
                <h3 class="amount text-dark"><strong>Pending</strong></h3>
                <h2 class="amount m-0 text-secondary">
                    <strong>{{ $purchasePendingCount }}</strong>
                    <span class="title text-end text-dark h6"> invoices</span>
                </h2>
                <div class="summary-footer">
                    <span class="text-muted small">Value: {{ number_format($purchasePendingValue, 0) }} PKR</span>
                </div>
            </div>
        </section>
    </div>
    <div class="col-12 col-md-3 mb-2">
        <section class="card card-featured-left card-featured-warning">
            <div class="card-body icon-container data-container">
                <h3 class="amount text-dark"><strong>In Transit</strong></h3>
                <h2 class="amount m-0 text-warning">
                    <strong>{{ $purchaseInTransitCount }}</strong>
                    <span class="title text-end text-dark h6"> invoices</span>
                </h2>
                <div class="summary-footer">
                    <span class="text-muted small">Vendor Payable: {{ number_format($purchaseInTransitValue, 0) }} PKR</span>
                </div>
            </div>
        </section>
    </div>
    <div class="col-12 col-md-3 mb-2">
        <section class="card card-featured-left card-featured-success">
            <div class="card-body icon-container data-container">
                <h3 class="amount text-dark"><strong>Received (Month)</strong></h3>
                <h2 class="amount m-0 text-success">
                    <strong>{{ $purchaseReceivedMonthCount }}</strong>
                    <span class="title text-end text-dark h6"> invoices</span>
                </h2>
                <div class="summary-footer">
                    <span class="text-muted small">Landed Value: {{ number_format($purchaseReceivedMonthValue, 0) }} PKR</span>
                </div>
            </div>
        </section>
    </div>
    <div class="col-12 col-md-3 mb-2">
        <section class="card card-featured-left card-featured-danger">
            <div class="card-body icon-container data-container">
                <h3 class="amount text-dark"><strong>Shortages (Month)</strong></h3>
                <h2 class="amount m-0 text-danger">
                    <strong>{{ $purchaseShortageCountMonth }}</strong>
                    <span class="title text-end text-dark h6"> invoices</span>
                </h2>
                <div class="summary-footer">
                    <a class="text-danger text-uppercase" href="{{ route('reports.purchase', ['tab' => 'SAC']) }}">View Details</a>
                </div>
            </div>
        </section>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════
     COMMISSION PIPELINE
     ═══════════════════════════════════════════════════════════════ --}}
<h4 class="mt-3 mb-2 text-dark"><i class="fa fa-handshake"></i> Commission Pipeline</h4>
<div class="row">
    <div class="col-12 col-md-3 mb-2">
        <section class="card card-featured-left card-featured-secondary">
            <div class="card-body icon-container data-container">
                <h3 class="amount text-dark"><strong>Pending</strong></h3>
                <h2 class="amount m-0 text-secondary">
                    <strong>{{ $commissionPendingCount }}</strong>
                    <span class="title text-end text-dark h6"> orders</span>
                </h2>
                <div class="summary-footer">
                    <a class="text-secondary text-uppercase" href="{{ route('commission_invoices.index') }}">View Details</a>
                </div>
            </div>
        </section>
    </div>
    <div class="col-12 col-md-3 mb-2">
        <section class="card card-featured-left card-featured-warning">
            <div class="card-body icon-container data-container">
                <h3 class="amount text-dark"><strong>In Transit</strong></h3>
                <h2 class="amount m-0 text-warning">
                    <strong>{{ $commissionInTransitCount }}</strong>
                    <span class="title text-end text-dark h6"> orders</span>
                </h2>
                <div class="summary-footer">
                    <span class="text-muted small">Vendor Payable: {{ number_format($commissionInTransitValue, 0) }} PKR</span>
                </div>
            </div>
        </section>
    </div>
    <div class="col-12 col-md-3 mb-2">
        <section class="card card-featured-left card-featured-success">
            <div class="card-body icon-container data-container">
                <h3 class="amount text-dark"><strong>Delivered (Month)</strong></h3>
                <h2 class="amount m-0 text-success">
                    <strong>{{ $commissionDeliveredMonthCount }}</strong>
                    <span class="title text-end text-dark h6"> orders</span>
                </h2>
                <div class="summary-footer">
                    <span class="text-muted small">Sale Value: {{ number_format($commissionDeliveredMonthValue, 0) }} PKR</span>
                </div>
            </div>
        </section>
    </div>
    <div class="col-12 col-md-3 mb-2">
        <section class="card card-featured-left card-featured-primary">
            <div class="card-body icon-container data-container">
                <h3 class="amount text-dark"><strong>Commission Earned (Month)</strong></h3>
                <h2 class="amount m-0 text-primary">
                    <strong>{{ number_format($commissionDeliveredMonthIncome, 0) }}</strong>
                    <span class="title text-end text-dark h6"> PKR</span>
                </h2>
                <div class="summary-footer">
                    <a class="text-primary text-uppercase" href="{{ route('reports.accounts', ['tab' => 'profit_loss']) }}">View P&amp;L</a>
                </div>
            </div>
        </section>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════
     INVENTORY
     ═══════════════════════════════════════════════════════════════ --}}
<h4 class="mt-3 mb-2 text-dark"><i class="fa fa-cubes"></i> Inventory</h4>
<div class="row">
    <div class="col-12 col-md-3 mb-2">
        <section class="card card-featured-left card-featured-primary">
            <div class="card-body icon-container data-container">
                <h3 class="amount text-dark"><strong>Total Products</strong></h3>
                <h2 class="amount m-0 text-primary">
                    <strong>{{ $totalProducts }}</strong>
                </h2>
                <div class="summary-footer">
                    <a class="text-primary text-uppercase" href="{{ route('products.index') }}">View Products</a>
                </div>
            </div>
        </section>
    </div>
    <div class="col-12 col-md-3 mb-2">
        <section class="card card-featured-left {{ $outOfStockCount > 0 ? 'card-featured-danger' : 'card-featured-success' }}">
            <div class="card-body icon-container data-container">
                <h3 class="amount text-dark"><strong>Out of Stock Variations</strong></h3>
                <h2 class="amount m-0 {{ $outOfStockCount > 0 ? 'text-danger' : 'text-success' }}">
                    <strong>{{ $outOfStockCount }}</strong>
                </h2>
                <div class="summary-footer">
                    <a class="{{ $outOfStockCount > 0 ? 'text-danger' : 'text-success' }} text-uppercase" href="{{ route('reports.inventory', ['tab' => 'SR']) }}">View Stock</a>
                </div>
            </div>
        </section>
    </div>
    <div class="col-12 col-md-3 mb-2">
        <section class="card card-featured-left card-featured-warning">
            <div class="card-body icon-container data-container">
                <h3 class="amount text-dark"><strong>Purchase Stock In Transit</strong></h3>
                <h2 class="amount m-0 text-warning">
                    <strong>{{ number_format($purchaseInTransitValue, 0) }}</strong>
                    <span class="title text-end text-dark h6"> PKR</span>
                </h2>
                <div class="summary-footer">
                    <a class="text-warning text-uppercase" href="{{ route('reports.inventory', ['tab' => 'IT']) }}">View Details</a>
                </div>
            </div>
        </section>
    </div>
    <div class="col-12 col-md-3 mb-2">
        <section class="card card-featured-left card-featured-tertiary">
            <div class="card-body icon-container data-container">
                <h3 class="amount text-dark"><strong>Commission Goods In Transit</strong></h3>
                <h2 class="amount m-0 text-tertiary">
                    <strong>{{ number_format($commissionInTransitValue, 0) }}</strong>
                    <span class="title text-end text-dark h6"> PKR</span>
                </h2>
                <div class="summary-footer">
                    <a class="text-tertiary text-uppercase" href="{{ route('reports.inventory', ['tab' => 'CIT']) }}">View Details</a>
                </div>
            </div>
        </section>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════
     RECENT ACTIVITY
     ═══════════════════════════════════════════════════════════════ --}}
<div class="row mt-2">
    <div class="col-12 col-lg-4 mb-3 d-flex">
        <section class="card flex-fill">
            <header class="card-header">
                <h2 class="card-title">Recent Purchase Invoices</h2>
            </header>
            <div class="card-body p-0">
                <table class="table table-sm table-striped mb-0">
                    <thead>
                        <tr><th>Invoice</th><th>Vendor</th><th>Status</th><th class="text-end">Amount</th></tr>
                    </thead>
                    <tbody>
                    @forelse($recentPurchases as $p)
                        <tr>
                            <td><a href="{{ route('purchase_invoices.show', $p->id) }}">PI-{{ $p->invoice_no }}</a></td>
                            <td>{{ $p->vendor->name ?? '—' }}</td>
                            <td><span class="{{ $p->statusBadgeClass() }}">{{ $p->statusLabel() }}</span></td>
                            <td class="text-end">{{ number_format($p->total_amount, 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">No purchase invoices yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="col-12 col-lg-4 mb-3 d-flex">
        <section class="card flex-fill">
            <header class="card-header">
                <h2 class="card-title">Recent Sale Invoices</h2>
            </header>
            <div class="card-body p-0">
                <table class="table table-sm table-striped mb-0">
                    <thead>
                        <tr><th>Invoice</th><th>Customer</th><th>Type</th><th class="text-end">Amount</th></tr>
                    </thead>
                    <tbody>
                    @forelse($recentSales as $s)
                        <tr>
                            <td><a href="{{ route('sale_invoices.show', $s->id) }}">SI-{{ $s->invoice_no }}</a></td>
                            <td>{{ $s->account->name ?? '—' }}</td>
                            <td><span class="badge {{ $s->type === 'credit' ? 'bg-warning text-dark' : 'bg-success' }}">{{ ucfirst($s->type) }}</span></td>
                            <td class="text-end">{{ number_format($s->net_amount, 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">No sale invoices yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="col-12 col-lg-4 mb-3 d-flex">
        <section class="card flex-fill">
            <header class="card-header">
                <h2 class="card-title">Recent Commission Invoices</h2>
            </header>
            <div class="card-body p-0">
                <table class="table table-sm table-striped mb-0">
                    <thead>
                        <tr><th>Invoice</th><th>Customer</th><th>Status</th><th class="text-end">Commission</th></tr>
                    </thead>
                    <tbody>
                    @forelse($recentCommissions as $c)
                        <tr>
                            <td><a href="{{ route('commission_invoices.show', $c->id) }}">CI-{{ $c->invoice_no }}</a></td>
                            <td>{{ $c->customer->name ?? '—' }}</td>
                            <td><span class="{{ $c->statusBadgeClass() }}">{{ $c->statusLabel() }}</span></td>
                            <td class="text-end">{{ number_format($c->total_commission_amount, 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">No commission invoices yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<script>
    $(document).ready(function() {
        const now = new Date();
        const day = getDaySuffix(now.getDate());
        const formattedDate = `${now.toLocaleString('en-GB', { weekday: 'long' })}, ${day} ${now.toLocaleString('en-GB', { month: 'long' })} ${now.getFullYear()}`;
        document.getElementById('currentDate').innerText = formattedDate;
    });

    function getDaySuffix(day) {
        if (day >= 11 && day <= 13) {
            return day + 'th';
        }
        switch (day % 10) {
            case 1: return day + 'st';
            case 2: return day + 'nd';
            case 3: return day + 'rd';
            default: return day + 'th';
        }
    }
</script>
@endsection