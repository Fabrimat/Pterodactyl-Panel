<?php

namespace Pterodactyl\Http\Controllers\OAuth;

use Illuminate\Http\JsonResponse;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Acl\Api\OAuthScopeAcl;

class ProtectedResourceController extends Controller
{
    /**
     * Returns the protected resource metadata document described by RFC 9728 for the MCP
     * endpoint. A client is pointed at this document by the WWW-Authenticate header on a
     * 401 from that endpoint and reads it to find the authorization server it has to get a
     * token from, so it must remain reachable without any authentication.
     *
     * Both identifiers are built from the configured application URL rather than from the
     * request. They have to be stable, and the entry in "authorization_servers" has to be
     * character for character the issuer that the authorization server metadata document
     * advertises, which is built the same way.
     */
    public function __invoke(): JsonResponse
    {
        $url = rtrim(config('app.url'), '/');

        return new JsonResponse([
            'resource' => $url . '/mcp',
            'authorization_servers' => [$url],
            'scopes_supported' => array_keys(OAuthScopeAcl::scopes()),
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Pterodactyl Panel',
        ]);
    }
}
