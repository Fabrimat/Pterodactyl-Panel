# OAuth 2.1 Authorization Server

The Panel doubles as an OAuth 2.1 authorization server so that an external
application, such as an MCP host, can act on behalf of a real Panel user instead
of sharing a static API key. Authorization is provided by
[Laravel Passport](https://laravel.com/docs/passport).

The Panel is also the OAuth resource server for its own MCP endpoint at `/mcp`
(see [`MCP.md`](MCP.md)), which is the reason an access token issued here is
useful in the first place: it is a bearer credential the endpoint accepts
alongside a plain API key.

An access token issued this way is bound to the user that approved it:

* The client API is available to every token that carries a `client:*` scope.
* The application API is available only while the account is still an
  administrator. That is re-checked on every single request, so demoting an
  administrator immediately stops their existing tokens from reaching it.

## Scopes

| Scope          | Grants                                                                    |
| -------------- | ------------------------------------------------------------------------- |
| `client:read`  | `GET` and `HEAD` requests against `/api/client`, except those listed below. |
| `client:write` | Every other method against `/api/client`.                                  |
| `admin:read`   | Read requests against `/api/application`. Administrators only.             |
| `admin:write`  | Write requests against `/api/application`. Administrators only.            |

The HTTP method is not on its own a reliable statement of what a request does. A
handful of `GET` routes answer with a credential rather than with a description
of state, and those require `client:write` despite the method:

* `GET /api/client/servers/{server}/files/upload` returns a signed URL that the
  daemon accepts as authority to write arbitrary files to the server.
* `GET /api/client/servers/{server}/websocket` returns a token carrying the
  user's full permission set for that server, which the daemon honours for
  console commands and power actions.

The same rule is applied on the application API, where each request declares the
permission it requires. Reading a node's configuration is a `GET` that discloses
the node's daemon token, and it asks for write access for that reason.

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

`passport:keys` writes the private key readable only by the user that ran the
command. Where Artisan and PHP-FPM run as different users that leaves the web
process unable to read it, and issuing or verifying a token then fails even
though the files are plainly there. The official Docker image is exactly this
case: Artisan runs as `root` while PHP-FPM runs as `nginx`. Hand the keys over
after generating them:

```bash
# Substitute whichever user PHP-FPM runs as.
chown nginx:nginx storage/oauth-private.key storage/oauth-public.key
chmod 600 storage/oauth-private.key
```

An installation where `php artisan` and PHP-FPM run as the same user, which is
the normal bare-metal case, needs none of this.

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

The device authorization flow is not offered. The Panel has no use for it, and
the middleware that keeps a regular account from approving administrative scopes
reads the requested scopes off the authorization request, which a device
approval does not carry. Enabling the grant would therefore create a second
consent path that the scope restriction does not cover, so the grant is turned
off before Passport registers its routes and `passport:client` does not offer to
enable it.

## Discovery

Clients bootstrap from the authorization server metadata document defined by
RFC 8414, which is served without authentication:

```
GET /.well-known/oauth-authorization-server
```

It advertises the issuer, the authorization and token endpoints, the four scopes
above, the supported grant types, and `S256` as the only PKCE code challenge
method. That last claim is enforced and not merely advertised: an authorization
request carrying a code challenge is refused unless its method is `S256`. A
request that supplies a challenge without naming a method is refused as well,
because the underlying library treats an absent method as `plain` rather than as
an error.

Every URL in this document, and in the protected-resource document and the
`WWW-Authenticate` challenge that lead a client to it, is built from `APP_URL`
rather than from the host the request arrived on. A client can rely on them
regardless of what sits in front of the Panel.

A client that starts from `/mcp` instead finds its way here through the RFC
9728 protected-resource metadata for that endpoint, served without
authentication at `/.well-known/oauth-protected-resource` and named as the
`resource_metadata` parameter of the `WWW-Authenticate` challenge on a 401 from
`/mcp`. See [`MCP.md`](MCP.md) for that document and the endpoint it describes.

## Revoking access

**To cut off a user's tokens, change that user's password.** Changing a password
revokes every OAuth access token and every OAuth refresh token that account
holds. The access tokens stop working at once, and the refresh tokens cannot
mint replacements, so an attacker holding a stolen token loses it rather than
being able to renew it for the remaining thirty days of its refresh window.

This happens on every path a password changes through: the client API, the admin
area, and the forgotten-password reset flow. Deleting an account does the same.

Revocation is keyed on the account whose password changed, not on whoever made
the change, and there is deliberately no carve-out for the token the request is
authenticated with. On the client API the question does not arise, because the
password and email routes refuse OAuth tokens outright. Through the application
API an `admin:write` token is accepted, so an administrator changing their own
password loses that token along with the rest, which is the intended outcome:
the case this exists for is the one where the caller might be the attacker. An
administrator changing someone else's password revokes that user's tokens and
keeps their own.

Two things this does not cover. Outstanding authorization codes, which live for
ten minutes, are not revoked. Enrolling in two-factor authentication does not
revoke anything, because it is a hardening step rather than a compromise signal.

To revoke every token issued to a specific OAuth client instead, set the
`revoked` flag on the corresponding row in the `oauth_clients` table to `1`:

```sql
UPDATE oauth_clients SET revoked = 1 WHERE id = <client-id>;
```

All existing tokens for that client become invalid immediately - the `oauth`
guard checks the flag on every request, so the client cannot refresh or reuse
already-issued tokens.

There is still no way to revoke one token in isolation. The levers are a single
user's tokens, by changing their password, or an entire client.

## Two-factor authentication

The consent screen is behind the same two-factor requirement as the rest of the
Panel. An account that is required to enroll in two-factor authentication and has
not done so cannot approve an authorization request, and any token it already
holds is refused on every API request until it enrolls.
