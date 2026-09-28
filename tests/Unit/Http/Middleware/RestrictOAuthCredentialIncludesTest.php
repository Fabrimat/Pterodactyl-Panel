<?php

namespace Pterodactyl\Tests\Unit\Http\Middleware;

use Illuminate\Http\Request;
use Pterodactyl\Tests\TestCase;
use Pterodactyl\Http\Middleware\Api\RestrictOAuthCredentialIncludes;

class RestrictOAuthCredentialIncludesTest extends TestCase
{
    /**
     * The include list is read the way ApplicationApiController and Fractal read it, so a
     * credential cannot be asked for in a shape the check does not recognise.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('includeProvider')]
    public function testCredentialIncludesAreRecognised(string $method, array $parameters, bool $expected): void
    {
        $request = Request::create('/api/application/servers/1/databases', $method, $parameters);

        $this->assertSame($expected, RestrictOAuthCredentialIncludes::requestsACredential($request));
    }

    /**
     * A name that differs from the include only in case is not matched. Fractal matches
     * include names exactly, so it would never run that include either.
     */
    public static function includeProvider(): array
    {
        return [
            'no include' => ['GET', [], false],
            'another include' => ['GET', ['include' => 'host'], false],
            'the password include' => ['GET', ['include' => 'password'], true],
            'among other includes' => ['GET', ['include' => 'host,password'], true],
            'padded with whitespace' => ['GET', ['include' => 'host, password '], true],
            'with modifiers' => ['GET', ['include' => 'password:limit(1|0)'], true],
            'nested under another include' => ['GET', ['include' => 'databases.password'], true],
            'nested behind a modifier' => ['GET', ['include' => 'databases:limit(1).password'], true],
            'nested behind an empty modifier' => ['GET', ['include' => 'databases:.password'], true],
            'a modifier naming it' => ['GET', ['include' => 'host:password'], false],
            'as an array' => ['GET', ['include' => ['host', 'password']], true],
            'in a request body' => ['POST', ['include' => 'password'], true],
            'a different case' => ['GET', ['include' => 'PASSWORD'], false],
            'a longer name' => ['GET', ['include' => 'passwords'], false],
        ];
    }
}
