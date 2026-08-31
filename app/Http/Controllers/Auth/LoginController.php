<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/';

    public function __construct()
    {
        $this->middleware('guest')->except('logout');
        $this->middleware('auth')->only('logout');
    }

    public function username()
    {
        return 'username'; // 👤 use username instead of email
    }

    /**
     * Attempt to log the user into the application.
     */
    protected function attemptLogin(Request $request)
    {
        if ($this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);
            return $this->sendLockoutResponse($request);
        }

        $login = $this->guard()->attempt(
            $this->credentials($request),
            $request->filled('remember')
        );

        if (! $login) {
            $this->incrementLoginAttempts($request); // 🚨 Count failed login
            return false;
        }

        // ✅ Credentials matched — now check if account is active
        $user = $this->guard()->user();

        if (! $user->is_active) {
            $this->guard()->logout(); // Kick them out immediately
            \Log::warning('🚫 Inactive user attempted login.', [
                'username' => $request->input($this->username()),
                'ip'       => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                $this->username() => ['Your account has been deactivated. Please contact the administrator.'],
            ]);
        }

        $this->clearLoginAttempts($request); // ✅ Reset on success
        return true;
    }

    /**
     * Define login throttling rules.
     */
    protected function hasTooManyLoginAttempts(Request $request)
    {
        // Allow 4 attempts within 1 minute
        return $this->limiter()->tooManyAttempts(
            $this->throttleKey($request), 4, 1
        );
    }

    /**
     * Response when user is locked out.
     */
    protected function sendLockoutResponse(Request $request)
    {
        $seconds = $this->limiter()->availableIn(
            $this->throttleKey($request)
        );

        \Log::warning('🚫 User locked out due to too many login attempts.', [
            'username' => $request->input($this->username()),
            'ip'       => $request->ip(),
            'wait'     => $seconds . 's',
        ]);

        throw ValidationException::withMessages([
            $this->username() => [trans('auth.throttle', ['seconds' => $seconds])],
        ]);
    }

    /**
     * Generic failed login response.
     */
    protected function sendFailedLoginResponse(Request $request)
    {
        throw ValidationException::withMessages([
            $this->username() => [__('auth.failed')], // Always generic
        ]);
    }
}
