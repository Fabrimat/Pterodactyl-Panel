# Pterodactyl panel MCP server

An MCP (Model Context Protocol) server that exposes the panel's existing REST
APIs as tools. It is a thin, typed client over HTTP - it does not reimplement
any panel logic, and it adds no new panel endpoints. Everything it can do is
exactly what `/api/application` and `/api/client` already do.

Every tool maps 1:1 to a route in `routes/api-application.php` (Application
API, `panel_admin_*` tools) or `routes/api-client.php` (Client API,
`panel_client_*` tools), with one deliberate exception: the `/websocket`
route is not exposed, since a token plus a raw socket URL is not usable over
this server's stdio transport.

It ships two transports:

- **stdio**, authenticated with a static `PANEL_APPLICATION_KEY` /
  `PANEL_CLIENT_KEY` pair read from the environment. Meant for local use or a
  single admin/service account. See [Setup](#setup) below.
- **Streamable HTTP**, where each caller authenticates as themselves with an
  OAuth 2.1 bearer token and gets exactly the tools their own account and
  token allow. See [Streamable HTTP transport (OAuth)](#streamable-http-transport-oauth).

## Setup

```
cd mcp
npm install
npm run build
```

Configure the server with environment variables (see `.env.example`):

| Variable | Required | Description |
| --- | --- | --- |
| `PANEL_URL` | yes | Base URL of the panel, no trailing slash. |
| `PANEL_APPLICATION_KEY` | one of these two | Admin API key (`ptla_...`). Enables the `panel_admin_*` tools. |
| `PANEL_CLIENT_KEY` | one of these two | User API key (`ptlc_...`). Enables the `panel_client_*` tools. |
| `PANEL_MCP_READ_ONLY` | no | Set to `1` to only register GET (read-only) tools. |

At least one of `PANEL_APPLICATION_KEY` / `PANEL_CLIENT_KEY` must be set; the
server refuses to start with neither. The two keys are independent - set only
`PANEL_CLIENT_KEY` to get a user-scoped server with no admin capabilities at
all, or only `PANEL_APPLICATION_KEY` for an admin-only server, or both for
the full tool set. Keys are read from the environment only; there is no
config file and no tool parameter that accepts a key.

Run it with `npm start`, or point an MCP client at
`node <path-to-mcp>/dist/src/index.js` with the environment variables above
set in the client's server configuration. The server speaks stdio.

## Design

- `src/endpoints.ts` is a single declarative table: one row per route, each
  carrying its HTTP method, path template, and zod schemas for path
  parameters, query parameters and body fields.
- `src/tool-registry.ts` iterates that table and registers one MCP tool per
  matching row against a shared handler. There is no per-tool code and no
  generic `panel_request(method, path)` escape hatch. Both entry points
  (`src/index.ts` for stdio, `src/http.ts` for Streamable HTTP) call into
  this one module so they can't drift apart on how a row becomes a tool.
- `src/client.ts` is the only place that talks HTTP. It never exposes a raw
  request/response/error object - only a status code plus the panel's own
  JSON error body ever reach a tool result.

### Special cases handled in the row schema

- **Raw text bodies/responses.** `files/contents` returns plain text
  (`responseType: 'text'`), and `files/write` sends the new content as a raw
  `text/plain` body with the path in a `file` query parameter
  (`bodyType: 'text'`), not JSON.
- **Signed URLs.** `files/download`, `files/upload` and backup download
  return a one-time signed Wings URL in a normal JSON response. The tool
  hands that URL back as its result; it does not fetch or proxy the file
  itself, and each tool's description says so.
- **Pagination/filtering.** List tools accept `page` and `per_page`, and
  where the underlying controller supports it, `filter`, `sort` and
  `include` too.
- **Destructive actions.** GET tools are annotated `readOnlyHint: true`.
  Every other tool is treated as destructive by default (the MCP spec
  defaults `destructiveHint` to true when the annotation is absent, so this
  server never asserts `destructiveHint: false` on anything - that would
  tell a hint-gating client a merely-unflagged action needs no
  confirmation). The `destructive: true` flags in `endpoints.ts` don't
  change that; they document *why* a specific non-GET row is dangerous
  (server reinstall, `files/write`, `files/decompress`, backup restore,
  two-factor disable, database password/credential rotation,
  `panel_admin_users_update`).
- **Read-only mode.** `PANEL_MCP_READ_ONLY=1` skips registering every
  non-GET row.

## Streamable HTTP transport (OAuth)

`npm run start:http` runs the same tool table over MCP's Streamable HTTP
transport instead of stdio. The difference is identity: there is no shared
`PANEL_APPLICATION_KEY` / `PANEL_CLIENT_KEY` for this transport. Every caller
presents their own OAuth 2.1 bearer token, that token is forwarded to the
panel verbatim on every call, and the tools a session gets depend on who the
token belongs to.

This is deliberate token passthrough, not the token-exchange pattern the MCP
spec generally recommends: it's correct here because the authorization
server (the panel) and the upstream API (also the panel) are the same trust
domain. There is no vault, no exchange, and no refresh-token handling in
this server - when the panel rejects a token, the MCP client is expected to
re-run the OAuth flow and start a new session.

### Prerequisites

The panel is the OAuth 2.1 authorization server via Laravel Passport. To set
up the authorization infrastructure, deploy the panel with the migrations and
keys described in the panel's [OAUTH.md](../OAUTH.md).

For each MCP host that will connect:

1. Register an OAuth client for the MCP host using the panel's Passport admin:
   `php artisan passport:client --public`. This is a manual, one-time step per
   MCP host (Passport has no Dynamic Client Registration). Give the operator a
   name for the client and a redirect URI pointing to where the MCP host's
   authorization callback lives.
2. Give the resulting client id to whoever configures their MCP host - the user
   pastes it into their host's OAuth client configuration alongside this
   server's URL. There is no separate client secret step for a public (PKCE)
   client.
3. Run this server with `PANEL_URL` pointing at the panel, since that is also
   treated as the OAuth issuer.

### Scopes

Exactly four, matching an API and an access level:

| Scope | Grants |
| --- | --- |
| `client:read` | Every `panel_client_*` GET tool. |
| `client:write` | Every other `panel_client_*` tool. |
| `admin:read` | Every `panel_admin_*` GET tool, if the user is also an admin. |
| `admin:write` | Every other `panel_admin_*` tool, if the user is also an admin. |

A token missing a scope does not get a degraded version of that scope's
tools - it does not get them registered at all, so they never show up in the
session's tool list.

### Per-session model

A session starts on the first `initialize` request. At that point, and only
then, this server calls `GET /api/client/account` once with the caller's own
token to find out who they are (in particular, the `admin` boolean the
panel's `AccountTransformer` returns). That result is cached for the life of
the session and is never re-fetched.

**Sessions require the `client:read` scope.** The identity call is made to
`/api/client/account`, which the panel refuses for tokens carrying only `admin`
scopes. This means an admin-scopes-only token (no `client:read` or
`client:write`) cannot open a session at all - the identity lookup will fail
with a 403, and the session will not start.

- A non-admin user gets the 68 `panel_client_*` tools, intersected with
  their token's scopes.
- An admin gets all 110 tools, intersected with their token's scopes.

Each session gets its **own** `McpServer` instance - not a shared one with
per-request filtering - specifically so one user's tool list and bearer
token can never leak into another user's session. Sessions are held in an
in-memory map keyed by the MCP session id; a session is torn down (and its
token discarded) when its transport closes, on an explicit session
termination, or after `PANEL_MCP_HTTP_SESSION_IDLE_MS` (default 30 minutes)
of inactivity, whichever comes first.

### Discovery and 401s

This server advertises itself as an OAuth-protected resource at
`/.well-known/oauth-protected-resource/mcp` (RFC 9728), naming the panel as
the authorization server. A request to `/mcp` with no bearer token, or one
that fails local validation, gets `401` with a `WWW-Authenticate: Bearer`
challenge that includes `resource_metadata`, so a spec-compliant MCP client
can discover the panel and start the OAuth flow on its own.

If the panel itself rejects the token during the one identity-resolution
call at session start - including because a forced-2FA panel is rejecting a
user who never enrolled TOTP - that panel error is surfaced verbatim (same
status code, same JSON body) rather than collapsed into a generic failure,
and a `401` specifically gets the same `WWW-Authenticate` challenge so the
client knows to re-authenticate rather than retry the same token.

### Running it

```
PANEL_URL=https://panel.example.com \
PANEL_MCP_HTTP_PORT=8089 \
PANEL_MCP_PUBLIC_URL=https://mcp.example.com/mcp \
npm run start:http
```

See `.env.example` for the full list of HTTP-transport variables.

## Scope: what this does NOT cover

This server can only do what the panel's REST API already exposes. As of
this API version, the Application API has **no endpoints at all** for:

- Panel-wide settings (mail, general config, etc.)
- Database hosts (the servers MySQL/PostgreSQL databases are created on)
- Mounts
- Creating, updating, importing or deleting nests or eggs (nest and egg
  routes are read-only: list and view only)

Adding tools for any of the above would require adding new PHP routes and
controllers to the panel, which is out of scope here - this server only
wraps what already exists.

## Two-factor authentication interaction

`RequireTwoFactorAuthentication` lives in the base `'api'` middleware group
(`app/Http/Kernel.php`), which wraps both `/api/application` and
`/api/client` equally in `RouteServiceProvider`. It is not part of the
`client-api` group, and it does not branch on which API is being called - it
only looks at the resolved user (`$request->user()`), which both `ptla_` and
`ptlc_` keys resolve identically since both are Sanctum personal access
tokens.

- At global 2FA level "all users", both key types are rejected identically
  if the key's owning user has not enrolled TOTP.
- At global level "admins only", an **Application API key is the exposed
  case, not the exempt one**: `ptla_` keys can only be created by root
  admins (`AuthenticateApplicationUser` rejects non-admins), so their owner
  is always in the targeted population. An ordinary non-admin `ptlc_` key
  owner is not required to have 2FA at this level.
- Either way, the fix for a service account is the per-user override: set
  that user's `require_2fa` to `false` (exempt), rather than changing the
  panel-wide setting.

`panel_admin_users_update` is not a partial update - the panel requires
`email`, `username`, `first_name` and `last_name` on every call, not just
the field being changed. To exempt a service account: first call
`panel_admin_users_view` for that user, then call `panel_admin_users_update`
with `require_2fa: false` plus that user's current `email`, `username`,
`first_name` and `last_name` echoed back unchanged. Omitting any of those
four fields gets the request rejected.

## Testing

```
npm test
```

Runs the build then `node --test` against the compiled tests. None of them
need a running panel - they stub it with a `fetch` mock:

- `test/redaction-check.ts` - the error path in `client.ts` never leaks the
  `Authorization` header or a raw request/error object into a tool result,
  even when the underlying HTTP client throws an axios-shaped error object
  that carries the header on `.config.headers`.
- `test/oauth-check.ts` - the protected-resource metadata is served and
  names the panel, and a missing or malformed bearer token gets `401` with a
  spec-shaped `WWW-Authenticate` challenge.
- `test/scope-gating-check.ts` - each of the four OAuth scopes registers
  exactly its expected subset of tools, and a token with a scope missing
  registers nothing from that subset.
- `test/session-isolation-check.ts` - two concurrent HTTP sessions for
  different identities get different tool counts and strictly separate
  cached tokens; tearing one down (explicitly or via the idle sweep) removes
  only that session's entry and token.
- `test/http-redaction-check.ts` - extends the redaction guarantee above to
  every HTTP-transport response path, including the one upstream call this
  server makes on session start.

These test files are named to avoid the repo-root Jest config, which has no
`roots`/`testMatch` override and would otherwise sweep up anything under
`__tests__/` or ending in `.test.ts` / `.spec.ts`.
