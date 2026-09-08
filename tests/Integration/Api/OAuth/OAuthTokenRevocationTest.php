<?php

namespace Pterodactyl\Tests\Integration\Api\OAuth;

use Illuminate\Support\Str;
use Pterodactyl\Models\User;
use Laravel\Passport\Passport;
use Illuminate\Database\Migrations\Migration;
use Pterodactyl\Services\Users\UserUpdateService;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

/**
 * Exercises Pterodactyl\Listeners\OAuthRevocationListener against real rows in
 * oauth_access_tokens and oauth_refresh_tokens.
 *
 * Passport only publishes its migrations - PassportServiceProvider::registerPublishing()
 * calls publishesMigrations(), never loadMigrationsFrom() - so they never end up in this
 * repository's database/migrations, and bootstrap/tests.php runs "migrate:fresh" against
 * that directory only. Neither table exists under test unless something creates it. Every
 * other OAuth integration test avoids the problem by authenticating through
 * Passport::actingAs(), which mocks the guard and never writes a row anywhere; that route
 * is not available here because the whole point of this test is to prove a real UPDATE
 * statement reaches real rows. The two migrations this depends on are required directly
 * from the vendor package instead and run by hand around each test.
 *
 * This deliberately extends IntegrationTestCase rather than OAuthIntegrationTestCase, so
 * that it is not wrapped in DatabaseTransactions. A CREATE TABLE or DROP TABLE causes an
 * implicit commit on both MySQL and MariaDB, and running that DDL inside a transaction
 * DatabaseTransactions already opened would silently end that transaction early - every
 * row created afterwards, starting with the users this test creates, would never be
 * rolled back and would leak into the shared test database. Staying out of that trait
 * avoids the interaction entirely; everything this test writes is removed by hand in
 * tearDown() instead, and the two tables are recreated empty for every test method.
 */
class OAuthTokenRevocationTest extends IntegrationTestCase
{
    private static ?Migration $accessTokensMigration = null;
    private static ?Migration $refreshTokensMigration = null;

    /** @var int[] */
    private array $createdUserIds = [];

    public function setUp(): void
    {
        parent::setUp();

        if (self::$accessTokensMigration === null) {
            self::$accessTokensMigration = require_once base_path('vendor/laravel/passport/database/migrations/2016_06_01_000002_create_oauth_access_tokens_table.php');
            self::$refreshTokensMigration = require_once base_path('vendor/laravel/passport/database/migrations/2016_06_01_000003_create_oauth_refresh_tokens_table.php');
        }

        self::$accessTokensMigration->up();
        self::$refreshTokensMigration->up();
    }

    public function tearDown(): void
    {
        // The row cleanup runs first and the table teardown must happen regardless of
        // whether it succeeds - otherwise a failure here would leave both tables behind
        // for the rest of this job's run. That is still self-healing, since the next
        // run's "migrate:fresh" wipes everything, but there is no reason to rely on that
        // when a finally block is free.
        try {
            User::query()->whereIn('id', $this->createdUserIds)->delete();
        } finally {
            self::$refreshTokensMigration->down();
            self::$accessTokensMigration->down();
        }

        parent::tearDown();
    }

    /**
     * A password change revokes both tables for the user whose password changed, and
     * leaves another user's tokens completely alone. UserUpdateService is called
     * directly, rather than dispatching PasswordChanged by hand, so this proves the real
     * production path - the same one the client API and the admin area use - actually
     * reaches the listener.
     */
    public function testPasswordChangeRevokesThatUsersAccessAndRefreshTokens(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser();

        $accessTokenId = $this->createAccessToken($user);
        $refreshTokenId = $this->createRefreshToken($accessTokenId);

        $otherAccessTokenId = $this->createAccessToken($otherUser);
        $otherRefreshTokenId = $this->createRefreshToken($otherAccessTokenId);

        app(UserUpdateService::class)->handle($user, ['password' => 'a totally different password']);

        $this->assertTrue($this->isAccessTokenRevoked($accessTokenId));
        $this->assertTrue($this->isRefreshTokenRevoked($refreshTokenId));

        $this->assertFalse($this->isAccessTokenRevoked($otherAccessTokenId));
        $this->assertFalse($this->isRefreshTokenRevoked($otherRefreshTokenId));
    }

    /**
     * Deleting the user model revokes the same two tables. The model is deleted
     * directly, rather than dispatching Deleting by hand, so this proves the real
     * observer wiring (User::observe(UserObserver::class) firing the event on the
     * Eloquent "deleting" hook) reaches the listener, not just the listener in
     * isolation.
     */
    public function testUserDeletionRevokesThatUsersAccessAndRefreshTokens(): void
    {
        $user = $this->createUser();

        $accessTokenId = $this->createAccessToken($user);
        $refreshTokenId = $this->createRefreshToken($accessTokenId);

        $user->delete();

        $this->assertTrue($this->isAccessTokenRevoked($accessTokenId));
        $this->assertTrue($this->isRefreshTokenRevoked($refreshTokenId));
    }

    /**
     * Every other test in this suite runs without oauth_access_tokens or
     * oauth_refresh_tokens ever existing - that is the normal state for an
     * installation that has not published and migrated Passport, and
     * PasswordChanged fires unconditionally regardless. Dropping the tables this
     * class's own setUp() just created, then running the real password-change path
     * against them missing, proves the listener notices and returns instead of
     * turning an ordinary password change into a SQLSTATE[42S02] error.
     */
    public function testPasswordChangeIsANoOpWhenOAuthTablesDoNotExist(): void
    {
        self::$refreshTokensMigration->down();
        self::$accessTokensMigration->down();

        $user = $this->createUser();
        $originalPassword = $user->password;

        app(UserUpdateService::class)->handle($user, ['password' => 'a totally different password']);

        $this->assertNotSame($originalPassword, $user->refresh()->password);
    }

    private function createUser(): User
    {
        /** @var User $user */
        $user = User::factory()->create();

        $this->createdUserIds[] = $user->id;

        return $user;
    }

    private function createAccessToken(User $user, bool $revoked = false): string
    {
        $id = (string) Str::uuid();

        Passport::token()->forceFill([
            'id' => $id,
            'user_id' => $user->id,
            'client_id' => (string) Str::uuid(),
            'revoked' => $revoked,
        ])->save();

        return $id;
    }

    private function createRefreshToken(string $accessTokenId, bool $revoked = false): string
    {
        $id = (string) Str::uuid();

        Passport::refreshToken()->forceFill([
            'id' => $id,
            'access_token_id' => $accessTokenId,
            'revoked' => $revoked,
        ])->save();

        return $id;
    }

    private function isAccessTokenRevoked(string $id): bool
    {
        return (bool) Passport::token()->newQuery()->whereKey($id)->first()?->revoked;
    }

    private function isRefreshTokenRevoked(string $id): bool
    {
        return (bool) Passport::refreshToken()->newQuery()->whereKey($id)->first()?->revoked;
    }
}
