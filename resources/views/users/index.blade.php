@extends('layouts.app')

@section('title', 'Users | All Users')

@section('content')

@if (session('success'))
    <div class="alert alert-success alert-dismissible">
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        {{ session('success') }}
    </div>
@elseif (session('error'))
    <div class="alert alert-danger alert-dismissible">
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        {{ session('error') }}
    </div>
@endif

<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header d-flex justify-content-between">
        <h2 class="card-title">Users</h2>
        <div>
          {{-- Add user still uses theme class — no form action conflict here --}}
          <a href="#addModal" class="modal-with-form btn btn-primary">
            <i class="fas fa-plus"></i> Add User
          </a>
        </div>
      </header>

      <div class="card-body">
        <div class="modal-wrapper table-scroll">
          <table class="table table-bordered table-striped mb-0" id="users-datatable">
            <thead>
              <tr>
                <th>#</th>
                <th>Name</th>
                <th>Username</th>
                <th>Role(s)</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @foreach($users as $index => $user)
                <tr>
                  <td>{{ $index + 1 }}</td>
                  <td>{{ $user->name }}</td>
                  <td>{{ $user->username ?? 'N/A' }}</td>
                  <td>{{ $user->roles->pluck('name')->join(', ') }}</td>
                  <td>
                    <span class="badge {{ $user->is_active ? 'bg-success' : 'bg-secondary' }}">
                        {{ $user->is_active ? 'Active' : 'Inactive' }}
                    </span>
                  </td>
                  <td class="actions">
                    {{-- Edit --}}
                    <a href="javascript:void(0)"
                       class="text-primary me-1"
                       onclick="openEditModal({{ $user->id }})"
                       title="Edit User">
                      <i class="fa fa-edit"></i>
                    </a>

                    {{-- Activate / Deactivate --}}
                    <a href="javascript:void(0)"
                       class="text-{{ $user->is_active ? 'danger' : 'success' }} me-1"
                       onclick="openActivateModal({{ $user->id }}, {{ $user->is_active ? 'false' : 'true' }})"
                       title="{{ $user->is_active ? 'Deactivate' : 'Activate' }} User">
                      <i class="fa fa-toggle-{{ $user->is_active ? 'on' : 'off' }}"></i>
                    </a>

                    {{-- Change Password --}}
                    {{-- FIX: use javascript:void(0) + openPasswordModal()
                         The original used href="#passwordModal" with modal-with-form
                         which made the theme intercept the form submit and post to
                         "#passwordModal" instead of the actual /users/{id}/change-password URL --}}
                    <a href="javascript:void(0)"
                       class="text-warning me-1"
                       onclick="openPasswordModal({{ $user->id }})"
                       title="Change Password">
                      <i class="fa fa-key"></i>
                    </a>

                    {{-- Delete --}}
                    <form action="{{ route('users.destroy', $user->id) }}"
                          method="POST" class="d-inline"
                          onsubmit="return confirm('Delete {{ addslashes($user->name) }}? This cannot be undone.')">
                      @csrf
                      @method('DELETE')
                      <button type="submit" class="btn btn-link p-0 m-0 text-danger" title="Delete">
                        <i class="fas fa-trash-alt"></i>
                      </button>
                    </form>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </section>

    {{-- ── ADD USER MODAL ────────────────────────────────────── --}}
    <div id="addModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form action="{{ route('users.store') }}" method="POST"
              enctype="multipart/form-data" onkeydown="return event.key != 'Enter';">
          @csrf
          <header class="card-header">
            <h2 class="card-title">Add User</h2>
          </header>
          <div class="card-body">
            <div class="mb-2">
              <label>Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required />
            </div>
            <div class="mb-2">
              <label>Username <span class="text-danger">*</span></label>
              <input type="text" name="username" class="form-control"
                     autocomplete="off" required />
            </div>
            <div class="mb-2">
              <label>Password <span class="text-danger">*</span></label>
              <div class="pw-wrap">
                <input type="password" name="password" id="add_password" class="form-control"
                       autocomplete="new-password" required />
                <button type="button" class="pw-toggle" tabindex="-1" onclick="togglePw('add_password', this)">
                    <i class="fas fa-eye"></i>
                </button>
              </div>
            </div>
            <div class="mb-2">
              <label>Confirm Password <span class="text-danger">*</span></label>
              <div class="pw-wrap">
                <input type="password" name="password_confirmation" id="add_password_confirm"
                       class="form-control" autocomplete="new-password" required />
                <button type="button" class="pw-toggle" tabindex="-1" onclick="togglePw('add_password_confirm', this)">
                    <i class="fas fa-eye"></i>
                </button>
              </div>
            </div>
            <div class="mb-2">
              <label>Role <span class="text-danger">*</span></label>
              <select name="role" class="form-control" required>
                <option value="">-- Select Role --</option>
                @foreach($roles as $role)
                  <option value="{{ $role->id }}">{{ $role->name }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Create User</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>

    {{-- ── EDIT USER MODAL ───────────────────────────────────── --}}
    {{-- FIX: action set by openEditModal() before modal opens --}}
    <div id="editModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form id="editUserForm" method="POST"
              enctype="multipart/form-data" onkeydown="return event.key != 'Enter';">
          @csrf
          @method('PUT')
          <header class="card-header">
            <h2 class="card-title">Edit User <small class="text-muted" id="edit_user_label"></small></h2>
          </header>
          <div class="card-body">
            <div class="mb-2">
              <label>Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="edit_name" class="form-control" required>
            </div>
            <div class="mb-2">
              <label>Username <span class="text-danger">*</span></label>
              <input type="text" name="username" id="edit_username" class="form-control" required>
            </div>
            <div class="mb-2">
              <label>Role <span class="text-danger">*</span></label>
              <select name="role" id="edit_role" class="form-control" required>
                <option value="">-- Select Role --</option>
                @foreach($roles as $role)
                  <option value="{{ $role->id }}">{{ $role->name }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Update User</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>

    {{-- ── CHANGE PASSWORD MODAL ─────────────────────────────── --}}
    {{-- FIX: action set by openPasswordModal() before modal opens.
         Original used modal-with-form which made the theme post to
         "#passwordModal" instead of /users/{id}/change-password --}}
    <div id="passwordModal" class="modal-block modal-block-warning mfp-hide">
      <section class="card">
        <form id="passwordForm" method="POST"
              enctype="multipart/form-data" onkeydown="return event.key != 'Enter';">
          @csrf
          @method('PUT')
          <header class="card-header">
            <h2 class="card-title">Change Password <small class="text-muted" id="pw_user_label"></small></h2>
          </header>
          <div class="card-body">
            <div id="pw-alert" class="alert d-none mb-2"></div>
            <div class="mb-3">
              <label>New Password <span class="text-danger">*</span></label>
              <div class="pw-wrap">
                <input type="password" name="password" id="pw_new"
                       class="form-control" required minlength="6"
                       autocomplete="new-password" placeholder="Min 6 characters" />
                <button type="button" class="pw-toggle" tabindex="-1" onclick="togglePw('pw_new', this)">
                    <i class="fas fa-eye"></i>
                </button>
              </div>
            </div>
            <div class="mb-2">
              <label>Confirm Password <span class="text-danger">*</span></label>
              {{-- FIX: field name must be password_confirmation for 'confirmed' rule --}}
              <div class="pw-wrap">
                <input type="password" name="password_confirmation" id="pw_confirm"
                       class="form-control" required minlength="6"
                       autocomplete="new-password" placeholder="Repeat new password" />
                <button type="button" class="pw-toggle" tabindex="-1" onclick="togglePw('pw_confirm', this)">
                    <i class="fas fa-eye"></i>
                </button>
              </div>
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-warning">Change Password</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>

    {{-- ── ACTIVATE / DEACTIVATE MODAL ──────────────────────── --}}
    <div id="activateModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form id="activateForm" method="POST" onkeydown="return event.key != 'Enter';">
          @csrf
          @method('PUT')
          <header class="card-header">
            <h2 class="card-title" id="activate_title">Activate / Deactivate User</h2>
          </header>
          <div class="card-body">
            <p id="activate_message">Are you sure you want to change the status of this user?</p>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary" id="activate_btn">Yes, proceed</button>
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
body.modal-open-noscroll main {
    overflow: hidden !important;
}
.mfp-wrap { z-index: 10000 !important; }
.mfp-bg   { z-index: 9999  !important; }

/* Password eye toggle */
.pw-wrap { position: relative; }
.pw-wrap .form-control { padding-right: 2.5rem; }
.pw-toggle {
    position: absolute;
    top: 50%; right: 10px;
    transform: translateY(-50%);
    background: none; border: none;
    padding: 0; cursor: pointer;
    color: #999; font-size: 14px;
    line-height: 1; z-index: 5;
}
.pw-toggle:hover { color: #444; }
</style>

<script>
// ── Password show/hide toggle ────────────────────────────────────
function togglePw(fieldId, btn) {
    var input = document.getElementById(fieldId);
    var icon  = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

// ── Scroll lock ──────────────────────────────────────────────────
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

// ── Focus trap ───────────────────────────────────────────────────
var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled])';
var _trap = null;
function trapFocus(el) {
    var els = Array.from(el.querySelectorAll(FOCUSABLE)).filter(function(e){ return e.offsetParent !== null; });
    if (!els.length) return;
    var first = els[0], last = els[els.length - 1];
    setTimeout(function(){ first.focus(); }, 60);
    if (_trap) document.removeEventListener('keydown', _trap);
    _trap = function(e) {
        if (e.key !== 'Tab') return;
        if (e.shiftKey) { if (document.activeElement === first) { e.preventDefault(); last.focus(); } }
        else            { if (document.activeElement === last)  { e.preventDefault(); first.focus(); } }
    };
    document.addEventListener('keydown', _trap);
}
function releaseTrap() {
    if (_trap) { document.removeEventListener('keydown', _trap); _trap = null; }
}

// ── Central modal open ───────────────────────────────────────────
function openMfpModal(src) {
    $.magnificPopup.open({
        items: { src: src },
        type: 'inline',
        callbacks: {
            open:  function() { lockScroll(); trapFocus(this.content[0]); },
            close: function() { releaseTrap(); unlockScroll(); }
        }
    });
}

$(document).on('click', '.modal-dismiss, .mfp-close', function() { releaseTrap(); unlockScroll(); });
$(document).on('keydown', function(e) {
    if (e.key === 'Escape' && document.body.classList.contains('modal-open-noscroll')) {
        releaseTrap(); unlockScroll();
    }
});
// Add modal uses theme class — hook scroll lock onto it
$(document).on('click', '.modal-with-form', function() {
    setTimeout(function() {
        var m = document.querySelector('.mfp-content .modal-block');
        if (m) { lockScroll(); trapFocus(m); }
    }, 80);
});

// ── EDIT USER ────────────────────────────────────────────────────
function openEditModal(id) {
    fetch('/users/' + id, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(res) { return res.json(); })
    .then(function(res) {
        if (!res.status) { alert('User not found.'); return; }
        var u = res.data;
        document.getElementById('editUserForm').action       = '/users/' + u.id;
        document.getElementById('edit_user_label').textContent = '— ' + u.name;
        document.getElementById('edit_name').value           = u.name;
        document.getElementById('edit_username').value       = u.username;
        document.getElementById('edit_role').value           = u.roles[0]?.id || '';
        openMfpModal('#editModal');
    })
    .catch(function() { alert('Error loading user details.'); });
}

// ── CHANGE PASSWORD ──────────────────────────────────────────────
// FIX: sets form action to the real route BEFORE opening modal.
// The old code used modal-with-form on the trigger link which caused
// the Porto theme to intercept the form submit and POST to
// "#passwordModal" (the href value) instead of the actual route.
function openPasswordModal(id) {
    document.getElementById('passwordForm').action       = '/users/' + id + '/change-password';
    document.getElementById('pw_user_label').textContent = '— User #' + id;
    // Clear fields and reset eye icons
    ['pw_new', 'pw_confirm'].forEach(function(fid) {
        var el = document.getElementById(fid);
        el.value = '';
        el.type  = 'password';
    });
    document.querySelectorAll('#passwordModal .pw-toggle i').forEach(function(icon) {
        icon.className = 'fas fa-eye';
    });
    document.getElementById('pw-alert').className = 'alert d-none';
    openMfpModal('#passwordModal');
}

// ── ACTIVATE / DEACTIVATE ────────────────────────────────────────
function openActivateModal(userId, activate) {
    document.getElementById('activateForm').action = '/users/' + userId + '/toggle-active';

    var title   = document.getElementById('activate_title');
    var message = document.getElementById('activate_message');
    var btn     = document.getElementById('activate_btn');

    if (activate) {
        title.textContent   = 'Activate User';
        message.textContent = 'Are you sure you want to activate this user?';
        btn.textContent     = 'Activate';
        btn.className       = 'btn btn-success';
    } else {
        title.textContent   = 'Deactivate User';
        message.textContent = 'Are you sure you want to deactivate this user?';
        btn.textContent     = 'Deactivate';
        btn.className       = 'btn btn-danger';
    }

    openMfpModal('#activateModal');
}

// ── DataTable ────────────────────────────────────────────────────
$(document).ready(function() {
    $('#users-datatable').DataTable({
        pageLength: 25,
        order: [[0, 'asc']],
    });
});
</script>
@endsection