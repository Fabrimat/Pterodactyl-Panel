<?php

namespace Pterodactyl\Services\Mcp;

use Illuminate\Support\Str;
use Pterodactyl\Models\User;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Services\Acl\Api\OAuthScopeAcl;
use Pterodactyl\Http\Middleware\Api\Client\AuthenticateOAuthScopes;

class ToolCatalog
{
    public function __construct(private EndpointRegistry $registry)
    {
    }

    /**
     * The tool descriptors the given caller is allowed to see, in table order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function descriptors(User $user, mixed $token): array
    {
        $tools = [];
        foreach ($this->registry->all() as $row) {
            if ($this->allows($row, $user, $token)) {
                $tools[] = self::describe($row);
            }
        }

        return $tools;
    }

    /**
     * Resolves a tool name to the row backing it, or null when this caller may not use it.
     *
     * tools/call resolves through here rather than trusting that a client only ever calls
     * what tools/list handed it. The listing is a convenience; this is the gate.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $name, User $user, mixed $token): ?array
    {
        $row = $this->registry->get($name);

        return $row !== null && $this->allows($row, $user, $token) ? $row : null;
    }

    /**
     * Whether a tool of this name exists at all, regardless of who is asking. Only used to
     * tell a client that mistyped a name apart from one that asked for something it is not
     * allowed to have.
     */
    public function exists(string $name): bool
    {
        return $this->registry->get($name) !== null;
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function allows(array $row, User $user, mixed $token): bool
    {
        $application = ($row['api'] ?? null) === 'application';

        // The application API is administrators only, re-checked on every request by the
        // AuthenticateApplicationUser middleware. Advertising those tools to anybody else
        // would only produce a wall of 403s.
        if ($application && !$user->root_admin) {
            return false;
        }

        // Same reasoning in the other direction: the client API refuses an application API
        // key outright, so a caller holding one has no use for the client tools.
        if (!$application && $token instanceof ApiKey && $token->key_type === ApiKey::TYPE_APPLICATION) {
            return false;
        }

        // API key and session authenticated callers carry no scopes at all, exactly as the
        // client API itself treats them, so there is nothing further to check for them.
        if (!OAuthScopeAcl::isOAuthRequest()) {
            return true;
        }

        $read = strtoupper((string) ($row['method'] ?? 'GET')) === 'GET';
        $scope = match (true) {
            $application && $read => OAuthScopeAcl::ADMIN_READ,
            $application => OAuthScopeAcl::ADMIN_WRITE,
            $read => OAuthScopeAcl::CLIENT_READ,
            default => OAuthScopeAcl::CLIENT_WRITE,
        };

        if (!OAuthScopeAcl::tokenCan($token, $scope)) {
            return false;
        }

        // The routes that hand out or replace account credentials are refused for OAuth
        // tokens by the client API middleware. Deriving the list from that constant rather
        // than keeping a second copy is what stops the two from drifting apart and leaving
        // a tool advertised that the Panel would never actually run.
        return !Str::startsWith($this->uri($row), AuthenticateOAuthScopes::PROTECTED_ROUTES);
    }

    /**
     * The route URI a row resolves to, in the form the router stores it in.
     *
     * @param array<string, mixed> $row
     */
    protected function uri(array $row): string
    {
        return ltrim(EndpointRegistry::basePath($row), '/') . ($row['path'] ?? '');
    }

    /**
     * Builds the MCP tool descriptor for a row.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function describe(array $row): array
    {
        $properties = array_merge(
            $row['path_params'] ?? [],
            $row['query'] ?? [],
            ($row['body_type'] ?? null) === 'text'
                ? [($row['text_field'] ?? 'content') => ['type' => 'string', 'description' => 'Raw contents to send as the request body.']]
                : ($row['body'] ?? [])
        );

        // Cast so that a tool taking no input still encodes "properties" as an object; an
        // empty PHP array would come out as [] and clients reject that as a schema.
        $schema = ['type' => 'object', 'properties' => (object) $properties];
        if (!empty($row['required'])) {
            $schema['required'] = array_values($row['required']);
        }

        return [
            'name' => $row['name'],
            'description' => $row['description'] ?? '',
            'inputSchema' => $schema,
            // The spec defaults destructiveHint to true when the key is absent, so emitting
            // destructiveHint: false actively tells a client that no confirmation is needed.
            // Every non-GET row therefore carries true: the "destructive" flag in the table
            // documents why a particular row is dangerous, it does not decide this.
            'annotations' => strtoupper((string) ($row['method'] ?? 'GET')) === 'GET'
                ? ['readOnlyHint' => true]
                : ['readOnlyHint' => false, 'destructiveHint' => true],
        ];
    }
}
