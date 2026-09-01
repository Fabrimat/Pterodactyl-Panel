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
     */
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'issuer' => rtrim(config('app.url'), '/'),
            'authorization_endpoint' => route('passport.authorizations.authorize'),
            'token_endpoint' => route('passport.token'),
            'scopes_supported' => array_keys(OAuthScopeAcl::scopes()),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
        ]);
    }
}
