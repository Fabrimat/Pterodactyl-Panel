<?php

namespace Pterodactyl\Listeners;

use Laravel\Passport\Passport;
use Pterodactyl\Events\User\Deleting;
use Pterodactyl\Events\User\PasswordChanged;

/**
 * Closes the one revocation surface a password change or an account deletion left
 * untouched. Before this listener existed, changing a password revoked nothing on the
 * OAuth side at all: a stolen access token kept working for up to seven days, and its
 * refresh token for up to thirty, indefinitely renewable for as long as the holder kept
 * using it. Changing a password is the lever an operator pulls the moment they suspect
 * a token has been stolen, so it has to actually cut that token off rather than just
 * looking like it does.
 *
 * Both tables are reached through Passport::token() and Passport::refreshToken() rather
 * than through the user model. Pterodactyl\Models\User does not use Passport's
 * HasApiTokens trait for this; Pterodactyl\Models\Traits\HasAccessTokens wraps
 * Sanctum's trait instead and repoints tokens() at the ApiKey table, which is an
 * entirely different credential (a permanent API key, not an OAuth token). Going
 * through Passport's own model accessors instead of hardcoding Token/RefreshToken also
 * keeps this correct if an installation ever swaps those models out.
 *
 * No carve-out is made for the token the request is currently authenticated with. The
 * client API's credential routes already refuse OAuth tokens outright (see
 * AuthenticateOAuthScopes::PROTECTED_ROUTES), so a client API password change can only
 * arrive over a session or an API key. The admin API is not restricted the same way:
 * PATCH /api/application/users/{user} accepts a password field over an admin:write
 * OAuth token, so an administrator can change a password that way. That is not a gap.
 * Revocation is keyed on the account whose password changed rather than on whoever
 * made the change, so an administrator changing their own password that way loses the
 * token they made the change with, and one changing somebody else's password revokes
 * that account's tokens and keeps their own. Both are the correct outcome: the point
 * is to cut off the account whose credential just moved.
 *
 * Auth codes are deliberately left alone. They live for ten minutes and are consumed
 * the instant they are exchanged for a token pair, so by the time an operator reacts to
 * a suspected compromise any outstanding code has almost certainly already been
 * redeemed or expired on its own; a third statement here would buy almost nothing.
 * Device codes are left alone for an unrelated reason: that grant is disabled entirely
 * elsewhere in this change.
 */
class OAuthRevocationListener
{
    public function handle(Deleting|PasswordChanged $event): void
    {
        // OAuth is optional until an installation actually publishes and migrates
        // Passport's tables - the same stance OAuthServiceProvider::disableOAuthGuard()
        // already takes for the guard itself. PasswordChanged and Deleting fire on
        // every password change and every account deletion regardless of whether that
        // step was ever taken, so this listener cannot assume oauth_access_tokens or
        // oauth_refresh_tokens exist. On an installation that never ran the publish
        // step, or one that upgraded but has not migrated yet, querying either table
        // would turn an ordinary password change into a 500. No token can exist
        // without both tables, so doing nothing here is not a workaround, it is
        // correct: there is nothing to revoke.
        //
        // Both tables are checked, not just one. They come from two separate
        // migration files run as part of the same batch, and a batch that fails
        // partway through (the first file's migration commits, the second's does not)
        // would otherwise leave one table without the other.
        //
        // The result is not cached. Nothing here runs often enough for a schema probe
        // to matter - a password change or an account deletion, not a hot path like an
        // authenticated request - and this exact listener is covered by a test suite
        // that creates and drops these two tables around individual test methods
        // inside a single PHP process, which would make a process-level cache actively
        // wrong rather than merely wasteful.
        if (!$this->oauthTablesExist()) {
            return;
        }

        $userId = $event->user->id;

        // Refresh tokens are revoked before access tokens, not after, but that order
        // only decides which side of a narrow race survives - it does not close the
        // race. A refresh token is validated against its own "revoked" column only -
        // RefreshTokenGrant never consults the access token it was issued alongside -
        // so a refresh request that had already passed that check before this method
        // started, and that finishes persisting its new access/refresh pair in the gap
        // between the two statements below, produces a new access token that is still
        // caught (the second statement matches on user_id and does not care when the
        // row appeared), but a new refresh token that is not (the first statement
        // already ran before that row existed, and neither statement runs again to
        // catch it). Reversing the order would be strictly worse: the survivor would
        // then be the access token instead, which is directly usable against the API
        // right now, rather than a refresh token that still has to make one more
        // request before it is. Closing this completely would mean not revoking
        // historical rows at all, and instead checking every token against a per-user
        // "issued before this instant is void" watermark on every authenticated
        // request, the same way root_admin is already re-read on every request for the
        // admin-demotion case. That is a different mechanism, not a fix to this
        // listener, and is not what this change does.
        Passport::refreshToken()->newQuery()
            ->whereIn('access_token_id', Passport::token()->newQuery()
                ->where('user_id', $userId)
                ->select('id'))
            ->update(['revoked' => true]);

        Passport::token()->newQuery()
            ->where('user_id', $userId)
            ->update(['revoked' => true]);
    }

    private function oauthTablesExist(): bool
    {
        $accessTokens = Passport::token();
        $refreshTokens = Passport::refreshToken();

        return $accessTokens->getConnection()->getSchemaBuilder()->hasTable($accessTokens->getTable())
            && $refreshTokens->getConnection()->getSchemaBuilder()->hasTable($refreshTokens->getTable());
    }
}
