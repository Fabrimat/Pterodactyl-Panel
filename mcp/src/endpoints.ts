import { z } from 'zod';

export type ApiName = 'application' | 'client';
export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export interface EndpointRow {
    /** Tool name exposed over MCP. panel_admin_* for the Application API, panel_client_* for the Client API. */
    name: string;
    description: string;
    api: ApiName;
    method: HttpMethod;
    /** Path relative to /api/application or /api/client, with {name} placeholders matching pathParams keys. */
    path: string;
    pathParams?: z.ZodRawShape;
    query?: z.ZodRawShape;
    /** JSON body fields. Ignored when bodyType is 'text'. */
    body?: z.ZodRawShape;
    /** Presence means the request body is a raw string, sent as text/plain, not JSON. */
    bodyType?: 'text';
    /** Name of the input field carrying the raw text body. Defaults to 'content'. */
    textField?: string;
    /** Presence means the response body should be returned as raw text instead of parsed JSON. */
    responseType?: 'text';
    /**
     * Explicit opt-in for non-GET, non-DELETE rows that are destructive (overwrite or disable
     * something). Every DELETE row is treated as destructive automatically - see index.ts.
     */
    destructive?: boolean;
}

function pagination(): z.ZodRawShape {
    return {
        page: z.number().int().min(1).optional().describe('Page number, starting at 1.'),
        per_page: z.number().int().min(1).max(100).optional().describe('Results per page (panel default is 50).'),
    };
}

function filterQuery(fields: string): z.ZodRawShape {
    return {
        filter: z
            .record(z.string(), z.string())
            .optional()
            .describe(`Exact or partial match filters, sent as filter[key]=value. Allowed keys: ${fields}.`),
    };
}

function sortQuery(fields: string): z.ZodRawShape {
    return {
        sort: z
            .string()
            .optional()
            .describe(`Field to sort results by, prefix with "-" for descending. Allowed: ${fields}.`),
    };
}

function includeQuery(relations: string): z.ZodRawShape {
    return {
        include: z
            .string()
            .optional()
            .describe(`Comma-separated related resources to embed in the response. Available: ${relations}.`),
    };
}

function listQuery(opts: { filter?: string; sort?: string; include?: string } = {}): z.ZodRawShape {
    return {
        ...pagination(),
        ...(opts.filter ? filterQuery(opts.filter) : {}),
        ...(opts.sort ? sortQuery(opts.sort) : {}),
        ...(opts.include ? includeQuery(opts.include) : {}),
    };
}

// Application API path parameters are the numeric database id unless noted otherwise.
const userIdParam = { userId: z.number().int().positive().describe('Numeric user id.') };
const nodeIdParam = { nodeId: z.number().int().positive().describe('Numeric node id.') };
const allocationIdParam = { allocationId: z.number().int().positive().describe('Numeric allocation id.') };
const locationIdParam = { locationId: z.number().int().positive().describe('Numeric location id.') };
const appServerIdParam = { serverId: z.number().int().positive().describe('Numeric server id.') };
const appDatabaseIdParam = { databaseId: z.number().int().positive().describe('Numeric server database id.') };
const nestIdParam = { nestId: z.number().int().positive().describe('Numeric nest id.') };
const eggIdParam = { eggId: z.number().int().positive().describe('Numeric egg id.') };

// Client API servers are addressed by identifier, not the numeric id.
const serverUuidParam = {
    serverId: z
        .string()
        .describe('Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.'),
};

const userStoreFields = {
    external_id: z.string().nullable().optional().describe('Arbitrary id linking this user to an external system.'),
    email: z.string().email(),
    username: z.string(),
    first_name: z.string(),
    last_name: z.string(),
    password: z.string().nullable().optional().describe('Plaintext password; omit to leave the user unable to log in with a password.'),
    language: z.string().optional().describe('Locale code, e.g. "en".'),
    root_admin: z.boolean().optional(),
    require_2fa: z
        .boolean()
        .nullable()
        .optional()
        .describe('Per-user two-factor override: null inherits the panel default, true forces 2FA, false exempts this user.'),
};

const nodeStoreFields = {
    public: z.boolean().optional().describe('Whether this node is visible for automatic deployment.'),
    name: z.string(),
    description: z.string().nullable().optional(),
    location_id: z.number().int().positive(),
    fqdn: z.string().describe('Hostname or IP address wings listens on.'),
    scheme: z.enum(['http', 'https']),
    behind_proxy: z.boolean().optional(),
    maintenance_mode: z.boolean().optional(),
    memory: z.number().int().describe('Total memory in MB.'),
    memory_overallocate: z.number().int(),
    disk: z.number().int().describe('Total disk in MB.'),
    disk_overallocate: z.number().int(),
    upload_size: z.number().int().describe('Max file upload size in MB.'),
    daemon_listen: z.number().int().describe('Port wings listens on.'),
    daemon_sftp: z.number().int().describe('Port the wings SFTP server listens on.'),
    daemon_base: z.string().describe('Base directory wings stores server data in.'),
};

export const endpoints: EndpointRow[] = [
    // -------------------------------------------------------------------
    // Application API - Users
    // -------------------------------------------------------------------
    {
        name: 'panel_admin_users_list',
        description: 'List panel users. Supports pagination and filtering by email, uuid, username or external_id.',
        api: 'application',
        method: 'GET',
        path: '/users',
        query: listQuery({ filter: 'email, uuid, username, external_id', sort: 'id, uuid', include: 'servers' }),
    },
    {
        name: 'panel_admin_users_view',
        description: 'View a single panel user by numeric id.',
        api: 'application',
        method: 'GET',
        path: '/users/{userId}',
        pathParams: userIdParam,
        query: includeQuery('servers'),
    },
    {
        name: 'panel_admin_users_view_by_external_id',
        description: 'View a single panel user by the external_id assigned to them.',
        api: 'application',
        method: 'GET',
        path: '/users/external/{externalId}',
        pathParams: { externalId: z.string() },
    },
    {
        name: 'panel_admin_users_create',
        description: 'Create a new panel user.',
        api: 'application',
        method: 'POST',
        path: '/users',
        body: userStoreFields,
    },
    {
        name: 'panel_admin_users_update',
        description:
            'Update an existing panel user. This is not a partial update: email, username, first_name and last_name are required on every call, not just the fields being changed - re-send the current values (fetch them first with panel_admin_users_view if unknown) alongside whatever you are changing, or the panel rejects the request. Can change root_admin and require_2fa; destructive in that it can escalate/de-escalate a user or remove their two-factor requirement.',
        api: 'application',
        method: 'PATCH',
        path: '/users/{userId}',
        pathParams: userIdParam,
        body: Object.fromEntries(Object.entries(userStoreFields).map(([key, schema]) => [key, schema.optional()])),
        destructive: true,
    },
    {
        name: 'panel_admin_users_delete',
        description: 'Permanently delete a panel user. Fails if the user still owns servers. Destructive, cannot be undone.',
        api: 'application',
        method: 'DELETE',
        path: '/users/{userId}',
        pathParams: userIdParam,
    },

    // -------------------------------------------------------------------
    // Application API - Nodes
    // -------------------------------------------------------------------
    {
        name: 'panel_admin_nodes_list',
        description: 'List wings nodes. Supports pagination and filtering.',
        api: 'application',
        method: 'GET',
        path: '/nodes',
        query: listQuery({
            filter: 'uuid, name, fqdn, daemon_token_id',
            sort: 'id, uuid, memory, disk',
            include: 'allocations, location, servers',
        }),
    },
    {
        name: 'panel_admin_nodes_deployable',
        description: 'Find nodes that have enough free memory and disk to satisfy the given requirements, for automatic deployment.',
        api: 'application',
        method: 'GET',
        path: '/nodes/deployable',
        query: {
            memory: z.number().int().min(0).describe('Required memory in MB.'),
            disk: z.number().int().min(0).describe('Required disk in MB.'),
            location_ids: z.array(z.number().int()).optional().describe('Restrict the search to these location ids.'),
            page: z.number().int().min(1).optional(),
        },
    },
    {
        name: 'panel_admin_nodes_view',
        description: 'View a single node by numeric id.',
        api: 'application',
        method: 'GET',
        path: '/nodes/{nodeId}',
        pathParams: nodeIdParam,
        query: includeQuery('allocations, location, servers'),
    },
    {
        name: 'panel_admin_nodes_configuration',
        description: "Fetch the wings configuration (config.yml contents) generated for this node.",
        api: 'application',
        method: 'GET',
        path: '/nodes/{nodeId}/configuration',
        pathParams: nodeIdParam,
    },
    {
        name: 'panel_admin_nodes_create',
        description: 'Register a new wings node.',
        api: 'application',
        method: 'POST',
        path: '/nodes',
        body: nodeStoreFields,
    },
    {
        name: 'panel_admin_nodes_update',
        description: 'Update an existing node. Only the fields provided are changed.',
        api: 'application',
        method: 'PATCH',
        path: '/nodes/{nodeId}',
        pathParams: nodeIdParam,
        body: Object.fromEntries(Object.entries(nodeStoreFields).map(([key, schema]) => [key, schema.optional()])),
    },
    {
        name: 'panel_admin_nodes_delete',
        description: 'Delete a node. Fails if it still has servers assigned to it. Destructive, cannot be undone.',
        api: 'application',
        method: 'DELETE',
        path: '/nodes/{nodeId}',
        pathParams: nodeIdParam,
    },

    // -------------------------------------------------------------------
    // Application API - Node allocations
    // -------------------------------------------------------------------
    {
        name: 'panel_admin_nodes_allocations_list',
        description: 'List the IP/port allocations defined on a node.',
        api: 'application',
        method: 'GET',
        path: '/nodes/{nodeId}/allocations',
        pathParams: nodeIdParam,
        query: listQuery({ filter: 'ip, port, ip_alias, server_id' }),
    },
    {
        name: 'panel_admin_nodes_allocations_create',
        description: 'Add one or more port allocations to a node for a given IP.',
        api: 'application',
        method: 'POST',
        path: '/nodes/{nodeId}/allocations',
        pathParams: nodeIdParam,
        body: {
            ip: z.string().describe('IP address to allocate ports on.'),
            alias: z.string().nullable().optional().describe('Friendly alias shown instead of the raw IP.'),
            ports: z.array(z.string()).describe('Ports or ranges to allocate, e.g. ["25565", "25570-25580"].'),
        },
    },
    {
        name: 'panel_admin_nodes_allocations_delete',
        description: 'Remove an allocation from a node. Fails if it is currently assigned to a server. Destructive, cannot be undone.',
        api: 'application',
        method: 'DELETE',
        path: '/nodes/{nodeId}/allocations/{allocationId}',
        pathParams: { ...nodeIdParam, ...allocationIdParam },
    },

    // -------------------------------------------------------------------
    // Application API - Locations
    // -------------------------------------------------------------------
    {
        name: 'panel_admin_locations_list',
        description: 'List deployment locations.',
        api: 'application',
        method: 'GET',
        path: '/locations',
        query: listQuery({ filter: 'short, long', sort: 'id', include: 'nodes, servers' }),
    },
    {
        name: 'panel_admin_locations_view',
        description: 'View a single location by numeric id.',
        api: 'application',
        method: 'GET',
        path: '/locations/{locationId}',
        pathParams: locationIdParam,
        query: includeQuery('nodes, servers'),
    },
    {
        name: 'panel_admin_locations_create',
        description: 'Create a new location.',
        api: 'application',
        method: 'POST',
        path: '/locations',
        body: {
            short: z.string().describe('Short identifier shown in listings, e.g. "us-east".'),
            long: z.string().nullable().optional().describe('Longer human-readable description.'),
        },
    },
    {
        name: 'panel_admin_locations_update',
        description: 'Update a location. Only the fields provided are changed.',
        api: 'application',
        method: 'PATCH',
        path: '/locations/{locationId}',
        pathParams: locationIdParam,
        body: {
            short: z.string().optional(),
            long: z.string().nullable().optional(),
        },
    },
    {
        name: 'panel_admin_locations_delete',
        description: 'Delete a location. Fails if nodes still reference it. Destructive, cannot be undone.',
        api: 'application',
        method: 'DELETE',
        path: '/locations/{locationId}',
        pathParams: locationIdParam,
    },

    // -------------------------------------------------------------------
    // Application API - Servers
    // -------------------------------------------------------------------
    {
        name: 'panel_admin_servers_list',
        description: 'List all servers on the panel.',
        api: 'application',
        method: 'GET',
        path: '/servers',
        query: listQuery({
            filter: 'uuid, uuidShort, name, description, image, external_id',
            sort: 'id, uuid',
            include: 'allocations, user, subusers, nest, egg, variables, location, node',
        }),
    },
    {
        name: 'panel_admin_servers_view',
        description: 'View a single server by numeric id.',
        api: 'application',
        method: 'GET',
        path: '/servers/{serverId}',
        pathParams: appServerIdParam,
        query: includeQuery('allocations, user, subusers, nest, egg, variables, location, node'),
    },
    {
        name: 'panel_admin_servers_view_by_external_id',
        description: 'View a single server by its external_id.',
        api: 'application',
        method: 'GET',
        path: '/servers/external/{externalId}',
        pathParams: { externalId: z.string() },
    },
    {
        name: 'panel_admin_servers_create',
        description: 'Create a new server. Either specify a node/allocation directly, or a deploy block to have the panel pick a viable node.',
        api: 'application',
        method: 'POST',
        path: '/servers',
        body: {
            external_id: z.string().nullable().optional(),
            name: z.string(),
            description: z.string().nullable().optional(),
            user: z.number().int().positive().describe('Owner user id.'),
            egg: z.number().int().positive().describe('Egg id to install.'),
            docker_image: z.string(),
            startup: z.string().describe('Startup command template.'),
            environment: z.record(z.string(), z.unknown()).describe('Egg variable values, keyed by variable name.'),
            skip_scripts: z.boolean().optional().describe('Skip running the egg install script.'),
            oom_disabled: z.boolean().optional(),
            limits: z.object({
                memory: z.number().int(),
                swap: z.number().int(),
                disk: z.number().int(),
                io: z.number().int(),
                threads: z.string().nullable().optional(),
                cpu: z.number().int(),
            }),
            feature_limits: z.object({
                databases: z.number().int().nullable().optional(),
                allocations: z.number().int().nullable().optional(),
                backups: z.number().int().nullable().optional(),
            }),
            allocation: z
                .object({
                    default: z.number().int().optional().describe('Allocation id to use as the primary allocation.'),
                    additional: z.array(z.number().int()).optional(),
                })
                .optional()
                .describe('Required unless deploy is used.'),
            deploy: z
                .object({
                    locations: z.array(z.number().int()).optional(),
                    dedicated_ip: z.boolean().optional(),
                    port_range: z.array(z.string()).optional(),
                })
                .optional()
                .describe('If set, the panel picks a viable node/allocation instead of using the allocation block.'),
            start_on_completion: z.boolean().optional(),
        },
    },
    {
        name: 'panel_admin_servers_update_details',
        description: "Update a server's name, description, owner or external_id.",
        api: 'application',
        method: 'PATCH',
        path: '/servers/{serverId}/details',
        pathParams: appServerIdParam,
        body: {
            external_id: z.string().nullable().optional(),
            name: z.string().optional(),
            user: z.number().int().positive().optional().describe('New owner user id.'),
            description: z.string().nullable().optional(),
        },
    },
    {
        name: 'panel_admin_servers_update_build',
        description: "Update a server's resource limits, feature limits and allocations.",
        api: 'application',
        method: 'PATCH',
        path: '/servers/{serverId}/build',
        pathParams: appServerIdParam,
        body: {
            allocation: z.number().int().positive().optional().describe('Primary allocation id.'),
            oom_disabled: z.boolean().optional(),
            limits: z
                .object({
                    memory: z.number().int().optional(),
                    swap: z.number().int().optional(),
                    io: z.number().int().optional(),
                    cpu: z.number().int().optional(),
                    threads: z.string().nullable().optional(),
                    disk: z.number().int().optional(),
                })
                .optional(),
            add_allocations: z.array(z.number().int()).optional(),
            remove_allocations: z.array(z.number().int()).optional(),
            feature_limits: z.object({
                databases: z.number().int().nullable().optional(),
                allocations: z.number().int().nullable().optional(),
                backups: z.number().int().nullable().optional(),
            }),
        },
    },
    {
        name: 'panel_admin_servers_update_startup',
        description: "Update a server's startup command, egg, docker image and environment variables.",
        api: 'application',
        method: 'PATCH',
        path: '/servers/{serverId}/startup',
        pathParams: appServerIdParam,
        body: {
            startup: z.string(),
            environment: z.record(z.string(), z.unknown()),
            egg: z.number().int().positive(),
            image: z.string(),
            skip_scripts: z.boolean().optional(),
        },
    },
    {
        name: 'panel_admin_servers_suspend',
        description: 'Suspend a server, blocking user access and stopping it on wings. Reversible with unsuspend.',
        api: 'application',
        method: 'POST',
        path: '/servers/{serverId}/suspend',
        pathParams: appServerIdParam,
    },
    {
        name: 'panel_admin_servers_unsuspend',
        description: 'Lift a previous suspension on a server.',
        api: 'application',
        method: 'POST',
        path: '/servers/{serverId}/unsuspend',
        pathParams: appServerIdParam,
    },
    {
        name: 'panel_admin_servers_reinstall',
        description: 'Reinstall a server by re-running its egg install script. Destructive: may remove or overwrite server files depending on the egg.',
        api: 'application',
        method: 'POST',
        path: '/servers/{serverId}/reinstall',
        pathParams: appServerIdParam,
        destructive: true,
    },
    {
        name: 'panel_admin_servers_delete',
        description: 'Delete a server and its data. Fails if wings cannot be reached to clean up files. Destructive, cannot be undone.',
        api: 'application',
        method: 'DELETE',
        path: '/servers/{serverId}',
        pathParams: appServerIdParam,
    },
    {
        name: 'panel_admin_servers_force_delete',
        description: 'Delete a server and its database records even if wings cannot be reached to clean up files. Destructive, cannot be undone.',
        api: 'application',
        method: 'DELETE',
        path: '/servers/{serverId}/force',
        pathParams: appServerIdParam,
    },

    // -------------------------------------------------------------------
    // Application API - Server databases
    // -------------------------------------------------------------------
    {
        name: 'panel_admin_servers_databases_list',
        description: "List a server's databases.",
        api: 'application',
        method: 'GET',
        path: '/servers/{serverId}/databases',
        pathParams: appServerIdParam,
        query: includeQuery('password, host'),
    },
    {
        name: 'panel_admin_servers_databases_view',
        description: 'View a single server database.',
        api: 'application',
        method: 'GET',
        path: '/servers/{serverId}/databases/{databaseId}',
        pathParams: { ...appServerIdParam, ...appDatabaseIdParam },
        query: includeQuery('password, host'),
    },
    {
        name: 'panel_admin_servers_databases_create',
        description: 'Create a new database for a server on a given database host.',
        api: 'application',
        method: 'POST',
        path: '/servers/{serverId}/databases',
        pathParams: appServerIdParam,
        body: {
            database: z.string().describe('Database name.'),
            remote: z.string().describe('CIDR or hostname pattern allowed to connect, e.g. "%".'),
            host: z.number().int().positive().describe('Numeric id of the database host to create it on.'),
        },
    },
    {
        name: 'panel_admin_servers_databases_reset_password',
        description: "Reset a server database's password. Destructive: the old password stops working immediately, breaking anything still using it.",
        api: 'application',
        method: 'POST',
        path: '/servers/{serverId}/databases/{databaseId}/reset-password',
        pathParams: { ...appServerIdParam, ...appDatabaseIdParam },
        destructive: true,
    },
    {
        name: 'panel_admin_servers_databases_delete',
        description: 'Delete a server database. Destructive, cannot be undone.',
        api: 'application',
        method: 'DELETE',
        path: '/servers/{serverId}/databases/{databaseId}',
        pathParams: { ...appServerIdParam, ...appDatabaseIdParam },
    },

    // -------------------------------------------------------------------
    // Application API - Nests and eggs (read-only)
    // -------------------------------------------------------------------
    {
        name: 'panel_admin_nests_list',
        description: 'List nests (egg categories). Read-only: the Application API has no endpoints to create, update or delete nests.',
        api: 'application',
        method: 'GET',
        path: '/nests',
        query: listQuery({ include: 'eggs, servers' }),
    },
    {
        name: 'panel_admin_nests_view',
        description: 'View a single nest by numeric id.',
        api: 'application',
        method: 'GET',
        path: '/nests/{nestId}',
        pathParams: nestIdParam,
        query: includeQuery('eggs, servers'),
    },
    {
        name: 'panel_admin_nests_eggs_list',
        description: 'List the eggs within a nest. Read-only: the Application API has no endpoints to create, update or import eggs.',
        api: 'application',
        method: 'GET',
        path: '/nests/{nestId}/eggs',
        pathParams: nestIdParam,
        query: listQuery({ include: 'nest, servers, config, script, variables' }),
    },
    {
        name: 'panel_admin_nests_eggs_view',
        description: 'View a single egg definition.',
        api: 'application',
        method: 'GET',
        path: '/nests/{nestId}/eggs/{eggId}',
        pathParams: { ...nestIdParam, ...eggIdParam },
        query: includeQuery('nest, servers, config, script, variables'),
    },

    // -------------------------------------------------------------------
    // Client API - root and account
    // -------------------------------------------------------------------
    {
        name: 'panel_client_servers_list',
        description: "List servers the API key's owner can access (owned or as a subuser).",
        api: 'client',
        method: 'GET',
        path: '/',
        query: {
            ...listQuery({ filter: 'uuid, name, description, external_id' }),
            type: z
                .enum(['owner', 'admin', 'admin-all'])
                .optional()
                .describe('"admin"/"admin-all" only work for root admins and widen the results beyond owned/subuser servers.'),
        },
    },
    {
        name: 'panel_client_permissions',
        description: 'List the permission strings that can be assigned to subusers.',
        api: 'client',
        method: 'GET',
        path: '/permissions',
    },
    {
        name: 'panel_client_account_view',
        description: "View the API key owner's account details.",
        api: 'client',
        method: 'GET',
        path: '/account',
    },
    {
        name: 'panel_client_account_two_factor_view',
        description: 'Fetch a new two-factor setup secret and QR code image for the account. Fails if 2FA is already enabled.',
        api: 'client',
        method: 'GET',
        path: '/account/two-factor',
    },
    {
        name: 'panel_client_account_two_factor_enable',
        description: 'Confirm two-factor setup with a TOTP code and enable it on the account. Returns one-time recovery tokens.',
        api: 'client',
        method: 'POST',
        path: '/account/two-factor',
        body: {
            code: z.string().length(6).describe('Current 6-digit TOTP code.'),
            password: z.string().describe("Account password, required to confirm the change."),
        },
    },
    {
        name: 'panel_client_account_two_factor_disable',
        description: 'Disable two-factor authentication on the account. Destructive: removes a security control from the account.',
        api: 'client',
        method: 'POST',
        path: '/account/two-factor/disable',
        body: { password: z.string() },
        destructive: true,
    },
    {
        name: 'panel_client_account_update_email',
        description: "Change the account's email address.",
        api: 'client',
        method: 'PUT',
        path: '/account/email',
        body: {
            email: z.string().email(),
            password: z.string().describe('Current password, required to confirm the change.'),
        },
    },
    {
        name: 'panel_client_account_update_password',
        description: "Change the account's password. Invalidates existing sessions.",
        api: 'client',
        method: 'PUT',
        path: '/account/password',
        body: {
            current_password: z.string(),
            password: z.string().min(8),
            password_confirmation: z.string(),
        },
    },
    {
        name: 'panel_client_account_activity',
        description: "List the account's activity log entries.",
        api: 'client',
        method: 'GET',
        path: '/account/activity',
        query: listQuery({ filter: 'event (partial match)', sort: 'timestamp' }),
    },
    {
        name: 'panel_client_account_api_keys_list',
        description: "List the account's API keys (metadata only, not the secret values).",
        api: 'client',
        method: 'GET',
        path: '/account/api-keys',
    },
    {
        name: 'panel_client_account_api_keys_create',
        description: 'Create a new API key for the account. The secret value is only returned once, in the response.',
        api: 'client',
        method: 'POST',
        path: '/account/api-keys',
        body: {
            description: z.string().describe('Label to identify this key.'),
            allowed_ips: z.array(z.string()).optional().describe('IPs or CIDR ranges allowed to use this key; empty allows any.'),
        },
    },
    {
        name: 'panel_client_account_api_keys_delete',
        description: 'Revoke an API key by its identifier. Destructive, cannot be undone; any integration using it stops working.',
        api: 'client',
        method: 'DELETE',
        path: '/account/api-keys/{identifier}',
        pathParams: { identifier: z.string() },
    },
    {
        name: 'panel_client_account_ssh_keys_list',
        description: "List the account's registered SSH public keys.",
        api: 'client',
        method: 'GET',
        path: '/account/ssh-keys',
    },
    {
        name: 'panel_client_account_ssh_keys_create',
        description: 'Add an SSH public key to the account.',
        api: 'client',
        method: 'POST',
        path: '/account/ssh-keys',
        body: {
            name: z.string().describe('Label to identify this key.'),
            public_key: z.string(),
        },
    },
    {
        name: 'panel_client_account_ssh_keys_delete',
        description: 'Remove an SSH public key from the account by its fingerprint. Destructive, cannot be undone.',
        api: 'client',
        method: 'POST',
        path: '/account/ssh-keys/remove',
        body: { fingerprint: z.string() },
        destructive: true,
    },

    // -------------------------------------------------------------------
    // Client API - per-server core
    // -------------------------------------------------------------------
    {
        name: 'panel_client_servers_view',
        description: 'View a single server, including its current install/suspension state.',
        api: 'client',
        method: 'GET',
        path: '/servers/{serverId}',
        pathParams: serverUuidParam,
    },
    {
        name: 'panel_client_servers_resources',
        description: "Fetch a server's live resource usage (CPU, memory, disk, network) and power state.",
        api: 'client',
        method: 'GET',
        path: '/servers/{serverId}/resources',
        pathParams: serverUuidParam,
    },
    {
        name: 'panel_client_servers_activity',
        description: "List a server's activity log entries.",
        api: 'client',
        method: 'GET',
        path: '/servers/{serverId}/activity',
        pathParams: serverUuidParam,
        query: listQuery({ filter: 'event (partial match)', sort: 'timestamp' }),
    },
    {
        name: 'panel_client_servers_command',
        description: "Send a console command to a server's running process. Requires the server to be online.",
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/command',
        pathParams: serverUuidParam,
        body: { command: z.string().min(1) },
    },
    {
        name: 'panel_client_servers_power',
        description: 'Send a power action to a server.',
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/power',
        pathParams: serverUuidParam,
        body: { signal: z.enum(['start', 'stop', 'restart', 'kill']) },
    },

    // -------------------------------------------------------------------
    // Client API - per-server databases
    // -------------------------------------------------------------------
    {
        name: 'panel_client_servers_databases_list',
        description: "List a server's databases.",
        api: 'client',
        method: 'GET',
        path: '/servers/{serverId}/databases',
        pathParams: serverUuidParam,
        query: includeQuery('password'),
    },
    {
        name: 'panel_client_servers_databases_create',
        description: 'Create a new database for a server, subject to the database limit set by an admin.',
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/databases',
        pathParams: serverUuidParam,
        body: {
            database: z.string(),
            remote: z.string().describe('CIDR or hostname pattern allowed to connect, e.g. "%".'),
        },
    },
    {
        name: 'panel_client_servers_databases_rotate_password',
        description: "Rotate a server database's password. Destructive: the old password stops working immediately, breaking anything still using it.",
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/databases/{databaseId}/rotate-password',
        pathParams: { ...serverUuidParam, databaseId: z.string().describe('Database identifier as returned by the API (not the numeric id).') },
        destructive: true,
    },
    {
        name: 'panel_client_servers_databases_delete',
        description: 'Delete a server database. Destructive, cannot be undone.',
        api: 'client',
        method: 'DELETE',
        path: '/servers/{serverId}/databases/{databaseId}',
        pathParams: { ...serverUuidParam, databaseId: z.string().describe('Database identifier as returned by the API (not the numeric id).') },
    },

    // -------------------------------------------------------------------
    // Client API - per-server files
    // -------------------------------------------------------------------
    {
        name: 'panel_client_servers_files_list',
        description: 'List files and folders in a directory on the server.',
        api: 'client',
        method: 'GET',
        path: '/servers/{serverId}/files/list',
        pathParams: serverUuidParam,
        query: { directory: z.string().optional().describe('Directory to list, defaults to "/".') },
    },
    {
        name: 'panel_client_servers_files_contents',
        description: 'Read the raw text contents of a file. Returns plain text, not JSON. Fails for files above the configured edit size limit.',
        api: 'client',
        method: 'GET',
        path: '/servers/{serverId}/files/contents',
        pathParams: serverUuidParam,
        query: { file: z.string().describe('Absolute path of the file to read.') },
        responseType: 'text',
    },
    {
        name: 'panel_client_servers_files_download',
        description: 'Request a one-time signed download URL for a file. The tool returns the signed Wings URL, not the file contents; fetch it separately and it expires after a short time.',
        api: 'client',
        method: 'GET',
        path: '/servers/{serverId}/files/download',
        pathParams: serverUuidParam,
        query: { file: z.string().describe('Absolute path of the file to download.') },
    },
    {
        name: 'panel_client_servers_files_rename',
        description: 'Rename or move one or more files within a directory.',
        api: 'client',
        method: 'PUT',
        path: '/servers/{serverId}/files/rename',
        pathParams: serverUuidParam,
        body: {
            root: z.string().describe('Directory the files currently live in.'),
            files: z.array(z.object({ from: z.string(), to: z.string() })),
        },
    },
    {
        name: 'panel_client_servers_files_copy',
        description: 'Copy a single file, creating a new file alongside it.',
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/files/copy',
        pathParams: serverUuidParam,
        body: { location: z.string().describe('Absolute path of the file to copy.') },
    },
    {
        name: 'panel_client_servers_files_write',
        description: 'Write raw text to a file, creating it if needed. Overwrites the existing file contents. Body is sent as raw text, not JSON. Destructive: replaces the current file content.',
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/files/write',
        pathParams: serverUuidParam,
        query: { file: z.string().describe('Absolute path of the file to write.') },
        bodyType: 'text',
        textField: 'content',
        destructive: true,
    },
    {
        name: 'panel_client_servers_files_compress',
        description: 'Compress one or more files/folders into a new archive in the given directory.',
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/files/compress',
        pathParams: serverUuidParam,
        body: {
            root: z.string().optional().describe('Directory the files live in, defaults to "/".'),
            files: z.array(z.string()),
        },
    },
    {
        name: 'panel_client_servers_files_decompress',
        description: 'Extract an archive in place. Destructive: may overwrite existing files with the same names.',
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/files/decompress',
        pathParams: serverUuidParam,
        body: {
            root: z.string().optional().describe('Directory the archive lives in, defaults to "/".'),
            file: z.string().describe('Archive file name to extract.'),
        },
        destructive: true,
    },
    {
        name: 'panel_client_servers_files_delete',
        description: 'Delete one or more files or folders. Destructive, cannot be undone.',
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/files/delete',
        pathParams: serverUuidParam,
        body: {
            root: z.string().describe('Directory the files live in.'),
            files: z.array(z.string()),
        },
        destructive: true,
    },
    {
        name: 'panel_client_servers_files_create_folder',
        description: 'Create a new empty folder.',
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/files/create-folder',
        pathParams: serverUuidParam,
        body: {
            root: z.string().optional().describe('Parent directory, defaults to "/".'),
            name: z.string(),
        },
    },
    {
        name: 'panel_client_servers_files_chmod',
        description: 'Change unix file permissions for one or more files.',
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/files/chmod',
        pathParams: serverUuidParam,
        body: {
            root: z.string().describe('Directory the files live in.'),
            files: z.array(z.object({ file: z.string(), mode: z.string().describe('Octal mode, e.g. "0755".') })),
        },
    },
    {
        name: 'panel_client_servers_files_pull',
        description: 'Have wings download a file from a remote URL directly into the server directory.',
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/files/pull',
        pathParams: serverUuidParam,
        body: {
            url: z.string().url(),
            directory: z.string().nullable().optional().describe('Destination directory, defaults to "/".'),
            filename: z.string().nullable().optional(),
            use_header: z.boolean().optional().describe('Use the filename from the Content-Disposition header if present.'),
            foreground: z.boolean().optional().describe('Wait for the download to finish before responding.'),
        },
    },
    {
        name: 'panel_client_servers_files_upload',
        description: 'Request a one-time signed upload URL for the server. The tool returns the signed Wings URL, not a place to send file bytes through this call; POST the file to that URL separately and it expires after a short time.',
        api: 'client',
        method: 'GET',
        path: '/servers/{serverId}/files/upload',
        pathParams: serverUuidParam,
    },

    // -------------------------------------------------------------------
    // Client API - per-server schedules
    // -------------------------------------------------------------------
    {
        name: 'panel_client_servers_schedules_list',
        description: "List a server's schedules and their tasks.",
        api: 'client',
        method: 'GET',
        path: '/servers/{serverId}/schedules',
        pathParams: serverUuidParam,
    },
    {
        name: 'panel_client_servers_schedules_create',
        description: 'Create a new cron-style schedule for a server.',
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/schedules',
        pathParams: serverUuidParam,
        body: {
            name: z.string(),
            is_active: z.boolean().optional(),
            minute: z.string().describe('Cron minute expression, e.g. "*/5".'),
            hour: z.string(),
            day_of_month: z.string(),
            day_of_week: z.string(),
        },
    },
    {
        name: 'panel_client_servers_schedules_view',
        description: 'View a single schedule and its tasks.',
        api: 'client',
        method: 'GET',
        path: '/servers/{serverId}/schedules/{scheduleId}',
        pathParams: { ...serverUuidParam, scheduleId: z.number().int().positive() },
    },
    {
        name: 'panel_client_servers_schedules_update',
        description: "Update a schedule's cron expression and active state.",
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/schedules/{scheduleId}',
        pathParams: { ...serverUuidParam, scheduleId: z.number().int().positive() },
        body: {
            name: z.string(),
            is_active: z.boolean().optional(),
            minute: z.string(),
            hour: z.string(),
            day_of_month: z.string(),
            day_of_week: z.string(),
        },
    },
    {
        name: 'panel_client_servers_schedules_execute',
        description: 'Run a schedule immediately, outside of its normal cron trigger.',
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/schedules/{scheduleId}/execute',
        pathParams: { ...serverUuidParam, scheduleId: z.number().int().positive() },
    },
    {
        name: 'panel_client_servers_schedules_delete',
        description: 'Delete a schedule and all its tasks. Destructive, cannot be undone.',
        api: 'client',
        method: 'DELETE',
        path: '/servers/{serverId}/schedules/{scheduleId}',
        pathParams: { ...serverUuidParam, scheduleId: z.number().int().positive() },
    },
    {
        name: 'panel_client_servers_schedules_tasks_create',
        description: 'Add a task (command, power action or backup) to a schedule.',
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/schedules/{scheduleId}/tasks',
        pathParams: { ...serverUuidParam, scheduleId: z.number().int().positive() },
        body: {
            action: z.enum(['command', 'power', 'backup']),
            payload: z.string().nullable().optional().describe('Command text or power signal; not used for the backup action.'),
            time_offset: z.number().int().min(0).max(900).describe('Seconds to wait after the previous task before running this one.'),
            sequence_id: z.number().int().min(1).optional().describe('Position in the task list; appended to the end if omitted.'),
            continue_on_failure: z.boolean().optional(),
        },
    },
    {
        name: 'panel_client_servers_schedules_tasks_update',
        description: 'Update an existing task on a schedule.',
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/schedules/{scheduleId}/tasks/{taskId}',
        pathParams: { ...serverUuidParam, scheduleId: z.number().int().positive(), taskId: z.number().int().positive() },
        body: {
            action: z.enum(['command', 'power', 'backup']),
            payload: z.string().nullable().optional(),
            time_offset: z.number().int().min(0).max(900),
            sequence_id: z.number().int().min(1).optional(),
            continue_on_failure: z.boolean().optional(),
        },
    },
    {
        name: 'panel_client_servers_schedules_tasks_delete',
        description: 'Remove a task from a schedule. Destructive, cannot be undone.',
        api: 'client',
        method: 'DELETE',
        path: '/servers/{serverId}/schedules/{scheduleId}/tasks/{taskId}',
        pathParams: { ...serverUuidParam, scheduleId: z.number().int().positive(), taskId: z.number().int().positive() },
    },

    // -------------------------------------------------------------------
    // Client API - per-server network allocations
    // -------------------------------------------------------------------
    {
        name: 'panel_client_servers_network_allocations_list',
        description: "List a server's assigned network allocations.",
        api: 'client',
        method: 'GET',
        path: '/servers/{serverId}/network/allocations',
        pathParams: serverUuidParam,
    },
    {
        name: 'panel_client_servers_network_allocations_create',
        description: 'Assign a new free allocation to the server, subject to the allocation limit set by an admin.',
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/network/allocations',
        pathParams: serverUuidParam,
    },
    {
        name: 'panel_client_servers_network_allocations_update',
        description: "Update an allocation's notes.",
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/network/allocations/{allocationId}',
        pathParams: { ...serverUuidParam, allocationId: z.number().int().positive() },
        body: { notes: z.string().nullable() },
    },
    {
        name: 'panel_client_servers_network_allocations_set_primary',
        description: "Mark an allocation as the server's primary allocation.",
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/network/allocations/{allocationId}/primary',
        pathParams: { ...serverUuidParam, allocationId: z.number().int().positive() },
    },
    {
        name: 'panel_client_servers_network_allocations_delete',
        description: 'Unassign an allocation from the server. Destructive, cannot be undone.',
        api: 'client',
        method: 'DELETE',
        path: '/servers/{serverId}/network/allocations/{allocationId}',
        pathParams: { ...serverUuidParam, allocationId: z.number().int().positive() },
    },

    // -------------------------------------------------------------------
    // Client API - per-server subusers
    // -------------------------------------------------------------------
    {
        name: 'panel_client_servers_subusers_list',
        description: "List a server's subusers and their permissions.",
        api: 'client',
        method: 'GET',
        path: '/servers/{serverId}/users',
        pathParams: serverUuidParam,
    },
    {
        name: 'panel_client_servers_subusers_create',
        description: 'Invite a user (by email) as a subuser of the server with the given permissions.',
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/users',
        pathParams: serverUuidParam,
        body: {
            email: z.string().email(),
            permissions: z.array(z.string()).describe('Permission strings, see panel_client_permissions for the full list.'),
        },
    },
    {
        name: 'panel_client_servers_subusers_view',
        description: 'View a single subuser and their permissions.',
        api: 'client',
        method: 'GET',
        path: '/servers/{serverId}/users/{userId}',
        pathParams: { ...serverUuidParam, userId: z.string().describe('Subuser UUID, as returned by the subuser list.') },
    },
    {
        name: 'panel_client_servers_subusers_update',
        description: "Replace a subuser's permission set.",
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/users/{userId}',
        pathParams: { ...serverUuidParam, userId: z.string().describe('Subuser UUID, as returned by the subuser list.') },
        body: { permissions: z.array(z.string()) },
    },
    {
        name: 'panel_client_servers_subusers_delete',
        description: 'Remove a subuser from the server. Destructive, cannot be undone.',
        api: 'client',
        method: 'DELETE',
        path: '/servers/{serverId}/users/{userId}',
        pathParams: { ...serverUuidParam, userId: z.string().describe('Subuser UUID, as returned by the subuser list.') },
    },

    // -------------------------------------------------------------------
    // Client API - per-server backups
    // -------------------------------------------------------------------
    {
        name: 'panel_client_servers_backups_list',
        description: "List a server's backups.",
        api: 'client',
        method: 'GET',
        path: '/servers/{serverId}/backups',
        pathParams: serverUuidParam,
        query: pagination(),
    },
    {
        name: 'panel_client_servers_backups_create',
        description: 'Start a new backup of the server, subject to the backup limit set by an admin.',
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/backups',
        pathParams: serverUuidParam,
        body: {
            name: z.string().nullable().optional(),
            is_locked: z.boolean().nullable().optional().describe('Locked backups cannot be deleted until unlocked.'),
            ignored: z.string().nullable().optional().describe('Newline-separated gitignore-style patterns to exclude.'),
        },
    },
    {
        name: 'panel_client_servers_backups_view',
        description: 'View a single backup and its status.',
        api: 'client',
        method: 'GET',
        path: '/servers/{serverId}/backups/{backupId}',
        pathParams: { ...serverUuidParam, backupId: z.string().describe('Backup UUID.') },
    },
    {
        name: 'panel_client_servers_backups_download',
        description: 'Request a one-time signed download URL for a backup. The tool returns the signed URL, not the archive bytes; fetch it separately and it expires after a short time.',
        api: 'client',
        method: 'GET',
        path: '/servers/{serverId}/backups/{backupId}/download',
        pathParams: { ...serverUuidParam, backupId: z.string().describe('Backup UUID.') },
    },
    {
        name: 'panel_client_servers_backups_toggle_lock',
        description: 'Toggle whether a backup is locked (locked backups cannot be deleted or pruned).',
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/backups/{backupId}/lock',
        pathParams: { ...serverUuidParam, backupId: z.string().describe('Backup UUID.') },
    },
    {
        name: 'panel_client_servers_backups_restore',
        description: 'Restore a backup onto the server. Destructive: overwrites current server files with the contents of the backup.',
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/backups/{backupId}/restore',
        pathParams: { ...serverUuidParam, backupId: z.string().describe('Backup UUID.') },
        body: { truncate: z.boolean().describe('If true, delete files not present in the backup before restoring.') },
        destructive: true,
    },
    {
        name: 'panel_client_servers_backups_delete',
        description: 'Delete a backup. Fails if it is locked. Destructive, cannot be undone.',
        api: 'client',
        method: 'DELETE',
        path: '/servers/{serverId}/backups/{backupId}',
        pathParams: { ...serverUuidParam, backupId: z.string().describe('Backup UUID.') },
    },

    // -------------------------------------------------------------------
    // Client API - per-server startup
    // -------------------------------------------------------------------
    {
        name: 'panel_client_servers_startup_view',
        description: "List a server's startup variables and their current values.",
        api: 'client',
        method: 'GET',
        path: '/servers/{serverId}/startup',
        pathParams: serverUuidParam,
    },
    {
        name: 'panel_client_servers_startup_update_variable',
        description: 'Update the value of a single startup (environment) variable, subject to its egg-defined validation rules.',
        api: 'client',
        method: 'PUT',
        path: '/servers/{serverId}/startup/variable',
        pathParams: serverUuidParam,
        body: {
            key: z.string().describe('Variable environment name, e.g. "SERVER_JARFILE".'),
            value: z.string(),
        },
    },

    // -------------------------------------------------------------------
    // Client API - per-server settings
    // -------------------------------------------------------------------
    {
        name: 'panel_client_servers_settings_rename',
        description: "Rename a server and optionally update its description.",
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/settings/rename',
        pathParams: serverUuidParam,
        body: {
            name: z.string(),
            description: z.string().nullable().optional(),
        },
    },
    {
        name: 'panel_client_servers_settings_reinstall',
        description: 'Reinstall the server by re-running its egg install script. Destructive: may remove or overwrite server files depending on the egg.',
        api: 'client',
        method: 'POST',
        path: '/servers/{serverId}/settings/reinstall',
        pathParams: serverUuidParam,
        destructive: true,
    },
    {
        name: 'panel_client_servers_settings_docker_image',
        description: "Switch the server's docker image to one of the images allowed by its egg.",
        api: 'client',
        method: 'PUT',
        path: '/servers/{serverId}/settings/docker-image',
        pathParams: serverUuidParam,
        body: { docker_image: z.string() },
    },
];
