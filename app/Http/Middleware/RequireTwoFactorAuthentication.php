<?php

namespace Pterodactyl\Http\Middleware;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Exceptions\Http\TwoFactorAuthRequiredException;

class RequireTwoFactorAuthentication
{
    public const LEVEL_NONE = 0;
    public const LEVEL_ADMIN = 1;
    public const LEVEL_ALL = 2;

    /**
     * The route to redirect a user to enable 2FA.
     */
    protected string $redirectRoute = '/account';

    /**
     * RequireTwoFactorAuthentication constructor.
     */
    public function __construct(private AlertsMessageBag $alert)
    {
    }

    /**
     * Check the user state on the incoming request to determine if they should be allowed to
     * proceed or not. This checks if the Panel is configured to require 2FA on an account in
     * order to perform actions. If so, we check the level at which it is required (all users
     * or just admins) and then check if the user has enabled it for their account.
     *
     * @throws TwoFactorAuthRequiredException
     */
    public function handle(Request $request, \Closure $next): mixed
    {
        $user = $request->user();
        $uri = rtrim($request->getRequestUri(), '/') . '/';
        $current = $request->route()->getName();

        if (!$user || Str::startsWith($uri, ['/auth/']) || Str::startsWith($current, ['auth.', 'account.'])) {
            return $next($request);
        }

        $level = (int) config('pterodactyl.auth.2fa_required');
        // If the user is already using 2FA then we can just send them right through, nothing
        // else needs to be checked.
        if ($user->use_totp) {
            return $next($request);
        }

        // A per-user override takes precedence over the global requirement level. If it is
        // not set, fall back to the global level logic as before.
        if ($user->require_2fa !== null) {
            $required = $user->require_2fa;
        } else {
            $required = !($level === self::LEVEL_NONE || ($level === self::LEVEL_ADMIN && !$user->root_admin));
        }

        if (!$required) {
            return $next($request);
        }

        // For API calls return an exception which gets rendered nicely in the API response.
        if ($request->isJson() || Str::startsWith($uri, '/api/')) {
            throw new TwoFactorAuthRequiredException();
        }

        $this->alert->danger(trans('auth.2fa_must_be_enabled'))->flash();

        return redirect()->to($this->redirectRoute);
    }
}
