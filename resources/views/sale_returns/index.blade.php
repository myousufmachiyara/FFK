@extends('layouts.app')
@section('title', 'Sale Returns')

@section('content')
<div class="row">
  <div class="col">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title">Sale Returns</h4>
        <a href="{{ route('sale_return.create') }}" class="btn btn-primary">
          <i class="fas fa-plus"></i> Sale Return
        </a>
      </div>
      <div class="card-body">
        <table class="table table-bordered datatable">
          <thead>
            <tr>
              <th>ID</th>
              <th>Customer</th>
              <th>Date</th>
              <th>Total Amount</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($returns as $ret)
              <tr>
                <td>{{ $ret->id }}</td>
                <td>{{ $ret->customer->name ?? '-' }}</td>
                <td>{{ $ret->return_date }}</td>
                <td>{{ number_format($ret->total_amount, 2) }}</td>
                <td>
                  <a href="{{ route('sale_return.edit', $ret->id) }}" class="text-primary">
                    <i class="fas fa-edit"></i>
                  </a>
                  <a href="{{ route('sale_return.print', $ret->id) }}" target="_blank" class="text-success">
                    <i class="fas fa-print"></i>
                  </a>
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
