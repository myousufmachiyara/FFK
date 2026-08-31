@extends('layouts.app')
@section('title', 'Purchase Returns')

@section('content')
<div class="row">
  <div class="col">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title">Purchase Returns</h4>
        <a href="{{ route('purchase_return.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Purchase Return</a>
      </div>
      <div class="card-body">
        <table class="table table-bordered datatable">
          <thead>
            <tr>
              <th>ID</th>
              <th>Vendor</th>
              <th>Date</th>
              <th>Total Amount</th>
              <th>Remarks</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($returns as $ret)
              <tr>
                <td>{{ $ret->id }}</td>
                <td>{{ $ret->vendor->name ?? '-' }}</td>
                <td>{{ \Carbon\Carbon::parse($ret->return_date)->format('d-M-Y') }}</td>
                <td>{{ number_format($ret->total_amount, 2) }}</td>
                <td>{{ $ret->remarks }}</td>
                <td>
                  <a href="{{ route('purchase_return.edit', $ret->id) }}" class="text-primary"><i class="fas fa-edit"></i></a>
                  <a href="{{ route('purchase_return.print', $ret->id) }}" target="_blank" class="text-success"><i class="fas fa-print"></i></a>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
@endsection