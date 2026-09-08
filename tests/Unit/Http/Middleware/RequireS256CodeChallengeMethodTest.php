<?php

namespace Pterodactyl\Tests\Unit\Http\Middleware;

use Pterodactyl\Http\Middleware\RequireS256CodeChallengeMethod;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class RequireS256CodeChallengeMethodTest extends MiddlewareTestCase
{
    /**
     * The only method the discovery document and OAUTH.md advertise must be the only
     * one the authorization endpoint actually accepts.
     */
    public function testS256ChallengeIsAllowed(): void
    {
        $this->setRequestCodeChallenge('a-challenge-value', 'S256');

        $this->getMiddleware()->handle($this->request, $this->getClosureAssertions());
    }

    /**
     * The library this grant is built on registers a "plain" verifier unconditionally
     * and accepts it, even though nothing in this Panel's documentation says it does.
     */
    public function testPlainChallengeIsRefused(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->setRequestCodeChallenge('a-challenge-value', 'plain');

        $this->getMiddleware()->handle($this->request, $this->getClosureAssertions());
    }

    /**
     * Any method other than S256 is refused the same way "plain" is, not just the
     * one the library happens to also support.
     */
    public function testUnknownChallengeMethodIsRefused(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->setRequestCodeChallenge('a-challenge-value', 'S1024');

        $this->getMiddleware()->handle($this->request, $this->getClosureAssertions());
    }

    /**
     * A code challenge with no method at all is the dangerous case: the library
     * defaults the method to "plain" instead of rejecting the request, so this has
     * to be refused exactly like an explicit "code_challenge_method=plain" is.
     */
    public function testChallengeWithoutAMethodIsRefused(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->setRequestCodeChallenge('a-challenge-value', null);

        $this->getMiddleware()->handle($this->request, $this->getClosureAssertions());
    }

    /**
     * A confidential client is allowed to omit PKCE entirely, and a public client
     * that omits it is already refused by the grant itself. Either way, a request
     * without a code challenge at all is not this middleware's concern.
     */
    public function testRequestWithoutACodeChallengeIsPassedThrough(): void
    {
        $this->setRequestCodeChallenge(null, null);

        $this->getMiddleware()->handle($this->request, $this->getClosureAssertions());
    }

    /**
     * The grant this middleware guards, AuthCodeGrant::validateAuthorizationRequest(),
     * reads both parameters with getQueryStringParameter(), which only ever looks at
     * the query string. On a GET request, Laravel's input() layers a JSON body over
     * the query string, so a request whose body claims "S256" while its query string
     * says "plain" would satisfy a check that reads input() while the grant still
     * mints a "plain" challenge. Stubbing input() to disagree with query() and
     * asserting the query string wins is what pins this middleware to query().
     */
    public function testQueryStringWinsOverADisagreeingBody(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->setRequestCodeChallenge('a-challenge-value', 'plain');
        $this->request->shouldReceive('input')->with('code_challenge_method', 'plain')->andReturn('S256');

        $this->getMiddleware()->handle($this->request, $this->getClosureAssertions());
    }

    /**
     * Set the code challenge and code challenge method the authorization request
     * carries in its query string, which is the only place the grant this middleware
     * guards reads them from. A null method means the parameter is absent from the
     * query string rather than present with an empty value.
     */
    private function setRequestCodeChallenge(?string $codeChallenge, ?string $codeChallengeMethod): void
    {
        $this->request->shouldReceive('query')->with('code_challenge')->andReturn($codeChallenge);
        $this->request->shouldReceive('query')->with('code_challenge_method', 'plain')->andReturn($codeChallengeMethod ?? 'plain');
    }

    /**
     * Return an instance of the middleware for testing.
     */
    private function getMiddleware(): RequireS256CodeChallengeMethod
    {
        return new RequireS256CodeChallengeMethod();
    }
}
