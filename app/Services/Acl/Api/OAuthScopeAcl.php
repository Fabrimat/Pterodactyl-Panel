<?php

namespace Pterodactyl\Services\Acl\Api;

use Pterodactyl\Models\User;
use Illuminate\Support\Facades\Auth;

class OAuthScopeAcl
{
    /**
     * The scopes that are registered with the authorization server. They are
     * deliberately coarse: the "client" scopes cover the client API and the
     * "admin" scopes cover the application API.
     */
    public const CLIENT_READ = 'client:read';
    public const CLIENT_WRITE = 'client:write';
    public const ADMIN_READ = 'admin:read';
    public const ADMIN_WRITE = 'admin:write';

    /**
     * The guard that the authorization server issues tokens for, as registered in
     * "config/auth.php" and used by the "api" middleware group.
     */
    public const GUARD = 'oauth';

    /**
     * Determine if the current request was authenticated by the OAuth guard.
     *
     * The authentication middleware records the guard that accepted the request, so
     * this is the only thing that positively identifies an OAuth request. Inspecting
     * the attached token cannot: a request may legitimately carry an API key, a
     * transient session token, or no token at all, and every one of those was
     * authenticated somewhere else in the chain and is not scoped.
     */
    public static function isOAuthRequest(): bool
    {
        return Auth::getDefaultDriver() === self::GUARD;
    }

    /**
     * Returns every scope keyed by its identifier, with the description that is
     * displayed to the user on the authorization consent screen.
     */
    public static function scopes(): array
    {
        return [
            self::CLIENT_READ => 'Read your account details and the servers you have access to.',
            self::CLIENT_WRITE => 'Manage the servers you have access to.',
            self::ADMIN_READ => 'Read any resource on the Panel using the application API.',
            self::ADMIN_WRITE => 'Create, modify, and delete any resource on the Panel using the application API.',
        ];
    }

    /**
     * Returns the scopes that may only ever be granted to an administrator.
     */
    public static function administrative(): array
    {
        return [self::ADMIN_READ, self::ADMIN_WRITE];
    }

    /**
     * Determine if any of the requested scopes may only be issued to an administrator.
     * The wildcard scope is included since it would otherwise carry every scope we
     * have registered along with it.
     *
     * @param string[] $scopes
     */
    public static function requiresAdministrator(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if ($scope === '*' || in_array($scope, self::administrative(), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if an access token was granted the given scope. Passport hands the
     * guard a token object rather than a model, so we ask the token itself whenever
     * it is able to answer and only fall back to reading the raw scope list.
     */
    public static function tokenCan(mixed $token, string $scope): bool
    {
        if (is_object($token) && method_exists($token, 'can')) {
            return (bool) $token->can($scope);
        }

        $granted = (array) data_get($token, 'scopes', []);

        return in_array('*', $granted, true) || in_array($scope, $granted, true);
    }

    /**
     * Determine if an OAuth access token is allowed to perform an application API
     * request at the given permission level. The resource is accepted to mirror
     * AdminAcl::check() but is not used to pick a scope. Access to the application
     * API as a whole is gated on the account being an administrator, which is
     * re-checked on every request by the AuthenticateApplicationUser middleware.
     */
    public static function check(User $user, mixed $token, string $resource, int $permission): bool
    {
        if (!$user->root_admin) {
            return false;
        }

        if (($permission & AdminAcl::READ) && self::tokenCan($token, self::ADMIN_READ)) {
            return true;
        }

        if (($permission & AdminAcl::WRITE) && self::tokenCan($token, self::ADMIN_WRITE)) {
            return true;
        }

        return false;
    }
}
