<?php

namespace Pterodactyl\Tests\Unit\Http\Middleware;

use Mockery as m;
use Mockery\MockInterface;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Exceptions\Http\TwoFactorAuthRequiredException;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;

class RequireTwoFactorAuthenticationTest extends MiddlewareTestCase
{
    private MockInterface $alert;

    /**
     * Setup tests.
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->alert = m::mock(AlertsMessageBag::class);
    }

    /**
     * With no per-user override the middleware must behave exactly as it did before the
     * override existed: driven entirely by the global requirement level.
     */
    public function testNullOverrideMatchesLegacyLevelBehavior()
    {
        foreach ($this->levels() as $level) {
            foreach ([true, false] as $rootAdmin) {
                $enforced = !($level === RequireTwoFactorAuthentication::LEVEL_NONE
                    || ($level === RequireTwoFactorAuthentication::LEVEL_ADMIN && !$rootAdmin));

                $this->assertOutcome($level, ['root_admin' => $rootAdmin, 'use_totp' => false, 'require_2fa' => null], $enforced);
            }
        }
    }

    /**
     * A user explicitly required to use 2FA is always enforced, even when the global
     * level would otherwise let them through.
     */
    public function testRequireTrueOverridesGlobalLevel()
    {
        foreach ($this->levels() as $level) {
            foreach ([true, false] as $rootAdmin) {
                $this->assertOutcome($level, ['root_admin' => $rootAdmin, 'use_totp' => false, 'require_2fa' => true], true);
            }
        }
    }

    /**
     * A user explicitly exempted from 2FA is never enforced, even when the global level
     * would otherwise require it.
     */
    public function testRequireFalseOverridesGlobalLevel()
    {
        foreach ($this->levels() as $level) {
            foreach ([true, false] as $rootAdmin) {
                $this->assertOutcome($level, ['root_admin' => $rootAdmin, 'use_totp' => false, 'require_2fa' => false], false);
            }
        }
    }

    /**
     * A user that already has 2FA enabled is never blocked, regardless of the override
     * value or the global requirement level.
     */
    public function testUseTotpAlwaysPasses()
    {
        foreach ($this->levels() as $level) {
            foreach ([true, false] as $rootAdmin) {
                foreach ([null, true, false] as $require2fa) {
                    $this->assertOutcome($level, ['root_admin' => $rootAdmin, 'use_totp' => true, 'require_2fa' => $require2fa], false);
                }
            }
        }
    }

    /**
     * JSON and API requests receive an exception rather than a redirect when the 2FA
     * requirement is enforced.
     */
    public function testJsonRequestThrowsException()
    {
        config()->set('pterodactyl.auth.2fa_required', RequireTwoFactorAuthentication::LEVEL_NONE);

        $this->generateRequestUserModel(['root_admin' => false, 'use_totp' => false, 'require_2fa' => true]);
        $this->setRequestRouteName('index');
        $this->request->shouldReceive('getRequestUri')->andReturn('/api/client');
        $this->request->shouldReceive('isJson')->andReturn(true);

        $this->expectException(TwoFactorAuthRequiredException::class);

        $this->getMiddleware()->handle($this->request, $this->getClosureAssertions());
    }

    /**
     * The requirement levels that can be configured for the Panel.
     */
    private function levels(): array
    {
        return [
            RequireTwoFactorAuthentication::LEVEL_NONE,
            RequireTwoFactorAuthentication::LEVEL_ADMIN,
            RequireTwoFactorAuthentication::LEVEL_ALL,
        ];
    }

    /**
     * Run the middleware for a given global level and set of user attributes, asserting
     * whether the request was allowed through or the 2FA requirement was enforced.
     */
    private function assertOutcome(int $level, array $userAttributes, bool $expectEnforced): void
    {
        config()->set('pterodactyl.auth.2fa_required', $level);

        $this->buildRequestMock();

        $this->generateRequestUserModel($userAttributes);
        $this->setRequestRouteName('index');
        $this->request->shouldReceive('getRequestUri')->andReturn('/servers/1');

        if ($expectEnforced) {
            $this->request->shouldReceive('isJson')->andReturn(false);
            $this->alert->shouldReceive('danger->flash')->once();

            $response = $this->getMiddleware()->handle($this->request, $this->getClosureAssertions());
            $this->assertInstanceOf(RedirectResponse::class, $response);
        } else {
            $this->getMiddleware()->handle($this->request, $this->getClosureAssertions());
        }
    }

    private function getMiddleware(): RequireTwoFactorAuthentication
    {
        return new RequireTwoFactorAuthentication($this->alert);
    }
}
