@extends('layouts.app')

@section('title', 'Sale | Details')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      @if (session('success'))
          <div class="alert alert-success">{{ session('success') }}</div>
      @elseif (session('error'))
          <div class="alert alert-danger">{{ session('error') }}</div>
      @endif

      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">
          SI-{{ $invoice->invoice_no }}
          <span class="badge {{ $invoice->type === 'credit' ? 'bg-warning text-dark' : 'bg-success' }} ms-2">
            {{ ucfirst($invoice->type) }}
          </span>
        </h2>
        <div>
          <a href="{{ route('sale_invoices.print', $invoice->id) }}" target="_blank" class="btn btn-outline-success">
            <i class="fas fa-print"></i> Print
          </a>
          <a href="{{ route('sale_invoices.printKgOnly', $invoice->id) }}" target="_blank" class="btn btn-outline-success" title="Print — Rate/kg only">
              <i class="fas fa-print"></i> Print (kg rate)
          </a>
          <a href="{{ route('sale_invoices.edit', $invoice->id) }}" class="btn btn-outline-primary">
            <i class="fas fa-edit"></i> Edit
          </a>
          <a href="{{ route('sale_returns.create', $invoice->id) }}" class="btn btn-outline-warning">
            <i class="fas fa-reply"></i> Return Items
          </a>
          @if($invoice->remainingBalance() <= 0.01)
            <span class="badge bg-success p-2">Fully Paid</span>
          @endif
          <form action="{{ route('sale_invoices.destroy', $invoice->id) }}" method="POST" style="display:inline-block"
                onsubmit="return confirm('Delete this invoice? Stock will be restored and all vouchers reversed. This cannot be undone.');">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-outline-danger"><i class="fas fa-trash-alt"></i> Delete</button>
          </form>
        </div>
      </header>

      <div class="card-body">
        <div class="row mb-3">
          <div class="col-md-3"><strong>Date:</strong><br>{{ \Carbon\Carbon::parse($invoice->date)->format('d-M-Y') }}</div>
          <div class="col-md-3"><strong>Customer:</strong><br>{{ $invoice->account->name ?? 'N/A' }}</div>
          <div class="col-md-3">
            <strong>Payment Terms:</strong><br>
            {{ ucfirst($invoice->type) }}
            @if($invoice->isCredit() && $invoice->credit_days) ({{ $invoice->credit_days }} days) @endif
          </div>
          @if($invoice->isCredit() && $invoice->dueDate())
          <div class="col-md-3">
            <strong>Due Date:</strong><br>
            <span class="{{ now()->greaterThan($invoice->dueDate()) ? 'text-danger fw-bold' : '' }}">
              {{ $invoice->dueDate()->format('d-M-Y') }}
            </span>
          </div>
          @endif
        </div>

        <h5>Items</h5>
        <div class="table-responsive mb-4">
          <table class="table table-bordered table-sm">
            <thead>
              <tr>
                <th>#</th><th>Item</th><th>Variation</th><th>Packing</th>
                <th>Wt/Packing</th><th>Qty</th><th>Gross Wt</th><th>Net Wt</th>
                <th>Rate (40 kg)</th><th>Rate (kg)</th><th>Disc %</th><th>Total</th>
              </tr>
            </thead>
            <tbody>
              @foreach($invoice->items as $i => $item)
              <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $item->product->name ?? '-' }}</td>
                <td>{{ $item->variation->sku ?? '-' }}</td>
                <td>{{ $item->packingUnit->name ?? '-' }}</td>
                <td>{{ number_format($item->wt_per_packing, 2) }}</td>
                <td>{{ number_format($item->quantity, 0) }}</td>
                <td>{{ number_format($item->gross_weight, 2) }}</td>
                <td>{{ number_format($item->net_weight, 2) }}</td>
                <td>{{ number_format($item->rate_per_40kg, 2) }}</td>
                <td>{{ number_format($item->sale_price, 4) }}</td>
                <td>{{ number_format($item->discount, 2) }}</td>
                <td>{{ number_format($item->total, 2) }}</td>
              </tr>
              @endforeach
              <tr class="fw-bold table-light">
                <td colspan="6" class="text-end">Totals</td>
                <td>{{ number_format($invoice->total_gross_weight, 2) }}</td>
                <td>{{ number_format($invoice->total_weight, 2) }}</td>
                <td colspan="3"></td>
                <td>{{ number_format($invoice->net_amount, 2) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <h5>Other Expenses</h5>
        <div class="table-responsive mb-4">
          <table class="table table-sm table-bordered">
            <thead><tr><th>Type</th><th>Description</th><th>Amount</th><th>Paid By</th><th>Payable To</th></tr></thead>
            <tbody>
              @forelse($invoice->expenses as $exp)
              <tr>
                <td>{{ $exp->typeLabel() }}</td>
                <td>{{ $exp->description }}</td>
                <td>{{ number_format($exp->amount, 2) }}</td>
                <td><span class="badge {{ $exp->paid_by === 'vendor' ? 'bg-secondary' : 'bg-info text-dark' }}">{{ $exp->paidByLabel() }}</span></td>
                <td>{{ $exp->payeeAccount->name ?? '—' }}</td>
              </tr>
              @empty
              <tr><td colspan="5" class="text-muted text-center">No Other Expenses.</td></tr>
              @endforelse
              @if($invoice->expenses->count())
              <tr class="fw-bold table-light">
                <td colspan="2" class="text-end">Total Expense Amount</td>
                <td>{{ number_format($invoice->total_other_expenses, 2) }}</td>
                <td colspan="2"></td>
              </tr>
              @endif
            </tbody>
          </table>
        </div>

        <h5>Sale Invoice Summary</h5>
        <div class="row mb-4 text-center">
          <div class="col"><small class="text-muted d-block">Total Item Amount</small><strong>{{ number_format($invoice->net_amount, 2) }}</strong></div>
          <div class="col"><small class="text-muted d-block">Total Expense Amount</small><strong>{{ number_format($invoice->total_other_expenses, 2) }}</strong></div>
          <div class="col"><small class="text-muted d-block">Gross Wt.</small><strong>{{ number_format($invoice->total_gross_weight, 2) }} kg</strong></div>
          <div class="col"><small class="text-muted d-block">Net Wt.</small><strong>{{ number_format($invoice->total_weight, 2) }} kg</strong></div>
          <div class="col"><small class="text-muted d-block">Total Bill Amount</small><strong class="text-danger">{{ number_format($invoice->totalBillAmount(), 2) }}</strong></div>
        </div>

        <div class="row mb-4 text-center">
          <div class="col"><small class="text-muted d-block">Amount Received</small><strong class="text-success">{{ number_format($invoice->amount_received, 2) }}</strong></div>
          <div class="col"><small class="text-muted d-block">Remaining Balance</small><strong class="text-danger">{{ number_format($invoice->remainingBalance(), 2) }}</strong></div>
        </div>

        @if($invoice->remarks)
        <div class="mb-3">
          <strong>Remarks:</strong><br>{{ $invoice->remarks }}
        </div>
        @endif

        <h5>System-Generated Vouchers</h5>
        <div class="table-responsive mb-2">
          <table class="table table-sm table-bordered">
            <thead><tr><th>Date</th><th>Reference</th><th>Debit A/C</th><th>Credit A/C</th><th>Amount</th><th>Remarks</th></tr></thead>
            <tbody>
              @forelse($vouchers as $v)
              <tr>
                <td>{{ $v->date }}</td>
                <td>{{ $v->reference }}</td>
                <td>{{ optional(\App\Models\ChartOfAccounts::find($v->ac_dr_sid))->name }}</td>
                <td>{{ optional(\App\Models\ChartOfAccounts::find($v->ac_cr_sid))->name }}</td>
                <td>{{ number_format($v->amount, 2) }}</td>
                <td>{{ $v->remarks }}</td>
              </tr>
              @empty
              <tr><td colspan="6" class="text-muted text-center">No vouchers generated yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
</div>
@endsection