# OAuth 2.1 Authorization Server

The Panel doubles as an OAuth 2.1 authorization server so that an external
application, such as an MCP host, can act on behalf of a real Panel user instead
of sharing a static API key. Authorization is provided by
[Laravel Passport](https://laravel.com/docs/passport).

An access token issued this way is bound to the user that approved it:

* The client API is available to every token that carries a `client:*` scope.
* The application API is available only while the account is still an
  administrator. That is re-checked on every single request, so demoting an
  administrator immediately stops their existing tokens from reaching it.

## Scopes

| Scope          | Grants                                                                    |
| -------------- | ------------------------------------------------------------------------- |
| `client:read`  | `GET` and `HEAD` requests against `/api/client`.                           |
| `client:write` | Every other method against `/api/client`.                                  |
| `admin:read`   | Read requests against `/api/application`. Administrators only.             |
| `admin:write`  | Write requests against `/api/application`. Administrators only.            |

The two `admin` scopes are refused at the consent screen for an account that is
not an administrator, so a token carrying them can never be created for a
regular user.

Regardless of the scopes granted, an OAuth access token is refused on the routes
that hand out or replace account credentials. That covers API key management, SSH
key management, and changing an email address or password. Those endpoints
remain available to API keys and to the Panel front-end as before.

## Deployment

Run these once, in this order, after deploying:

```bash
# 1. Generate the signing keys. Nothing can be issued or verified without them.
php artisan passport:keys

# 2. Publish and run the migrations that back the authorization server.
php artisan vendor:publish --tag=passport-migrations
php artisan migrate

# 3. Optional, only if the defaults need changing.
php artisan vendor:publish --tag=passport-config
```

The keys are written to `storage/oauth-private.key` and `storage/oauth-public.key`.
Deployments that cannot persist those files may instead supply their contents
through the `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` environment
variables.

Until the keys exist the `oauth` guard resolves to nobody, which means OAuth
simply does not work yet. API keys, sessions, and the error responses for
unauthenticated requests are unaffected, so upgrading without running the command
degrades rather than breaks.

Installations that cache their routes need to run `php artisan route:cache` again
after upgrading. The authorization server metadata route and the middleware added
to Passport's consent endpoints are captured when the cache is built, so a cache
generated before this change will not contain them.

## Registering a client

The Panel does not implement dynamic client registration (RFC 7591). An
administrator registers each client once:

```bash
php artisan passport:client --public
```

Answer the prompts with a name for the client and the redirect URI the
application listens on. Give the resulting client id to the user, who configures
it in their MCP host. Public clients hold no secret and must use PKCE.

## Discovery

Clients bootstrap from the authorization server metadata document defined by
RFC 8414, which is served without authentication:

```
GET /.well-known/oauth-authorization-server
```

It advertises the issuer, the authorization and token endpoints, the four scopes
above, the supported grant types, and `S256` as the only PKCE code challenge
method.

## Revoking access

To revoke all tokens issued to a specific OAuth client, set the `revoked` flag
on the corresponding row in the `oauth_clients` table to `1`:

```sql
UPDATE oauth_clients SET revoked = 1 WHERE id = <client-id>;
```

All existing tokens for that client become invalid immediately - the `oauth`
guard checks the flag on every request, so the client cannot refresh or reuse
already-issued tokens. This is the only documented way to revoke a client.

There is no per-token or per-user revocation surface yet. Revoking access to a
specific token requires revoking the entire client.

## Two-factor authentication

The consent screen is behind the same two-factor requirement as the rest of the
Panel. An account that is required to enroll in two-factor authentication and has
not done so cannot approve an authorization request, and any token it already
holds is refused on every API request until it enrolls.
