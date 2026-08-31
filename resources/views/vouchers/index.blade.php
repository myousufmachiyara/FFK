@extends('layouts.app')

@section('title', ucfirst($type) . ' Vouchers')

@section('content')

@if(session('success'))
    <div class="alert alert-success alert-dismissible">
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        {{ session('success') }}
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible">
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        {{ session('error') }}
    </div>
@endif

<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">{{ ucfirst($type) }} Vouchers</h2>
        <button type="button" class="modal-with-form btn btn-primary" href="#addModal">
          <i class="fas fa-plus"></i> Add New
        </button>
      </header>

      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped mb-0" id="voucher-datatable">
            <thead>
              <tr>
                <th>Voch#</th>
                <th>Date</th>
                <th>Account Debit</th>
                <th>Account Credit</th>
                <th>Remarks</th>
                <th>Amount</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($vouchers as $row)
                <tr>
                  <td>{{ $row->id }}</td>
                  <td>{{ \Carbon\Carbon::parse($row->date)->format('d-m-Y') }}</td>
                  <td>{{ $row->debitAccount->name ?? 'N/A' }}</td>
                  <td>{{ $row->creditAccount->name ?? 'N/A' }}</td>
                  <td>{{ $row->remarks }}</td>
                  <td><strong>{{ number_format($row->amount, 0, '.', ',') }}</strong></td>
                  <td class="actions">
                    <a class="text-success me-1"
                       href="{{ route('vouchers.print', ['type' => $type, 'id' => $row->id]) }}"
                       target="_blank" title="Print">
                        <i class="fas fa-print"></i>
                    </a>
                    {{-- FIX: use javascript:void(0) + openEditModal() instead of
                         href="#updateModal" + modal-with-form. The theme's
                         modal-with-form handler doesn't set the form action,
                         so edits always posted to the wrong URL. --}}
                    <a class="text-primary me-1"
                       href="javascript:void(0)"
                       onclick="openEditModal({{ $row->id }})"
                       title="Edit">
                        <i class="fas fa-edit"></i>
                    </a>
                    {{-- FIX: delete was using <a href="#deleteModal"> without
                         modal-with-form class so Magnific never opened it. --}}
                    <a class="text-danger"
                       href="javascript:void(0)"
                       onclick="openDeleteModal({{ $row->id }})"
                       title="Delete">
                        <i class="fas fa-trash-alt"></i>
                    </a>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </section>

    {{-- ADD MODAL --}}
    <div id="addModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="POST" action="{{ route('vouchers.store', $type) }}"
              enctype="multipart/form-data" onkeydown="return event.key != 'Enter';">
          @csrf
          <input type="hidden" name="voucher_type" value="{{ $type }}">
          <header class="card-header">
            <h2 class="card-title">Add {{ ucfirst($type) }} Voucher</h2>
          </header>
          <div class="card-body">
            <div class="row">
              <div class="col-lg-6 mb-2">
                <label>Date</label>
                <input type="date" class="form-control" name="date" value="{{ date('Y-m-d') }}" required>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Account Debit <span class="text-danger">*</span></label>
                <select class="form-control select2-js" name="ac_dr_sid" required>
                  <option value="" disabled selected>Select Account</option>
                  @foreach($accounts as $acc)
                    <option value="{{ $acc->id }}">{{ $acc->name }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Account Credit <span class="text-danger">*</span></label>
                <select class="form-control select2-js" name="ac_cr_sid" required>
                  <option value="" disabled selected>Select Account</option>
                  @foreach($accounts as $acc)
                    <option value="{{ $acc->id }}">{{ $acc->name }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Amount <span class="text-danger">*</span></label>
                <input type="number" class="form-control" name="amount" step="any" value="0" required>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Attachments</label>
                <input type="file" class="form-control" name="att[]" multiple
                       accept=".zip,application/pdf,image/png,image/jpeg">
              </div>
              <div class="col-lg-12 mb-2">
                <label>Remarks</label>
                <textarea rows="3" class="form-control" name="remarks"></textarea>
              </div>
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Add {{ ucfirst($type) }} Voucher</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>

    {{-- EDIT MODAL --}}
    <div id="editModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="POST" id="editForm"
              enctype="multipart/form-data"
              onkeydown="return event.key != 'Enter';">
          @csrf
          @method('PUT')
          <input type="hidden" name="voucher_type" value="{{ $type }}">
          <header class="card-header">
            <h2 class="card-title">
                Edit {{ ucfirst($type) }} Voucher
                <small class="text-muted" id="edit_voucher_label"></small>
            </h2>
          </header>
          <div class="card-body">
            <div class="row">
              <div class="col-lg-6 mb-2">
                <label>Date</label>
                <input type="date" class="form-control" name="date" id="edit_date" required>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Account Debit <span class="text-danger">*</span></label>
                <select class="form-control select2-js" name="ac_dr_sid" id="edit_ac_dr_sid" required>
                  <option value="" disabled>Select Account</option>
                  @foreach($accounts as $acc)
                    <option value="{{ $acc->id }}">{{ $acc->name }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Account Credit <span class="text-danger">*</span></label>
                <select class="form-control select2-js" name="ac_cr_sid" id="edit_ac_cr_sid" required>
                  <option value="" disabled>Select Account</option>
                  @foreach($accounts as $acc)
                    <option value="{{ $acc->id }}">{{ $acc->name }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Amount <span class="text-danger">*</span></label>
                <input type="number" class="form-control" name="amount" id="edit_amount" step="any" required>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Attachments <small class="text-muted">(leave blank to keep existing)</small></label>
                <input type="file" class="form-control" name="att[]" multiple
                       accept=".zip,application/pdf,image/png,image/jpeg">
              </div>
              <div class="col-lg-12 mb-2">
                <label>Remarks</label>
                <textarea rows="3" class="form-control" name="remarks" id="edit_remarks"></textarea>
              </div>
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Update Voucher</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>

    {{-- DELETE MODAL --}}
    <div id="deleteModal" class="modal-block modal-block-warning mfp-hide">
      <section class="card">
        <form method="POST" id="deleteForm">
          @csrf
          @method('DELETE')
          <header class="card-header">
            <h2 class="card-title">Delete Voucher</h2>
          </header>
          <div class="card-body">
            <p class="mb-0">
                Are you sure you want to delete
                <strong id="delete_voucher_label"></strong>?
                This cannot be undone.
            </p>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-danger">Yes, Delete</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>

  </div>
</div>

<style>
body.modal-open-noscroll {
    overflow: hidden !important;
    padding-right: var(--scrollbar-width, 0px);
}
body.modal-open-noscroll section.body,
body.modal-open-noscroll .inner-wrapper,
body.modal-open-noscroll .content-body,
body.modal-open-noscroll main,
body.modal-open-noscroll .page-wrapper {
    overflow: hidden !important;
}
.mfp-wrap { z-index: 10000 !important; }
.mfp-bg   { z-index: 9999  !important; }
</style>

<script>
// ── Scroll lock helpers ──────────────────────────────────────────
function getScrollbarWidth() {
    var d = document.createElement('div');
    d.style.cssText = 'width:100px;height:100px;overflow:scroll;position:absolute;top:-9999px';
    document.body.appendChild(d);
    var w = d.offsetWidth - d.clientWidth;
    document.body.removeChild(d);
    return w;
}
function preventScroll(e) {
    var mc = document.querySelector('.mfp-content');
    if (mc && mc.contains(e.target)) return;
    e.preventDefault();
}
function lockScroll() {
    document.documentElement.style.setProperty('--scrollbar-width', getScrollbarWidth() + 'px');
    document.body.classList.add('modal-open-noscroll');
    document.addEventListener('wheel',     preventScroll, { passive: false });
    document.addEventListener('touchmove', preventScroll, { passive: false });
}
function unlockScroll() {
    document.body.classList.remove('modal-open-noscroll');
    document.documentElement.style.removeProperty('--scrollbar-width');
    document.removeEventListener('wheel',     preventScroll);
    document.removeEventListener('touchmove', preventScroll);
}

// ── Focus trap helpers ───────────────────────────────────────────
var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
var _trapHandler = null;

function trapFocus(modalEl) {
    var els = Array.from(modalEl.querySelectorAll(FOCUSABLE))
                   .filter(function(el){ return el.offsetParent !== null; });
    if (!els.length) return;
    var first = els[0], last = els[els.length - 1];
    setTimeout(function(){ first.focus(); }, 60);
    if (_trapHandler) document.removeEventListener('keydown', _trapHandler);
    _trapHandler = function(e) {
        if (e.key !== 'Tab') return;
        if (e.shiftKey) { if (document.activeElement === first) { e.preventDefault(); last.focus(); } }
        else            { if (document.activeElement === last)  { e.preventDefault(); first.focus(); } }
    };
    document.addEventListener('keydown', _trapHandler);
}
function releaseTrap() {
    if (_trapHandler) { document.removeEventListener('keydown', _trapHandler); _trapHandler = null; }
}

// ── Central modal opener — all modals use this ───────────────────
function openMfpModal(src) {
    $.magnificPopup.open({
        items: { src: src },
        type: 'inline',
        callbacks: {
            open: function() {
                lockScroll();
                trapFocus(this.content[0]);
            },
            close: function() {
                releaseTrap();
                unlockScroll();
            }
        }
    });
}

// ── EDIT MODAL ───────────────────────────────────────────────────
// FIX: set form action BEFORE opening modal, fetch data first,
// then open so Select2 values are populated when modal appears.
function openEditModal(id) {
    document.getElementById('editForm').action        = '/vouchers/{{ $type }}/' + id;
    document.getElementById('edit_voucher_label').textContent = '#' + id;

    fetch('/vouchers/{{ $type }}/' + id, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        document.getElementById('edit_date').value    = data.date;
        document.getElementById('edit_amount').value  = data.amount;
        document.getElementById('edit_remarks').value = data.remarks || '';
        // Trigger Select2 update after modal is in DOM
        $('#edit_ac_dr_sid').val(data.ac_dr_sid).trigger('change');
        $('#edit_ac_cr_sid').val(data.ac_cr_sid).trigger('change');
        openMfpModal('#editModal');
    })
    .catch(function() {
        alert('Could not load voucher data. Please try again.');
    });
}

// ── DELETE MODAL ─────────────────────────────────────────────────
// FIX: set form action and open programmatically — previously the
// anchor used href="#deleteModal" without modal-with-form class,
// so Magnific never initialised it as a popup.
function openDeleteModal(id) {
    document.getElementById('deleteForm').action              = '/vouchers/{{ $type }}/' + id;
    document.getElementById('delete_voucher_label').textContent = 'Voucher #' + id;
    openMfpModal('#deleteModal');
}

// ── Escape key cleanup ───────────────────────────────────────────
$(document).on('keydown', function(e) {
    if (e.key === 'Escape' && document.body.classList.contains('modal-open-noscroll')) {
        releaseTrap();
        unlockScroll();
    }
});

// ── Add modal — still uses theme class-based open ────────────────
$(document).on('click', '.modal-with-form', function() {
    setTimeout(function() {
        var modal = document.querySelector('.mfp-content .modal-block');
        if (modal) { lockScroll(); trapFocus(modal); }
    }, 80);
});
$(document).on('click', '.modal-dismiss, .mfp-close', function() {
    releaseTrap();
    unlockScroll();
});

// ── DataTable + Select2 ──────────────────────────────────────────
$(document).ready(function() {
    $('.select2-js').select2({ width: '100%' });
    $('#voucher-datatable').DataTable({
        pageLength: 50,
        order: [[0, 'desc']],
    });
});
</script>
@endsection