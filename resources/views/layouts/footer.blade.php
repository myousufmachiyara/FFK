<!-- jQuery -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.4/jquery.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.nanoscroller/0.8.7/jquery.nanoscroller.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-placeholder/2.3.1/jquery.placeholder.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/magnific-popup.js/1.1.0/jquery.magnific-popup.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.13.2/jquery-ui.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui-touch-punch/0.2.3/jquery.ui.touch-punch.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.appear/0.4.1/jquery.appear.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.4/moment.min.js"></script>
<script src="{{ asset('/assets/vendor/bootstrapv5-multiselect/js/bootstrap-multiselect.js') }}"></script>
<script src="{{ asset('/assets/vendor/dropzone/dropzone.js') }}"></script>

<!-- Vendor -->
<script src="{{ asset('/assets/vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('/assets/vendor/select2/js/select2.js') }}"></script>

<!-- Theme Base, Components and Settings -->
<script src="{{ asset('/assets/js/theme.js') }}"></script>

<!-- Theme Custom -->
<script src="{{ asset('/assets/js/custom.js') }}"></script>

<!-- Examples -->
<script src="{{ asset('/assets/js/examples/examples.header.menu.js') }}"></script>
<script src="{{ asset('/assets/js/examples/examples.dashboard.js') }}"></script>
<script src="{{ asset('/assets/js/examples/examples.datatables.default.js') }}"></script>
<script src="{{ asset('/assets/js/examples/examples.modals.js') }}"></script>

<!-- Theme Initialization Files -->
<script src="{{ asset('/assets/js/theme.init.js') }}"></script>
<script src="{{ asset('/assets/vendor/jquery-nestable/jquery.nestable.js') }}"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@fingerprintjs/fingerprintjs@3/dist/fp.min.js"></script>
<script>
    document.getElementById('changePasswordForm').addEventListener('submit', function(e) {
        e.preventDefault();
    
        const form    = this;
        const btn     = document.getElementById('cp-submit-btn');
        const alert   = document.getElementById('cp-alert');
        const data    = new FormData(form);
    
        btn.disabled     = true;
        btn.textContent  = 'Saving...';
        alert.className  = 'alert d-none';
    
        fetch('/change-my-password', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: data,
        })
        .then(async res => {
            // Laravel returns redirect on success, JSON-like on validation fail
            // We handle both by checking status
            if (res.redirected || res.ok) {
                const text = await res.text();
    
                // Check if response contains validation errors
                const parser = new DOMParser();
                const doc    = parser.parseFromString(text, 'text/html');
                const errors = doc.querySelectorAll('.invalid-feedback, .alert-danger li');
    
                if (errors.length > 0) {
                    let msgs = [];
                    errors.forEach(el => msgs.push(el.textContent.trim()));
                    alert.className   = 'alert alert-danger';
                    alert.textContent = msgs.join(' | ');
                } else {
                    alert.className   = 'alert alert-success';
                    alert.textContent = 'Password changed successfully!';
                    form.reset();
                    setTimeout(() => $.magnificPopup.close(), 1500);
                }
            }
        })
        .catch(() => {
            alert.className   = 'alert alert-danger';
            alert.textContent = 'Something went wrong. Please try again.';
        })
        .finally(() => {
            btn.disabled    = false;
            btn.textContent = 'Change Password';
        });
    });
</script>