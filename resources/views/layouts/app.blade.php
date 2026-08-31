<!DOCTYPE html>
<html lang="en" class="fixed js flexbox flexboxlegacy no-touch csstransforms csstransforms3d no-overflowscrolling webkit chrome win js no-mobile-device custom-scroll sidebar-left-collapsed">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
        <title>@yield('title', 'Default Title')</title>
        <link rel="shortcut icon" href="{{ asset('assets/img/favicon.png') }}">
        <meta name="csrf-token" content="{{ csrf_token() }}">

		<link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700,800|Shadows+Into+Light" rel="stylesheet" type="text/css">

		<link rel="stylesheet" href="{{ asset('assets/vendor/bootstrap/css/bootstrap.css') }}" />
		<link rel="stylesheet" href="{{ asset('/assets/vendor/animate/animate.compat.css') }}" />
		<link rel="stylesheet" href="{{ asset('/assets/vendor/font-awesome/css/all.min.css') }}" />
		<link rel="stylesheet" href="{{ asset('/assets/vendor/boxicons/css/boxicons.min.css') }}" />
		<link rel="stylesheet" href="{{ asset('/assets/vendor/magnific-popup/magnific-popup.css') }}" />
		<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/css/bootstrap-datepicker.min.css" />
		<link rel="stylesheet" href="{{ asset('/assets/vendor/datatables/media/css/dataTables.bootstrap5.css') }}" />
		<link rel="stylesheet" href="{{ asset('/assets/vendor/select2/css/select2.css') }}" />
		<link rel="stylesheet" href="{{ asset('/assets/vendor/select2-bootstrap-theme/select2-bootstrap.min.css') }}" />
		<link rel="stylesheet" href="{{ asset('/assets/vendor/bootstrap-multiselect/css/bootstrap-multiselect.css') }}" />
		<link rel="stylesheet" href="{{ asset('/assets/vendor/dropzone/basic.css') }}"/>
		<link rel="stylesheet" href="{{ asset('/assets/vendor/dropzone/dropzone.css') }}" />
        <link rel="stylesheet" href="{{ asset('/assets/css/theme.css') }}" />
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
        <link rel="stylesheet" href="{{ asset('/assets/css/skins/default.css') }}" />
        <link rel="stylesheet" href="{{ asset('/assets/css/custom.css') }}" />

        <style>
            #loader {
                position: fixed;
                top: 0; left: 0;
                width: 100%; height: 100%;
                background: rgba(255, 255, 255, 0.8);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 9999;
            }
            #loader.hidden { display: none; }
            .cust-pad { padding-top: 0; }
            @media (min-width: 768px) {
                .cust-pad { padding: 60px 10px 0px 20px; }
                .home-cust-pad { padding: 60px 15px 0px 15px; }
                .sidebar-logo { width: 60%; height: auto; padding-top: 5px; }
            }
            @media (max-width: 767px) {
                .sidebar-logo { height: 40%; }
            }
            .icon-container {
                background-size: auto;
                background-repeat: no-repeat;
                background-position: right bottom;
            }
            /* Password field eye toggle */
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
    </head>
    <body>
        <div id="loader">
            <div class="spinner-border" role="status">
                <span class="sr-only">Loading...</span>
            </div>
        </div>

        {{-- ── Change Password Modal ─────────────────────────────── --}}
        <div id="changePassword" class="zoom-anim-dialog modal-block modal-block-danger mfp-hide">
            <section class="card">
                <form id="changePasswordForm" autocomplete="off"
                      onkeydown="return event.key != 'Enter';">
                    @csrf
                    <header class="card-header">
                        <h2 class="card-title">Change Password</h2>
                    </header>
                    <div class="card-body">
                        <div id="cp-alert" class="alert d-none mb-3"></div>
                        <div class="row form-group">

                            <div class="col-12 mb-3">
                                <label>Current Password</label>
                                <div class="pw-wrap">
                                    <input type="password" class="form-control"
                                           name="current_password" id="cp_current"
                                           placeholder="Current Password"
                                           autocomplete="current-password" required>
                                    <button type="button" class="pw-toggle" tabindex="-1"
                                            onclick="togglePw('cp_current', this)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-12 mb-3">
                                <label>New Password</label>
                                <div class="pw-wrap">
                                    <input type="password" class="form-control"
                                           name="new_password" id="cp_new"
                                           placeholder="New Password (min 8 chars)"
                                           minlength="8" autocomplete="new-password" required>
                                    <button type="button" class="pw-toggle" tabindex="-1"
                                            onclick="togglePw('cp_new', this)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-12 mb-2">
                                <label>Confirm New Password</label>
                                <div class="pw-wrap">
                                    <input type="password" class="form-control"
                                           name="new_password_confirmation" id="cp_confirm"
                                           placeholder="Confirm New Password"
                                           minlength="8" autocomplete="new-password" required>
                                    <button type="button" class="pw-toggle" tabindex="-1"
                                            onclick="togglePw('cp_confirm', this)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                        </div>
                    </div>
                    <footer class="card-footer">
                        <div class="col-md-12 text-end">
                            <button type="submit" id="cp-submit-btn" class="btn btn-primary">
                                Change Password
                            </button>
                            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
                        </div>
                    </footer>
                </form>
            </section>
        </div>

        <header class="page-header">
            <div class="logo-container d-none d-md-block">
                <div id="userbox" class="userbox" style="float:right !important;">
                    <a href="#" data-bs-toggle="dropdown" style="margin-right: 20px;">
                        <div class="profile-info">
                            <span class="name">{{ session('user_name') }}</span>
                            <span class="role">{{ session('role_name') }}</span>
                        </div>
                        <i class="fa custom-caret"></i>
                    </a>
                    <div class="dropdown-menu">
                        <ul class="list-unstyled">
                            <li>
                                <a role="menuitem" tabindex="-1"
                                   href="#changePassword"
                                   class="mb-1 mt-1 me-1 modal-with-zoom-anim ws-normal">
                                    <i class="bx bx-lock"></i> Change Password
                                </a>
                            </li>
                            <li>
                                <form action="/logout" method="POST">
                                    @csrf
                                    <button style="background:transparent;border:none;font-size:14px;"
                                            type="submit" role="menuitem" tabindex="-1">
                                        <i class="bx bx-power-off"></i> Logout
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="logo-container d-md-none">
                <a href="/" class="logo">
                    <img class="pt-2" src="/assets/img/billtrix-logo-black.png" width="35%" alt="Logo" />
                </a>
                <div id="userbox" class="userbox" style="float:right !important;">
                    <a href="#" data-bs-toggle="dropdown" style="margin-right: 20px;">
                        <div class="profile-info">
                            <span class="name">{{ session('user_name') }}</span>
                            <span class="role">{{ session('role_name') }}</span>
                        </div>
                        <i class="fa custom-caret"></i>
                    </a>
                    <div class="dropdown-menu">
                        <ul class="list-unstyled">
                            <li>
                                <a role="menuitem" tabindex="-1"
                                   href="#changePassword"
                                   class="mb-1 mt-1 me-1 modal-with-zoom-anim ws-normal">
                                    <i class="bx bx-lock"></i> Change Password
                                </a>
                            </li>
                        </ul>
                    </div>
                    <i class="fas fa-bars toggle-sidebar-left"
                       data-toggle-class="sidebar-left-opened"
                       data-target="html"
                       data-fire-event="sidebar-left-opened"
                       aria-label="Toggle sidebar"></i>
                </div>
            </div>
        </header>

        <section class="body">
            <div class="inner-wrapper cust-pad">
                @include('layouts.sidebar')
                <section role="main" class="content-body">
                    @yield('content')
                </section>
            </div>
        </section>

        <footer>
            @include('layouts.footer')
            <div class="text-end">
                <div>Powered By <a target="_blank" href="https://syitrix.com/">SyiTrix</a></div>
            </div>
        </footer>

        <script>
        // ── Toggle password visibility ────────────────────────────
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

        // ── Change Password — intercept at the BUTTON level, not form level ──
        //
        // ROOT CAUSE of the double-submit:
        // The Porto theme calls form.submit() programmatically on modal-block
        // forms after its own processing. Listening on the form's 'submit' event
        // catches the first fire but the theme's programmatic form.submit() call
        // is a second, separate dispatch that also resolves. Since form.reset()
        // runs after success (clearing the fields), the second submission sends
        // empty fields → server validates → returns "Current password is incorrect"
        // (or required errors) → JS shows the error message over the success one.
        //
        // FIX: Attach to the button's 'click' event instead, manually collect and
        // POST the data, and disable the submit button BEFORE anything async runs.
        // Also remove the form's native submit capability entirely so the theme
        // can never trigger a real form submission.
        (function () {
            var form = document.getElementById('changePasswordForm');

            // Neutralise native form submission entirely —
            // the theme cannot trigger a second POST this way
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                e.stopImmediatePropagation();
            }, true); // capture phase — fires before theme listeners

            document.getElementById('cp-submit-btn')
                .addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopImmediatePropagation();

                    var btn     = this;
                    var alertEl = document.getElementById('cp-alert');
                    var csrf    = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

                    // Bail if already in flight
                    if (btn.dataset.submitting === '1') return;

                    // Basic client-side check: new passwords match
                    var newPw  = document.getElementById('cp_new').value;
                    var confPw = document.getElementById('cp_confirm').value;
                    if (newPw !== confPw) {
                        alertEl.className   = 'alert alert-danger';
                        alertEl.textContent = 'Passwords do not match.';
                        return;
                    }
                    if (newPw.length < 8) {
                        alertEl.className   = 'alert alert-danger';
                        alertEl.textContent = 'New password must be at least 8 characters.';
                        return;
                    }

                    // Lock button BEFORE fetch — this is the key guard
                    btn.dataset.submitting = '1';
                    btn.disabled           = true;
                    btn.textContent        = 'Saving…';
                    alertEl.className      = 'alert d-none';
                    alertEl.textContent    = '';

                    // Build payload manually from named inputs
                    var payload = new FormData();
                    payload.append('_token',                   csrf);
                    payload.append('current_password',         document.getElementById('cp_current').value);
                    payload.append('new_password',             newPw);
                    payload.append('new_password_confirmation', confPw);

                    fetch('/change-my-password', {
                        method : 'POST',
                        headers: {
                            'X-CSRF-TOKEN'     : csrf,
                            'Accept'           : 'application/json',
                            'X-Requested-With' : 'XMLHttpRequest',
                        },
                        body: payload,
                    })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data.success) {
                            alertEl.className   = 'alert alert-success';
                            alertEl.textContent = data.message || 'Password changed successfully.';

                            // Clear fields manually (not form.reset — avoids triggering submit)
                            ['cp_current', 'cp_new', 'cp_confirm'].forEach(function (id) {
                                var el  = document.getElementById(id);
                                el.value = '';
                                el.type  = 'password';
                            });
                            form.querySelectorAll('.pw-toggle i').forEach(function (icon) {
                                icon.className = 'fas fa-eye';
                            });

                            setTimeout(function () {
                                if (typeof $.magnificPopup !== 'undefined') {
                                    $.magnificPopup.close();
                                }
                                alertEl.className = 'alert d-none';
                            }, 1500);

                        } else {
                            var msgs = [];
                            if (data.errors) {
                                msgs = Array.isArray(data.errors)
                                    ? data.errors
                                    : Object.values(data.errors).flat();
                            }
                            alertEl.className   = 'alert alert-danger';
                            alertEl.textContent = msgs.join(' ') || 'Something went wrong.';
                        }
                    })
                    .catch(function () {
                        alertEl.className   = 'alert alert-danger';
                        alertEl.textContent = 'Network error. Please try again.';
                    })
                    .finally(function () {
                        btn.disabled           = false;
                        btn.textContent        = 'Change Password';
                        btn.dataset.submitting = '0';
                    });
                });
        })();
        </script>

    </body>
</html>