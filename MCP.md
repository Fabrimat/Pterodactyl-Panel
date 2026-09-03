# Model Context Protocol server

The Panel is itself an MCP (Model Context Protocol) server. There is no
separate process, no extra port and nothing to reverse proxy: an MCP client
talks to the same domain and the same web server as everything else on the
Panel, at a single route.

```
POST https://<panel>/mcp
```

The endpoint is stateless Streamable HTTP: one JSON-RPC request in, one JSON-RPC
response out, no `Mcp-Session-Id` and no server-initiated event stream. A tool
call is dispatched internally through the Panel's own HTTP kernel, so it
traverses the identical routing, authentication, permission and validation
stack a normal REST API request does. MCP can never grant what the REST API
would deny.

## Installation

There is none, beyond installing the Panel itself. The endpoint exists as soon
as this code is deployed - nothing to build, run or supervise separately.

Installations that cache their routes need to run `php artisan route:cache`
again after upgrading, since the `/mcp` route and the discovery routes below
are captured when the cache is built.

## Connecting a client

Point the client at `https://<panel>/mcp` and authenticate with a bearer
token:

```json
{
  "mcpServers": {
    "pterodactyl": {
      "url": "https://panel.example.com/mcp",
      "headers": {
        "Authorization": "Bearer ptlc_xxxxxxxxxxxxxxxxxxxxxxxx"
      }
    }
  }
}
```

Two kinds of bearer token work here:

* **A Pterodactyl API key.** A `ptlc_...` client key from the account page, or
  a `ptla_...` application key from the admin area. Simplest option: it is a
  permanent, unscoped credential tied to one account, with no separate setup.
  Good for a personal assistant or a script that already has a key.
* **An OAuth 2.1 access token.** Expiring, scoped to exactly the operations it
  was consented for, and revocable independently of any password change. Good
  for a shared or third-party MCP host acting on a user's behalf rather than
  under an operator's own key. See [`OAUTH.md`](OAUTH.md) for how to register a
  client and obtain a token; nothing about that flow is specific to MCP.

## stdio clients

An MCP host that only speaks stdio, not Streamable HTTP, cannot connect
directly. Bridge it with [`mcp-remote`](https://www.npmjs.com/package/mcp-remote):

```
npx mcp-remote https://panel.example.com/mcp --header "Authorization: Bearer ptlc_xxxxxxxxxxxxxxxxxxxxxxxx"
```

Earlier revisions of this feature bundled a standalone stdio server that
shipped its own copy of the endpoint table. That server has been removed:
keeping the same 117-row mapping of REST endpoint to tool in both TypeScript
and PHP would only ever drift the two apart. `mcp-remote` is the standard
bridge for exactly this situation and needs no code of the Panel's own to
maintain.

That server also had a `PANEL_MCP_READ_ONLY` switch, which is gone with it. An
OAuth token granted only `client:read` and `admin:read` gets a read-only tool
set and is the replacement. There is no equivalent for an API key: a key is
unscoped, so a caller holding one gets every tool the account can reach.

## What tools you get

Every caller gets the 68 client tools, named `panel_client_*`, covering the
same ground as the Client API: the servers the account can reach, console
access, power control, backups, files, databases, schedules, subusers and the
account's own settings.

An account that is a root administrator additionally gets the 49 application
tools, named `panel_admin_*`, covering the Application API: users, nodes,
locations, servers, backups and nests/eggs panel-wide. This is checked on
every request, the same as it is for the REST Application API itself, so
demoting an administrator immediately removes their access to these tools.
The admin backup and orphaned-backup tools are authorized under the servers
ACL resource rather than a backups-specific one, since a backup is server
data and the Application API has never split the two.

An OAuth access token narrows this further by its granted scopes: `client:read`
and `client:write` gate the client tools by whether they read or write,
`admin:read` and `admin:write` do the same for the admin tools, and the two
admin scopes are unavailable to a token issued to a non-administrator to begin
with. See the scopes table in [`OAUTH.md`](OAUTH.md).

Regardless of scope, an OAuth token can never reach the tools backed by
account-credential routes - creating or revoking API keys, adding or removing
SSH keys, two-factor setup, or changing an email address or password. A
limited, expiring token must not be usable to mint a permanent, unlimited one
underneath it. Those tools remain available to API keys.

## Security notes

* Every tool call runs through the exact same authorization the REST API
  applies to the request it is built from - the same guards, the same
  permission checks, the same validation. There is no path through MCP that
  bypasses what a direct API call would be refused for.
* Tool annotations mark every tool backed by a non-`GET` request as
  destructive, so a client that respects those hints asks for confirmation
  before calling them.
* The endpoint has its own rate limit bucket, keyed to the calling account so
  that switching IP address does not get around it, separate from the bucket
  the REST API itself draws from for the same account.

## Discovery

An unauthenticated or expired request gets back a 401 carrying a
`WWW-Authenticate` header that points at the resource metadata document:

```
GET /.well-known/oauth-protected-resource
GET /.well-known/oauth-protected-resource/mcp
```

This is the RFC 9728 protected-resource metadata for `/mcp`, served at both
the well-known path and, since `/mcp` is itself a path, the path-inserted form
some clients look for first. It names the Panel as the authorization server, so
a client that only holds this URL can still find its way to
`/.well-known/oauth-authorization-server` (RFC 8414, described in
[`OAUTH.md`](OAUTH.md)) without being told about it up front.

## Limitations

* No server-initiated notifications. This endpoint answers one request with
  one response and keeps no connection open, so `GET`/`DELETE /mcp` - the
  methods a Streamable HTTP client would use to open a stream or close a
  session - are refused with `405 Method Not Allowed`.
* No dynamic client registration for OAuth. See [`OAUTH.md`](OAUTH.md).
* No tools exist for anything the REST API itself does not expose. That
  includes panel-wide settings, database hosts and mounts, and creating or
  updating nests and eggs - the Application API only lists and reads those,
  so there is nothing for a tool to call.
