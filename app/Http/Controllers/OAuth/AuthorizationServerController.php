<?php

namespace Pterodactyl\Http\Controllers\OAuth;

use Illuminate\Http\JsonResponse;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Acl\Api\OAuthScopeAcl;

class AuthorizationServerController extends Controller
{
    /**
     * Returns the authorization server metadata document described by RFC 8414. A
     * client reads this document to discover the endpoints it needs before it starts
     * an authorization code flow against the Panel, so it must remain reachable
     * without any authentication.
     *
     * There is no "registration_endpoint" advertised: clients are registered by the
     * administrator of the Panel rather than dynamically.
     *
     * The endpoints are built from the configured application URL rather than from the
     * incoming request. This Panel trusts every proxy in front of it, which makes an
     * X-Forwarded-Host header, or even a bare Host header if nothing restricts it,
     * authoritative for what the request appears to have arrived on. route() resolves
     * against that, so calling it directly here would hand back endpoints pointing at
     * whatever host the caller claimed rather than at the Panel a client actually has
     * to reach. Asking route() for the path only, with its third argument, keeps the
     * path following the route if it is ever renamed or moved, while the host is taken
     * from configuration and cannot be influenced by the request at all. The "issuer"
     * field has always been built this way, and the two endpoints now match it exactly
     * for the same reason.
     */
    public function __invoke(): JsonResponse
    {
        $appUrl = rtrim(config('app.url'), '/');

        return new JsonResponse([
            'issuer' => $appUrl,
            'authorization_endpoint' => $appUrl . route('passport.authorizations.authorize', [], false),
            'token_endpoint' => $appUrl . route('passport.token', [], false),
            'scopes_supported' => array_keys(OAuthScopeAcl::scopes()),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
        ]);
    }
}
