<?php

namespace Pterodactyl\Tests\Unit\Http\Middleware;

use Pterodactyl\Services\Acl\Api\OAuthScopeAcl;
use Pterodactyl\Http\Middleware\RestrictAdminOAuthScopes;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class RestrictAdminOAuthScopesTest extends MiddlewareTestCase
{
    /**
     * An account that is not an administrator must never be able to approve a grant
     * carrying an administrative scope.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('administrativeScopeProvider')]
    public function testNonAdminCannotGrantAdministrativeScopes(string $scope): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->generateRequestUserModel(['root_admin' => false]);
        $this->setRequestScope($scope);

        $this->getMiddleware()->handle($this->request, $this->getClosureAssertions());
    }

    /**
     * The wildcard scope carries every registered scope with it and is treated the
     * same way as an explicit administrative scope.
     */
    public function testNonAdminCannotGrantTheWildcardScope(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->generateRequestUserModel(['root_admin' => false]);
        $this->setRequestScope('client:read *');

        $this->getMiddleware()->handle($this->request, $this->getClosureAssertions());
    }

    /**
     * The client scopes are available to everybody.
     */
    public function testNonAdminCanGrantClientScopes(): void
    {
        $this->generateRequestUserModel(['root_admin' => false]);
        $this->setRequestScope(OAuthScopeAcl::CLIENT_READ . ' ' . OAuthScopeAcl::CLIENT_WRITE);

        $this->getMiddleware()->handle($this->request, $this->getClosureAssertions());
    }

    /**
     * An administrator may grant everything.
     */
    public function testAdminCanGrantAdministrativeScopes(): void
    {
        $this->generateRequestUserModel(['root_admin' => true]);
        $this->setRequestScope(OAuthScopeAcl::ADMIN_READ . ' ' . OAuthScopeAcl::ADMIN_WRITE);

        $this->getMiddleware()->handle($this->request, $this->getClosureAssertions());
    }

    /**
     * A request without any scope at all is left to Passport to deal with.
     */
    public function testRequestWithoutScopesIsPassedThrough(): void
    {
        $this->generateRequestUserModel(['root_admin' => false]);
        $this->setRequestScope(null);

        $this->getMiddleware()->handle($this->request, $this->getClosureAssertions());
    }

    /**
     * An unauthenticated request is passed through to the authentication middleware.
     */
    public function testUnauthenticatedRequestIsPassedThrough(): void
    {
        $this->setRequestUserModel(null);
        $this->setRequestScope(OAuthScopeAcl::ADMIN_WRITE);

        $this->getMiddleware()->handle($this->request, $this->getClosureAssertions());
    }

    /**
     * Scopes that may only ever be issued to an administrator.
     */
    public static function administrativeScopeProvider(): array
    {
        return [
            [OAuthScopeAcl::ADMIN_READ],
            [OAuthScopeAcl::ADMIN_WRITE],
            [OAuthScopeAcl::CLIENT_READ . ' ' . OAuthScopeAcl::ADMIN_READ],
        ];
    }

    /**
     * Set the scopes that the authorization request is asking for.
     */
    private function setRequestScope(?string $scope): void
    {
        $this->request->shouldReceive('input')->with('scope')->andReturn($scope);
    }

    /**
     * Return an instance of the middleware for testing.
     */
    private function getMiddleware(): RestrictAdminOAuthScopes
    {
        return new RestrictAdminOAuthScopes();
    }
}
