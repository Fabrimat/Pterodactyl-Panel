<?php

// This table drives the MCP (Model Context Protocol) tool layer: each entry
// maps one panel REST endpoint (Application or Client API) to the tool name,
// HTTP method and parameter shape an MCP client sees. It is plain data, kept
// separate from the code that turns it into registered tools so the mapping
// can be reviewed and updated without touching that code.

return [
    [
        'name' => 'panel_admin_users_list',
        'description' => 'List panel users. Supports pagination and filtering by email, uuid, username or external_id.',
        'api' => 'application',
        'method' => 'GET',
        'path' => '/users',
        'query' => [
            'page' => [
                'type' => 'integer',
                'description' => 'Page number, starting at 1.',
                'minimum' => 1,
                'maximum' => 9007199254740991,
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Results per page (panel default is 50).',
                'minimum' => 1,
                'maximum' => 100,
            ],
            'filter' => [
                'type' => 'object',
                'description' => 'Exact or partial match filters, sent as filter[key]=value. Allowed keys: email, uuid, username, external_id.',
                'additionalProperties' => [
                    'type' => 'string',
                ],
            ],
            'sort' => [
                'type' => 'string',
                'description' => 'Field to sort results by, prefix with "-" for descending. Allowed: id, uuid.',
            ],
            'include' => [
                'type' => 'string',
                'description' => 'Comma-separated related resources to embed in the response. Available: servers.',
            ],
        ],
    ],
    [
        'name' => 'panel_admin_users_view',
        'description' => 'View a single panel user by numeric id.',
        'api' => 'application',
        'method' => 'GET',
        'path' => '/users/{userId}',
        'path_params' => [
            'userId' => [
                'type' => 'integer',
                'description' => 'Numeric user id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'query' => [
            'include' => [
                'type' => 'string',
                'description' => 'Comma-separated related resources to embed in the response. Available: servers.',
            ],
        ],
        'required' => [
            'userId',
        ],
    ],
    [
        'name' => 'panel_admin_users_view_by_external_id',
        'description' => 'View a single panel user by the external_id assigned to them.',
        'api' => 'application',
        'method' => 'GET',
        'path' => '/users/external/{externalId}',
        'path_params' => [
            'externalId' => [
                'type' => 'string',
            ],
        ],
        'required' => [
            'externalId',
        ],
    ],
    [
        'name' => 'panel_admin_users_create',
        'description' => 'Create a new panel user.',
        'api' => 'application',
        'method' => 'POST',
        'path' => '/users',
        'body' => [
            'external_id' => [
                'type' => [
                    'string',
                    'null',
                ],
                'description' => 'Arbitrary id linking this user to an external system.',
            ],
            'email' => [
                'type' => 'string',
                'format' => 'email',
            ],
            'username' => [
                'type' => 'string',
            ],
            'first_name' => [
                'type' => 'string',
            ],
            'last_name' => [
                'type' => 'string',
            ],
            'password' => [
                'type' => [
                    'string',
                    'null',
                ],
                'description' => 'Plaintext password; omit to leave the user unable to log in with a password.',
            ],
            'language' => [
                'type' => 'string',
                'description' => 'Locale code, e.g. "en".',
            ],
            'root_admin' => [
                'type' => 'boolean',
            ],
            'require_2fa' => [
                'type' => [
                    'boolean',
                    'null',
                ],
                'description' => 'Per-user two-factor override: null inherits the panel default, true forces 2FA, false exempts this user.',
            ],
        ],
        'required' => [
            'email',
            'username',
            'first_name',
            'last_name',
        ],
    ],
    [
        'name' => 'panel_admin_users_update',
        'description' => 'Update an existing panel user. This is not a partial update: email, username, first_name and last_name are required on every call, not just the fields being changed - re-send the current values (fetch them first with panel_admin_users_view if unknown) alongside whatever you are changing, or the panel rejects the request. Can change root_admin and require_2fa; destructive in that it can escalate/de-escalate a user or remove their two-factor requirement.',
        'api' => 'application',
        'method' => 'PATCH',
        'path' => '/users/{userId}',
        'path_params' => [
            'userId' => [
                'type' => 'integer',
                'description' => 'Numeric user id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'body' => [
            'external_id' => [
                'type' => [
                    'string',
                    'null',
                ],
                'description' => 'Arbitrary id linking this user to an external system.',
            ],
            'email' => [
                'type' => 'string',
                'format' => 'email',
            ],
            'username' => [
                'type' => 'string',
            ],
            'first_name' => [
                'type' => 'string',
            ],
            'last_name' => [
                'type' => 'string',
            ],
            'password' => [
                'type' => [
                    'string',
                    'null',
                ],
                'description' => 'Plaintext password; omit to leave the user unable to log in with a password.',
            ],
            'language' => [
                'type' => 'string',
                'description' => 'Locale code, e.g. "en".',
            ],
            'root_admin' => [
                'type' => 'boolean',
            ],
            'require_2fa' => [
                'type' => [
                    'boolean',
                    'null',
                ],
                'description' => 'Per-user two-factor override: null inherits the panel default, true forces 2FA, false exempts this user.',
            ],
        ],
        'required' => [
            'userId',
        ],
        'destructive' => true,
    ],
    [
        'name' => 'panel_admin_users_delete',
        'description' => 'Permanently delete a panel user. Fails if the user still owns servers. Destructive, cannot be undone.',
        'api' => 'application',
        'method' => 'DELETE',
        'path' => '/users/{userId}',
        'path_params' => [
            'userId' => [
                'type' => 'integer',
                'description' => 'Numeric user id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'userId',
        ],
    ],
    [
        'name' => 'panel_admin_nodes_list',
        'description' => 'List wings nodes. Supports pagination and filtering.',
        'api' => 'application',
        'method' => 'GET',
        'path' => '/nodes',
        'query' => [
            'page' => [
                'type' => 'integer',
                'description' => 'Page number, starting at 1.',
                'minimum' => 1,
                'maximum' => 9007199254740991,
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Results per page (panel default is 50).',
                'minimum' => 1,
                'maximum' => 100,
            ],
            'filter' => [
                'type' => 'object',
                'description' => 'Exact or partial match filters, sent as filter[key]=value. Allowed keys: uuid, name, fqdn, daemon_token_id.',
                'additionalProperties' => [
                    'type' => 'string',
                ],
            ],
            'sort' => [
                'type' => 'string',
                'description' => 'Field to sort results by, prefix with "-" for descending. Allowed: id, uuid, memory, disk.',
            ],
            'include' => [
                'type' => 'string',
                'description' => 'Comma-separated related resources to embed in the response. Available: allocations, location, servers.',
            ],
        ],
    ],
    [
        'name' => 'panel_admin_nodes_deployable',
        'description' => 'Find nodes that have enough free memory and disk to satisfy the given requirements, for automatic deployment.',
        'api' => 'application',
        'method' => 'GET',
        'path' => '/nodes/deployable',
        'query' => [
            'memory' => [
                'type' => 'integer',
                'description' => 'Required memory in MB.',
                'minimum' => 0,
                'maximum' => 9007199254740991,
            ],
            'disk' => [
                'type' => 'integer',
                'description' => 'Required disk in MB.',
                'minimum' => 0,
                'maximum' => 9007199254740991,
            ],
            'location_ids' => [
                'type' => 'array',
                'description' => 'Restrict the search to these location ids.',
                'items' => [
                    'type' => 'integer',
                    'minimum' => -9007199254740991,
                    'maximum' => 9007199254740991,
                ],
            ],
            'page' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'memory',
            'disk',
        ],
    ],
    [
        'name' => 'panel_admin_nodes_view',
        'description' => 'View a single node by numeric id.',
        'api' => 'application',
        'method' => 'GET',
        'path' => '/nodes/{nodeId}',
        'path_params' => [
            'nodeId' => [
                'type' => 'integer',
                'description' => 'Numeric node id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'query' => [
            'include' => [
                'type' => 'string',
                'description' => 'Comma-separated related resources to embed in the response. Available: allocations, location, servers.',
            ],
        ],
        'required' => [
            'nodeId',
        ],
    ],
    [
        'name' => 'panel_admin_nodes_configuration',
        'description' => 'Fetch the wings configuration (config.yml contents) generated for this node.',
        'api' => 'application',
        'method' => 'GET',
        'path' => '/nodes/{nodeId}/configuration',
        'path_params' => [
            'nodeId' => [
                'type' => 'integer',
                'description' => 'Numeric node id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'nodeId',
        ],
    ],
    [
        'name' => 'panel_admin_nodes_create',
        'description' => 'Register a new wings node.',
        'api' => 'application',
        'method' => 'POST',
        'path' => '/nodes',
        'body' => [
            'public' => [
                'type' => 'boolean',
                'description' => 'Whether this node is visible for automatic deployment.',
            ],
            'name' => [
                'type' => 'string',
            ],
            'description' => [
                'type' => [
                    'string',
                    'null',
                ],
            ],
            'location_id' => [
                'type' => 'integer',
                'maximum' => 9007199254740991,
            ],
            'fqdn' => [
                'type' => 'string',
                'description' => 'Hostname or IP address wings listens on.',
            ],
            'scheme' => [
                'type' => 'string',
                'enum' => [
                    'http',
                    'https',
                ],
            ],
            'behind_proxy' => [
                'type' => 'boolean',
            ],
            'maintenance_mode' => [
                'type' => 'boolean',
            ],
            'memory' => [
                'type' => 'integer',
                'description' => 'Total memory in MB.',
                'minimum' => -9007199254740991,
                'maximum' => 9007199254740991,
            ],
            'memory_overallocate' => [
                'type' => 'integer',
                'minimum' => -9007199254740991,
                'maximum' => 9007199254740991,
            ],
            'disk' => [
                'type' => 'integer',
                'description' => 'Total disk in MB.',
                'minimum' => -9007199254740991,
                'maximum' => 9007199254740991,
            ],
            'disk_overallocate' => [
                'type' => 'integer',
                'minimum' => -9007199254740991,
                'maximum' => 9007199254740991,
            ],
            'upload_size' => [
                'type' => 'integer',
                'description' => 'Max file upload size in MB.',
                'minimum' => -9007199254740991,
                'maximum' => 9007199254740991,
            ],
            'daemon_listen' => [
                'type' => 'integer',
                'description' => 'Port wings listens on.',
                'minimum' => -9007199254740991,
                'maximum' => 9007199254740991,
            ],
            'daemon_sftp' => [
                'type' => 'integer',
                'description' => 'Port the wings SFTP server listens on.',
                'minimum' => -9007199254740991,
                'maximum' => 9007199254740991,
            ],
            'daemon_base' => [
                'type' => 'string',
                'description' => 'Base directory wings stores server data in.',
            ],
        ],
        'required' => [
            'name',
            'location_id',
            'fqdn',
            'scheme',
            'memory',
            'memory_overallocate',
            'disk',
            'disk_overallocate',
            'upload_size',
            'daemon_listen',
            'daemon_sftp',
            'daemon_base',
        ],
    ],
    [
        'name' => 'panel_admin_nodes_update',
        'description' => 'Update an existing node. Only the fields provided are changed.',
        'api' => 'application',
        'method' => 'PATCH',
        'path' => '/nodes/{nodeId}',
        'path_params' => [
            'nodeId' => [
                'type' => 'integer',
                'description' => 'Numeric node id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'body' => [
            'public' => [
                'type' => 'boolean',
                'description' => 'Whether this node is visible for automatic deployment.',
            ],
            'name' => [
                'type' => 'string',
            ],
            'description' => [
                'type' => [
                    'string',
                    'null',
                ],
            ],
            'location_id' => [
                'type' => 'integer',
                'maximum' => 9007199254740991,
            ],
            'fqdn' => [
                'type' => 'string',
                'description' => 'Hostname or IP address wings listens on.',
            ],
            'scheme' => [
                'type' => 'string',
                'enum' => [
                    'http',
                    'https',
                ],
            ],
            'behind_proxy' => [
                'type' => 'boolean',
            ],
            'maintenance_mode' => [
                'type' => 'boolean',
            ],
            'memory' => [
                'type' => 'integer',
                'description' => 'Total memory in MB.',
                'minimum' => -9007199254740991,
                'maximum' => 9007199254740991,
            ],
            'memory_overallocate' => [
                'type' => 'integer',
                'minimum' => -9007199254740991,
                'maximum' => 9007199254740991,
            ],
            'disk' => [
                'type' => 'integer',
                'description' => 'Total disk in MB.',
                'minimum' => -9007199254740991,
                'maximum' => 9007199254740991,
            ],
            'disk_overallocate' => [
                'type' => 'integer',
                'minimum' => -9007199254740991,
                'maximum' => 9007199254740991,
            ],
            'upload_size' => [
                'type' => 'integer',
                'description' => 'Max file upload size in MB.',
                'minimum' => -9007199254740991,
                'maximum' => 9007199254740991,
            ],
            'daemon_listen' => [
                'type' => 'integer',
                'description' => 'Port wings listens on.',
                'minimum' => -9007199254740991,
                'maximum' => 9007199254740991,
            ],
            'daemon_sftp' => [
                'type' => 'integer',
                'description' => 'Port the wings SFTP server listens on.',
                'minimum' => -9007199254740991,
                'maximum' => 9007199254740991,
            ],
            'daemon_base' => [
                'type' => 'string',
                'description' => 'Base directory wings stores server data in.',
            ],
        ],
        'required' => [
            'nodeId',
        ],
    ],
    [
        'name' => 'panel_admin_nodes_delete',
        'description' => 'Delete a node. Fails if it still has servers assigned to it. Destructive, cannot be undone.',
        'api' => 'application',
        'method' => 'DELETE',
        'path' => '/nodes/{nodeId}',
        'path_params' => [
            'nodeId' => [
                'type' => 'integer',
                'description' => 'Numeric node id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'nodeId',
        ],
    ],
    [
        'name' => 'panel_admin_nodes_allocations_list',
        'description' => 'List the IP/port allocations defined on a node.',
        'api' => 'application',
        'method' => 'GET',
        'path' => '/nodes/{nodeId}/allocations',
        'path_params' => [
            'nodeId' => [
                'type' => 'integer',
                'description' => 'Numeric node id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'query' => [
            'page' => [
                'type' => 'integer',
                'description' => 'Page number, starting at 1.',
                'minimum' => 1,
                'maximum' => 9007199254740991,
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Results per page (panel default is 50).',
                'minimum' => 1,
                'maximum' => 100,
            ],
            'filter' => [
                'type' => 'object',
                'description' => 'Exact or partial match filters, sent as filter[key]=value. Allowed keys: ip, port, ip_alias, server_id.',
                'additionalProperties' => [
                    'type' => 'string',
                ],
            ],
        ],
        'required' => [
            'nodeId',
        ],
    ],
    [
        'name' => 'panel_admin_nodes_allocations_create',
        'description' => 'Add one or more port allocations to a node for a given IP.',
        'api' => 'application',
        'method' => 'POST',
        'path' => '/nodes/{nodeId}/allocations',
        'path_params' => [
            'nodeId' => [
                'type' => 'integer',
                'description' => 'Numeric node id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'body' => [
            'ip' => [
                'type' => 'string',
                'description' => 'IP address to allocate ports on.',
            ],
            'alias' => [
                'type' => [
                    'string',
                    'null',
                ],
                'description' => 'Friendly alias shown instead of the raw IP.',
            ],
            'ports' => [
                'type' => 'array',
                'description' => 'Ports or ranges to allocate, e.g. ["25565", "25570-25580"].',
                'items' => [
                    'type' => 'string',
                ],
            ],
        ],
        'required' => [
            'nodeId',
            'ip',
            'ports',
        ],
    ],
    [
        'name' => 'panel_admin_nodes_allocations_delete',
        'description' => 'Remove an allocation from a node. Fails if it is currently assigned to a server. Destructive, cannot be undone.',
        'api' => 'application',
        'method' => 'DELETE',
        'path' => '/nodes/{nodeId}/allocations/{allocationId}',
        'path_params' => [
            'nodeId' => [
                'type' => 'integer',
                'description' => 'Numeric node id.',
                'maximum' => 9007199254740991,
            ],
            'allocationId' => [
                'type' => 'integer',
                'description' => 'Numeric allocation id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'nodeId',
            'allocationId',
        ],
    ],
    [
        'name' => 'panel_admin_locations_list',
        'description' => 'List deployment locations.',
        'api' => 'application',
        'method' => 'GET',
        'path' => '/locations',
        'query' => [
            'page' => [
                'type' => 'integer',
                'description' => 'Page number, starting at 1.',
                'minimum' => 1,
                'maximum' => 9007199254740991,
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Results per page (panel default is 50).',
                'minimum' => 1,
                'maximum' => 100,
            ],
            'filter' => [
                'type' => 'object',
                'description' => 'Exact or partial match filters, sent as filter[key]=value. Allowed keys: short, long.',
                'additionalProperties' => [
                    'type' => 'string',
                ],
            ],
            'sort' => [
                'type' => 'string',
                'description' => 'Field to sort results by, prefix with "-" for descending. Allowed: id.',
            ],
            'include' => [
                'type' => 'string',
                'description' => 'Comma-separated related resources to embed in the response. Available: nodes, servers.',
            ],
        ],
    ],
    [
        'name' => 'panel_admin_locations_view',
        'description' => 'View a single location by numeric id.',
        'api' => 'application',
        'method' => 'GET',
        'path' => '/locations/{locationId}',
        'path_params' => [
            'locationId' => [
                'type' => 'integer',
                'description' => 'Numeric location id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'query' => [
            'include' => [
                'type' => 'string',
                'description' => 'Comma-separated related resources to embed in the response. Available: nodes, servers.',
            ],
        ],
        'required' => [
            'locationId',
        ],
    ],
    [
        'name' => 'panel_admin_locations_create',
        'description' => 'Create a new location.',
        'api' => 'application',
        'method' => 'POST',
        'path' => '/locations',
        'body' => [
            'short' => [
                'type' => 'string',
                'description' => 'Short identifier shown in listings, e.g. "us-east".',
            ],
            'long' => [
                'type' => [
                    'string',
                    'null',
                ],
                'description' => 'Longer human-readable description.',
            ],
        ],
        'required' => [
            'short',
        ],
    ],
    [
        'name' => 'panel_admin_locations_update',
        'description' => 'Update a location. Only the fields provided are changed.',
        'api' => 'application',
        'method' => 'PATCH',
        'path' => '/locations/{locationId}',
        'path_params' => [
            'locationId' => [
                'type' => 'integer',
                'description' => 'Numeric location id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'body' => [
            'short' => [
                'type' => 'string',
            ],
            'long' => [
                'type' => [
                    'string',
                    'null',
                ],
            ],
        ],
        'required' => [
            'locationId',
        ],
    ],
    [
        'name' => 'panel_admin_locations_delete',
        'description' => 'Delete a location. Fails if nodes still reference it. Destructive, cannot be undone.',
        'api' => 'application',
        'method' => 'DELETE',
        'path' => '/locations/{locationId}',
        'path_params' => [
            'locationId' => [
                'type' => 'integer',
                'description' => 'Numeric location id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'locationId',
        ],
    ],
    [
        'name' => 'panel_admin_servers_list',
        'description' => 'List all servers on the panel.',
        'api' => 'application',
        'method' => 'GET',
        'path' => '/servers',
        'query' => [
            'page' => [
                'type' => 'integer',
                'description' => 'Page number, starting at 1.',
                'minimum' => 1,
                'maximum' => 9007199254740991,
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Results per page (panel default is 50).',
                'minimum' => 1,
                'maximum' => 100,
            ],
            'filter' => [
                'type' => 'object',
                'description' => 'Exact or partial match filters, sent as filter[key]=value. Allowed keys: uuid, uuidShort, name, description, image, external_id.',
                'additionalProperties' => [
                    'type' => 'string',
                ],
            ],
            'sort' => [
                'type' => 'string',
                'description' => 'Field to sort results by, prefix with "-" for descending. Allowed: id, uuid.',
            ],
            'include' => [
                'type' => 'string',
                'description' => 'Comma-separated related resources to embed in the response. Available: allocations, user, subusers, nest, egg, variables, location, node.',
            ],
        ],
    ],
    [
        'name' => 'panel_admin_servers_view',
        'description' => 'View a single server by numeric id.',
        'api' => 'application',
        'method' => 'GET',
        'path' => '/servers/{serverId}',
        'path_params' => [
            'serverId' => [
                'type' => 'integer',
                'description' => 'Numeric server id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'query' => [
            'include' => [
                'type' => 'string',
                'description' => 'Comma-separated related resources to embed in the response. Available: allocations, user, subusers, nest, egg, variables, location, node.',
            ],
        ],
        'required' => [
            'serverId',
        ],
    ],
    [
        'name' => 'panel_admin_servers_view_by_external_id',
        'description' => 'View a single server by its external_id.',
        'api' => 'application',
        'method' => 'GET',
        'path' => '/servers/external/{externalId}',
        'path_params' => [
            'externalId' => [
                'type' => 'string',
            ],
        ],
        'required' => [
            'externalId',
        ],
    ],
    [
        'name' => 'panel_admin_servers_create',
        'description' => 'Create a new server. Either specify a node/allocation directly, or a deploy block to have the panel pick a viable node.',
        'api' => 'application',
        'method' => 'POST',
        'path' => '/servers',
        'body' => [
            'external_id' => [
                'type' => [
                    'string',
                    'null',
                ],
            ],
            'name' => [
                'type' => 'string',
            ],
            'description' => [
                'type' => [
                    'string',
                    'null',
                ],
            ],
            'user' => [
                'type' => 'integer',
                'description' => 'Owner user id.',
                'maximum' => 9007199254740991,
            ],
            'egg' => [
                'type' => 'integer',
                'description' => 'Egg id to install.',
                'maximum' => 9007199254740991,
            ],
            'docker_image' => [
                'type' => 'string',
            ],
            'startup' => [
                'type' => 'string',
                'description' => 'Startup command template.',
            ],
            'environment' => [
                'type' => 'object',
                'description' => 'Egg variable values, keyed by variable name.',
                'additionalProperties' => true,
            ],
            'skip_scripts' => [
                'type' => 'boolean',
                'description' => 'Skip running the egg install script.',
            ],
            'oom_disabled' => [
                'type' => 'boolean',
            ],
            'limits' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'memory' => [
                        'type' => 'integer',
                        'minimum' => -9007199254740991,
                        'maximum' => 9007199254740991,
                    ],
                    'swap' => [
                        'type' => 'integer',
                        'minimum' => -9007199254740991,
                        'maximum' => 9007199254740991,
                    ],
                    'disk' => [
                        'type' => 'integer',
                        'minimum' => -9007199254740991,
                        'maximum' => 9007199254740991,
                    ],
                    'io' => [
                        'type' => 'integer',
                        'minimum' => -9007199254740991,
                        'maximum' => 9007199254740991,
                    ],
                    'threads' => [
                        'type' => [
                            'string',
                            'null',
                        ],
                    ],
                    'cpu' => [
                        'type' => 'integer',
                        'minimum' => -9007199254740991,
                        'maximum' => 9007199254740991,
                    ],
                ],
                'required' => [
                    'memory',
                    'swap',
                    'disk',
                    'io',
                    'cpu',
                ],
            ],
            'feature_limits' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'databases' => [
                        'type' => [
                            'integer',
                            'null',
                        ],
                        'minimum' => -9007199254740991,
                        'maximum' => 9007199254740991,
                    ],
                    'allocations' => [
                        'type' => [
                            'integer',
                            'null',
                        ],
                        'minimum' => -9007199254740991,
                        'maximum' => 9007199254740991,
                    ],
                    'backups' => [
                        'type' => [
                            'integer',
                            'null',
                        ],
                        'minimum' => -9007199254740991,
                        'maximum' => 9007199254740991,
                    ],
                ],
            ],
            'allocation' => [
                'type' => 'object',
                'description' => 'Required unless deploy is used.',
                'additionalProperties' => false,
                'properties' => [
                    'default' => [
                        'type' => 'integer',
                        'description' => 'Allocation id to use as the primary allocation.',
                        'minimum' => -9007199254740991,
                        'maximum' => 9007199254740991,
                    ],
                    'additional' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'integer',
                            'minimum' => -9007199254740991,
                            'maximum' => 9007199254740991,
                        ],
                    ],
                ],
            ],
            'deploy' => [
                'type' => 'object',
                'description' => 'If set, the panel picks a viable node/allocation instead of using the allocation block.',
                'additionalProperties' => false,
                'properties' => [
                    'locations' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'integer',
                            'minimum' => -9007199254740991,
                            'maximum' => 9007199254740991,
                        ],
                    ],
                    'dedicated_ip' => [
                        'type' => 'boolean',
                    ],
                    'port_range' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                        ],
                    ],
                ],
            ],
            'start_on_completion' => [
                'type' => 'boolean',
            ],
        ],
        'required' => [
            'name',
            'user',
            'egg',
            'docker_image',
            'startup',
            'environment',
            'limits',
            'feature_limits',
        ],
    ],
    [
        'name' => 'panel_admin_servers_update_details',
        'description' => "Update a server's name, description, owner or external_id.",
        'api' => 'application',
        'method' => 'PATCH',
        'path' => '/servers/{serverId}/details',
        'path_params' => [
            'serverId' => [
                'type' => 'integer',
                'description' => 'Numeric server id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'body' => [
            'external_id' => [
                'type' => [
                    'string',
                    'null',
                ],
            ],
            'name' => [
                'type' => 'string',
            ],
            'user' => [
                'type' => 'integer',
                'description' => 'New owner user id.',
                'maximum' => 9007199254740991,
            ],
            'description' => [
                'type' => [
                    'string',
                    'null',
                ],
            ],
        ],
        'required' => [
            'serverId',
        ],
    ],
    [
        'name' => 'panel_admin_servers_update_build',
        'description' => "Update a server's resource limits, feature limits and allocations.",
        'api' => 'application',
        'method' => 'PATCH',
        'path' => '/servers/{serverId}/build',
        'path_params' => [
            'serverId' => [
                'type' => 'integer',
                'description' => 'Numeric server id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'body' => [
            'allocation' => [
                'type' => 'integer',
                'description' => 'Primary allocation id.',
                'maximum' => 9007199254740991,
            ],
            'oom_disabled' => [
                'type' => 'boolean',
            ],
            'limits' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'memory' => [
                        'type' => 'integer',
                        'minimum' => -9007199254740991,
                        'maximum' => 9007199254740991,
                    ],
                    'swap' => [
                        'type' => 'integer',
                        'minimum' => -9007199254740991,
                        'maximum' => 9007199254740991,
                    ],
                    'io' => [
                        'type' => 'integer',
                        'minimum' => -9007199254740991,
                        'maximum' => 9007199254740991,
                    ],
                    'cpu' => [
                        'type' => 'integer',
                        'minimum' => -9007199254740991,
                        'maximum' => 9007199254740991,
                    ],
                    'threads' => [
                        'type' => [
                            'string',
                            'null',
                        ],
                    ],
                    'disk' => [
                        'type' => 'integer',
                        'minimum' => -9007199254740991,
                        'maximum' => 9007199254740991,
                    ],
                ],
            ],
            'add_allocations' => [
                'type' => 'array',
                'items' => [
                    'type' => 'integer',
                    'minimum' => -9007199254740991,
                    'maximum' => 9007199254740991,
                ],
            ],
            'remove_allocations' => [
                'type' => 'array',
                'items' => [
                    'type' => 'integer',
                    'minimum' => -9007199254740991,
                    'maximum' => 9007199254740991,
                ],
            ],
            'feature_limits' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'databases' => [
                        'type' => [
                            'integer',
                            'null',
                        ],
                        'minimum' => -9007199254740991,
                        'maximum' => 9007199254740991,
                    ],
                    'allocations' => [
                        'type' => [
                            'integer',
                            'null',
                        ],
                        'minimum' => -9007199254740991,
                        'maximum' => 9007199254740991,
                    ],
                    'backups' => [
                        'type' => [
                            'integer',
                            'null',
                        ],
                        'minimum' => -9007199254740991,
                        'maximum' => 9007199254740991,
                    ],
                ],
            ],
        ],
        'required' => [
            'serverId',
            'feature_limits',
        ],
    ],
    [
        'name' => 'panel_admin_servers_update_startup',
        'description' => "Update a server's startup command, egg, docker image and environment variables.",
        'api' => 'application',
        'method' => 'PATCH',
        'path' => '/servers/{serverId}/startup',
        'path_params' => [
            'serverId' => [
                'type' => 'integer',
                'description' => 'Numeric server id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'body' => [
            'startup' => [
                'type' => 'string',
            ],
            'environment' => [
                'type' => 'object',
                'additionalProperties' => true,
            ],
            'egg' => [
                'type' => 'integer',
                'maximum' => 9007199254740991,
            ],
            'image' => [
                'type' => 'string',
            ],
            'skip_scripts' => [
                'type' => 'boolean',
            ],
        ],
        'required' => [
            'serverId',
            'startup',
            'environment',
            'egg',
            'image',
        ],
    ],
    [
        'name' => 'panel_admin_servers_suspend',
        'description' => 'Suspend a server, blocking user access and stopping it on wings. Reversible with unsuspend.',
        'api' => 'application',
        'method' => 'POST',
        'path' => '/servers/{serverId}/suspend',
        'path_params' => [
            'serverId' => [
                'type' => 'integer',
                'description' => 'Numeric server id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'serverId',
        ],
    ],
    [
        'name' => 'panel_admin_servers_unsuspend',
        'description' => 'Lift a previous suspension on a server.',
        'api' => 'application',
        'method' => 'POST',
        'path' => '/servers/{serverId}/unsuspend',
        'path_params' => [
            'serverId' => [
                'type' => 'integer',
                'description' => 'Numeric server id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'serverId',
        ],
    ],
    [
        'name' => 'panel_admin_servers_reinstall',
        'description' => 'Reinstall a server by re-running its egg install script. Destructive: may remove or overwrite server files depending on the egg.',
        'api' => 'application',
        'method' => 'POST',
        'path' => '/servers/{serverId}/reinstall',
        'path_params' => [
            'serverId' => [
                'type' => 'integer',
                'description' => 'Numeric server id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'serverId',
        ],
        'destructive' => true,
    ],
    [
        'name' => 'panel_admin_servers_delete',
        'description' => 'Delete a server and its data. Fails if wings cannot be reached to clean up files. Destructive, cannot be undone.',
        'api' => 'application',
        'method' => 'DELETE',
        'path' => '/servers/{serverId}',
        'path_params' => [
            'serverId' => [
                'type' => 'integer',
                'description' => 'Numeric server id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'serverId',
        ],
    ],
    [
        'name' => 'panel_admin_servers_force_delete',
        'description' => 'Delete a server and its database records even if wings cannot be reached to clean up files. Destructive, cannot be undone.',
        'api' => 'application',
        'method' => 'DELETE',
        'path' => '/servers/{serverId}/force',
        'path_params' => [
            'serverId' => [
                'type' => 'integer',
                'description' => 'Numeric server id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'serverId',
        ],
    ],
    [
        'name' => 'panel_admin_servers_databases_list',
        'description' => "List a server's databases.",
        'api' => 'application',
        'method' => 'GET',
        'path' => '/servers/{serverId}/databases',
        'path_params' => [
            'serverId' => [
                'type' => 'integer',
                'description' => 'Numeric server id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'query' => [
            'include' => [
                'type' => 'string',
                'description' => 'Comma-separated related resources to embed in the response. Available: password, host.',
            ],
        ],
        'required' => [
            'serverId',
        ],
    ],
    [
        'name' => 'panel_admin_servers_databases_view',
        'description' => 'View a single server database.',
        'api' => 'application',
        'method' => 'GET',
        'path' => '/servers/{serverId}/databases/{databaseId}',
        'path_params' => [
            'serverId' => [
                'type' => 'integer',
                'description' => 'Numeric server id.',
                'maximum' => 9007199254740991,
            ],
            'databaseId' => [
                'type' => 'integer',
                'description' => 'Numeric server database id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'query' => [
            'include' => [
                'type' => 'string',
                'description' => 'Comma-separated related resources to embed in the response. Available: password, host.',
            ],
        ],
        'required' => [
            'serverId',
            'databaseId',
        ],
    ],
    [
        'name' => 'panel_admin_servers_databases_create',
        'description' => 'Create a new database for a server on a given database host.',
        'api' => 'application',
        'method' => 'POST',
        'path' => '/servers/{serverId}/databases',
        'path_params' => [
            'serverId' => [
                'type' => 'integer',
                'description' => 'Numeric server id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'body' => [
            'database' => [
                'type' => 'string',
                'description' => 'Database name.',
            ],
            'remote' => [
                'type' => 'string',
                'description' => 'CIDR or hostname pattern allowed to connect, e.g. "%".',
            ],
            'host' => [
                'type' => 'integer',
                'description' => 'Numeric id of the database host to create it on.',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'serverId',
            'database',
            'remote',
            'host',
        ],
    ],
    [
        'name' => 'panel_admin_servers_databases_reset_password',
        'description' => "Reset a server database's password. Destructive: the old password stops working immediately, breaking anything still using it.",
        'api' => 'application',
        'method' => 'POST',
        'path' => '/servers/{serverId}/databases/{databaseId}/reset-password',
        'path_params' => [
            'serverId' => [
                'type' => 'integer',
                'description' => 'Numeric server id.',
                'maximum' => 9007199254740991,
            ],
            'databaseId' => [
                'type' => 'integer',
                'description' => 'Numeric server database id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'serverId',
            'databaseId',
        ],
        'destructive' => true,
    ],
    [
        'name' => 'panel_admin_servers_databases_delete',
        'description' => 'Delete a server database. Destructive, cannot be undone.',
        'api' => 'application',
        'method' => 'DELETE',
        'path' => '/servers/{serverId}/databases/{databaseId}',
        'path_params' => [
            'serverId' => [
                'type' => 'integer',
                'description' => 'Numeric server id.',
                'maximum' => 9007199254740991,
            ],
            'databaseId' => [
                'type' => 'integer',
                'description' => 'Numeric server database id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'serverId',
            'databaseId',
        ],
    ],
    [
        'name' => 'panel_admin_nests_list',
        'description' => 'List nests (egg categories). Read-only: the Application API has no endpoints to create, update or delete nests.',
        'api' => 'application',
        'method' => 'GET',
        'path' => '/nests',
        'query' => [
            'page' => [
                'type' => 'integer',
                'description' => 'Page number, starting at 1.',
                'minimum' => 1,
                'maximum' => 9007199254740991,
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Results per page (panel default is 50).',
                'minimum' => 1,
                'maximum' => 100,
            ],
            'include' => [
                'type' => 'string',
                'description' => 'Comma-separated related resources to embed in the response. Available: eggs, servers.',
            ],
        ],
    ],
    [
        'name' => 'panel_admin_nests_view',
        'description' => 'View a single nest by numeric id.',
        'api' => 'application',
        'method' => 'GET',
        'path' => '/nests/{nestId}',
        'path_params' => [
            'nestId' => [
                'type' => 'integer',
                'description' => 'Numeric nest id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'query' => [
            'include' => [
                'type' => 'string',
                'description' => 'Comma-separated related resources to embed in the response. Available: eggs, servers.',
            ],
        ],
        'required' => [
            'nestId',
        ],
    ],
    [
        'name' => 'panel_admin_nests_eggs_list',
        'description' => 'List the eggs within a nest. Read-only: the Application API has no endpoints to create, update or import eggs.',
        'api' => 'application',
        'method' => 'GET',
        'path' => '/nests/{nestId}/eggs',
        'path_params' => [
            'nestId' => [
                'type' => 'integer',
                'description' => 'Numeric nest id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'query' => [
            'page' => [
                'type' => 'integer',
                'description' => 'Page number, starting at 1.',
                'minimum' => 1,
                'maximum' => 9007199254740991,
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Results per page (panel default is 50).',
                'minimum' => 1,
                'maximum' => 100,
            ],
            'include' => [
                'type' => 'string',
                'description' => 'Comma-separated related resources to embed in the response. Available: nest, servers, config, script, variables.',
            ],
        ],
        'required' => [
            'nestId',
        ],
    ],
    [
        'name' => 'panel_admin_nests_eggs_view',
        'description' => 'View a single egg definition.',
        'api' => 'application',
        'method' => 'GET',
        'path' => '/nests/{nestId}/eggs/{eggId}',
        'path_params' => [
            'nestId' => [
                'type' => 'integer',
                'description' => 'Numeric nest id.',
                'maximum' => 9007199254740991,
            ],
            'eggId' => [
                'type' => 'integer',
                'description' => 'Numeric egg id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'query' => [
            'include' => [
                'type' => 'string',
                'description' => 'Comma-separated related resources to embed in the response. Available: nest, servers, config, script, variables.',
            ],
        ],
        'required' => [
            'nestId',
            'eggId',
        ],
    ],
    [
        'name' => 'panel_admin_backups_list',
        'description' => 'List backups across every server on the panel. Unlike panel_client_servers_backups_list, which is scoped to one server, this is panel-wide. Supports pagination and filtering.',
        'api' => 'application',
        'method' => 'GET',
        'path' => '/backups',
        'query' => [
            'page' => [
                'type' => 'integer',
                'description' => 'Page number, starting at 1.',
                'minimum' => 1,
                'maximum' => 9007199254740991,
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Results per page (panel default is 50).',
                'minimum' => 1,
                'maximum' => 100,
            ],
            'filter' => [
                'type' => 'object',
                'description' => 'Exact or partial match filters, sent as filter[key]=value. Allowed keys: uuid, server_id, disk, is_successful, is_locked.',
                'additionalProperties' => [
                    'type' => 'string',
                ],
            ],
            'sort' => [
                'type' => 'string',
                'description' => 'Field to sort results by, prefix with "-" for descending. Allowed: id, uuid, created_at, bytes.',
            ],
            'include' => [
                'type' => 'string',
                'description' => 'Comma-separated related resources to embed in the response. Available: server.',
            ],
        ],
    ],
    [
        'name' => 'panel_admin_backups_view',
        'description' => 'View a single backup by numeric id, panel-wide rather than scoped to one server.',
        'api' => 'application',
        'method' => 'GET',
        'path' => '/backups/{backupId}',
        'path_params' => [
            'backupId' => [
                'type' => 'integer',
                'description' => 'Numeric backup id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'backupId',
        ],
    ],
    [
        'name' => 'panel_admin_backups_delete',
        'description' => 'Permanently delete a backup by numeric id, panel-wide. Refused if the backup is locked. Destructive, cannot be undone.',
        'api' => 'application',
        'method' => 'DELETE',
        'path' => '/backups/{backupId}',
        'path_params' => [
            'backupId' => [
                'type' => 'integer',
                'description' => 'Numeric backup id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'backupId',
        ],
    ],
    [
        'name' => 'panel_admin_orphaned_backups_list',
        'description' => "List orphaned backups: stored backup data that outlived the server it belonged to. Each row keeps the deleted server's name. Supports pagination and filtering.",
        'api' => 'application',
        'method' => 'GET',
        'path' => '/orphaned-backups',
        'query' => [
            'page' => [
                'type' => 'integer',
                'description' => 'Page number, starting at 1.',
                'minimum' => 1,
                'maximum' => 9007199254740991,
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Results per page (panel default is 50).',
                'minimum' => 1,
                'maximum' => 100,
            ],
            'filter' => [
                'type' => 'object',
                'description' => 'Exact or partial match filters, sent as filter[key]=value. Allowed keys: backup_uuid, server_uuid, disk, node_id.',
                'additionalProperties' => [
                    'type' => 'string',
                ],
            ],
            'sort' => [
                'type' => 'string',
                'description' => 'Field to sort results by, prefix with "-" for descending. Allowed: id, backup_created_at, bytes.',
            ],
            'include' => [
                'type' => 'string',
                'description' => 'Comma-separated related resources to embed in the response. Available: node.',
            ],
        ],
    ],
    [
        'name' => 'panel_admin_orphaned_backups_view',
        'description' => "View a single orphaned backup by numeric id. The row keeps the deleted server's name since the server itself no longer exists.",
        'api' => 'application',
        'method' => 'GET',
        'path' => '/orphaned-backups/{orphanedBackupId}',
        'path_params' => [
            'orphanedBackupId' => [
                'type' => 'integer',
                'description' => 'Numeric orphaned backup id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'orphanedBackupId',
        ],
    ],
    [
        'name' => 'panel_admin_orphaned_backups_delete',
        'description' => "Permanently delete the stored data behind an orphaned backup. Removes the backup archive itself; use panel_admin_orphaned_backups_forget instead to drop only the panel's record and leave the data in place. Destructive, cannot be undone.",
        'api' => 'application',
        'method' => 'DELETE',
        'path' => '/orphaned-backups/{orphanedBackupId}',
        'path_params' => [
            'orphanedBackupId' => [
                'type' => 'integer',
                'description' => 'Numeric orphaned backup id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'orphanedBackupId',
        ],
    ],
    [
        'name' => 'panel_admin_orphaned_backups_forget',
        'description' => "Remove the panel's record of an orphaned backup without touching the stored data, which is left in place and becomes untracked; use panel_admin_orphaned_backups_delete instead to remove the data too. Destructive: cannot be undone.",
        'api' => 'application',
        'method' => 'POST',
        'path' => '/orphaned-backups/{orphanedBackupId}/forget',
        'path_params' => [
            'orphanedBackupId' => [
                'type' => 'integer',
                'description' => 'Numeric orphaned backup id.',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'orphanedBackupId',
        ],
        'destructive' => true,
    ],
    [
        'name' => 'panel_client_servers_list',
        'description' => "List servers the API key's owner can access (owned or as a subuser).",
        'api' => 'client',
        'method' => 'GET',
        'path' => '/',
        'query' => [
            'page' => [
                'type' => 'integer',
                'description' => 'Page number, starting at 1.',
                'minimum' => 1,
                'maximum' => 9007199254740991,
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Results per page (panel default is 50).',
                'minimum' => 1,
                'maximum' => 100,
            ],
            'filter' => [
                'type' => 'object',
                'description' => 'Exact or partial match filters, sent as filter[key]=value. Allowed keys: uuid, name, description, external_id.',
                'additionalProperties' => [
                    'type' => 'string',
                ],
            ],
            'type' => [
                'type' => 'string',
                'description' => '"admin"/"admin-all" only work for root admins and widen the results beyond owned/subuser servers.',
                'enum' => [
                    'owner',
                    'admin',
                    'admin-all',
                ],
            ],
        ],
    ],
    [
        'name' => 'panel_client_permissions',
        'description' => 'List the permission strings that can be assigned to subusers.',
        'api' => 'client',
        'method' => 'GET',
        'path' => '/permissions',
    ],
    [
        'name' => 'panel_client_account_view',
        'description' => "View the API key owner's account details.",
        'api' => 'client',
        'method' => 'GET',
        'path' => '/account',
    ],
    [
        'name' => 'panel_client_account_two_factor_view',
        'description' => 'Fetch a new two-factor setup secret and QR code image for the account. Fails if 2FA is already enabled.',
        'api' => 'client',
        'method' => 'GET',
        'path' => '/account/two-factor',
    ],
    [
        'name' => 'panel_client_account_two_factor_enable',
        'description' => 'Confirm two-factor setup with a TOTP code and enable it on the account. Returns one-time recovery tokens.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/account/two-factor',
        'body' => [
            'code' => [
                'type' => 'string',
                'description' => 'Current 6-digit TOTP code.',
            ],
            'password' => [
                'type' => 'string',
                'description' => 'Account password, required to confirm the change.',
            ],
        ],
        'required' => [
            'code',
            'password',
        ],
    ],
    [
        'name' => 'panel_client_account_two_factor_disable',
        'description' => 'Disable two-factor authentication on the account. Destructive: removes a security control from the account.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/account/two-factor/disable',
        'body' => [
            'password' => [
                'type' => 'string',
            ],
        ],
        'required' => [
            'password',
        ],
        'destructive' => true,
    ],
    [
        'name' => 'panel_client_account_update_email',
        'description' => "Change the account's email address.",
        'api' => 'client',
        'method' => 'PUT',
        'path' => '/account/email',
        'body' => [
            'email' => [
                'type' => 'string',
                'format' => 'email',
            ],
            'password' => [
                'type' => 'string',
                'description' => 'Current password, required to confirm the change.',
            ],
        ],
        'required' => [
            'email',
            'password',
        ],
    ],
    [
        'name' => 'panel_client_account_update_password',
        'description' => "Change the account's password. Invalidates existing sessions.",
        'api' => 'client',
        'method' => 'PUT',
        'path' => '/account/password',
        'body' => [
            'current_password' => [
                'type' => 'string',
            ],
            'password' => [
                'type' => 'string',
            ],
            'password_confirmation' => [
                'type' => 'string',
            ],
        ],
        'required' => [
            'current_password',
            'password',
            'password_confirmation',
        ],
    ],
    [
        'name' => 'panel_client_account_activity',
        'description' => "List the account's activity log entries.",
        'api' => 'client',
        'method' => 'GET',
        'path' => '/account/activity',
        'query' => [
            'page' => [
                'type' => 'integer',
                'description' => 'Page number, starting at 1.',
                'minimum' => 1,
                'maximum' => 9007199254740991,
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Results per page (panel default is 50).',
                'minimum' => 1,
                'maximum' => 100,
            ],
            'filter' => [
                'type' => 'object',
                'description' => 'Exact or partial match filters, sent as filter[key]=value. Allowed keys: event (partial match).',
                'additionalProperties' => [
                    'type' => 'string',
                ],
            ],
            'sort' => [
                'type' => 'string',
                'description' => 'Field to sort results by, prefix with "-" for descending. Allowed: timestamp.',
            ],
        ],
    ],
    [
        'name' => 'panel_client_account_api_keys_list',
        'description' => "List the account's API keys (metadata only, not the secret values).",
        'api' => 'client',
        'method' => 'GET',
        'path' => '/account/api-keys',
    ],
    [
        'name' => 'panel_client_account_api_keys_create',
        'description' => 'Create a new API key for the account. The secret value is only returned once, in the response.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/account/api-keys',
        'body' => [
            'description' => [
                'type' => 'string',
                'description' => 'Label to identify this key.',
            ],
            'allowed_ips' => [
                'type' => 'array',
                'description' => 'IPs or CIDR ranges allowed to use this key; empty allows any.',
                'items' => [
                    'type' => 'string',
                ],
            ],
        ],
        'required' => [
            'description',
        ],
    ],
    [
        'name' => 'panel_client_account_api_keys_delete',
        'description' => 'Revoke an API key by its identifier. Destructive, cannot be undone; any integration using it stops working.',
        'api' => 'client',
        'method' => 'DELETE',
        'path' => '/account/api-keys/{identifier}',
        'path_params' => [
            'identifier' => [
                'type' => 'string',
            ],
        ],
        'required' => [
            'identifier',
        ],
    ],
    [
        'name' => 'panel_client_account_ssh_keys_list',
        'description' => "List the account's registered SSH public keys.",
        'api' => 'client',
        'method' => 'GET',
        'path' => '/account/ssh-keys',
    ],
    [
        'name' => 'panel_client_account_ssh_keys_create',
        'description' => 'Add an SSH public key to the account.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/account/ssh-keys',
        'body' => [
            'name' => [
                'type' => 'string',
                'description' => 'Label to identify this key.',
            ],
            'public_key' => [
                'type' => 'string',
            ],
        ],
        'required' => [
            'name',
            'public_key',
        ],
    ],
    [
        'name' => 'panel_client_account_ssh_keys_delete',
        'description' => 'Remove an SSH public key from the account by its fingerprint. Destructive, cannot be undone.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/account/ssh-keys/remove',
        'body' => [
            'fingerprint' => [
                'type' => 'string',
            ],
        ],
        'required' => [
            'fingerprint',
        ],
        'destructive' => true,
    ],
    [
        'name' => 'panel_client_servers_view',
        'description' => 'View a single server, including its current install/suspension state.',
        'api' => 'client',
        'method' => 'GET',
        'path' => '/servers/{serverId}',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'required' => [
            'serverId',
        ],
    ],
    [
        'name' => 'panel_client_servers_resources',
        'description' => "Fetch a server's live resource usage (CPU, memory, disk, network) and power state.",
        'api' => 'client',
        'method' => 'GET',
        'path' => '/servers/{serverId}/resources',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'required' => [
            'serverId',
        ],
    ],
    [
        'name' => 'panel_client_servers_activity',
        'description' => "List a server's activity log entries.",
        'api' => 'client',
        'method' => 'GET',
        'path' => '/servers/{serverId}/activity',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'query' => [
            'page' => [
                'type' => 'integer',
                'description' => 'Page number, starting at 1.',
                'minimum' => 1,
                'maximum' => 9007199254740991,
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Results per page (panel default is 50).',
                'minimum' => 1,
                'maximum' => 100,
            ],
            'filter' => [
                'type' => 'object',
                'description' => 'Exact or partial match filters, sent as filter[key]=value. Allowed keys: event (partial match).',
                'additionalProperties' => [
                    'type' => 'string',
                ],
            ],
            'sort' => [
                'type' => 'string',
                'description' => 'Field to sort results by, prefix with "-" for descending. Allowed: timestamp.',
            ],
        ],
        'required' => [
            'serverId',
        ],
    ],
    [
        'name' => 'panel_client_servers_command',
        'description' => "Send a console command to a server's running process. Requires the server to be online.",
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/command',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'body' => [
            'command' => [
                'type' => 'string',
            ],
        ],
        'required' => [
            'serverId',
            'command',
        ],
    ],
    [
        'name' => 'panel_client_servers_power',
        'description' => 'Send a power action to a server.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/power',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'body' => [
            'signal' => [
                'type' => 'string',
                'enum' => [
                    'start',
                    'stop',
                    'restart',
                    'kill',
                ],
            ],
        ],
        'required' => [
            'serverId',
            'signal',
        ],
    ],
    [
        'name' => 'panel_client_servers_databases_list',
        'description' => "List a server's databases.",
        'api' => 'client',
        'method' => 'GET',
        'path' => '/servers/{serverId}/databases',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'query' => [
            'include' => [
                'type' => 'string',
                'description' => 'Comma-separated related resources to embed in the response. Available: password.',
            ],
        ],
        'required' => [
            'serverId',
        ],
    ],
    [
        'name' => 'panel_client_servers_databases_create',
        'description' => 'Create a new database for a server, subject to the database limit set by an admin.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/databases',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'body' => [
            'database' => [
                'type' => 'string',
            ],
            'remote' => [
                'type' => 'string',
                'description' => 'CIDR or hostname pattern allowed to connect, e.g. "%".',
            ],
        ],
        'required' => [
            'serverId',
            'database',
            'remote',
        ],
    ],
    [
        'name' => 'panel_client_servers_databases_rotate_password',
        'description' => "Rotate a server database's password. Destructive: the old password stops working immediately, breaking anything still using it.",
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/databases/{databaseId}/rotate-password',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
            'databaseId' => [
                'type' => 'string',
                'description' => 'Database identifier as returned by the API (not the numeric id).',
            ],
        ],
        'required' => [
            'serverId',
            'databaseId',
        ],
        'destructive' => true,
    ],
    [
        'name' => 'panel_client_servers_databases_delete',
        'description' => 'Delete a server database. Destructive, cannot be undone.',
        'api' => 'client',
        'method' => 'DELETE',
        'path' => '/servers/{serverId}/databases/{databaseId}',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
            'databaseId' => [
                'type' => 'string',
                'description' => 'Database identifier as returned by the API (not the numeric id).',
            ],
        ],
        'required' => [
            'serverId',
            'databaseId',
        ],
    ],
    [
        'name' => 'panel_client_servers_files_list',
        'description' => 'List files and folders in a directory on the server.',
        'api' => 'client',
        'method' => 'GET',
        'path' => '/servers/{serverId}/files/list',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'query' => [
            'directory' => [
                'type' => 'string',
                'description' => 'Directory to list, defaults to "/".',
            ],
        ],
        'required' => [
            'serverId',
        ],
    ],
    [
        'name' => 'panel_client_servers_files_contents',
        'description' => 'Read the raw text contents of a file. Returns plain text, not JSON. Fails for files above the configured edit size limit.',
        'api' => 'client',
        'method' => 'GET',
        'path' => '/servers/{serverId}/files/contents',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'query' => [
            'file' => [
                'type' => 'string',
                'description' => 'Absolute path of the file to read.',
            ],
        ],
        'required' => [
            'serverId',
            'file',
        ],
        'response_type' => 'text',
    ],
    [
        'name' => 'panel_client_servers_files_download',
        'description' => 'Request a one-time signed download URL for a file. The tool returns the signed Wings URL, not the file contents; fetch it separately and it expires after a short time.',
        'api' => 'client',
        'method' => 'GET',
        'path' => '/servers/{serverId}/files/download',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'query' => [
            'file' => [
                'type' => 'string',
                'description' => 'Absolute path of the file to download.',
            ],
        ],
        'required' => [
            'serverId',
            'file',
        ],
    ],
    [
        'name' => 'panel_client_servers_files_rename',
        'description' => 'Rename or move one or more files within a directory.',
        'api' => 'client',
        'method' => 'PUT',
        'path' => '/servers/{serverId}/files/rename',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'body' => [
            'root' => [
                'type' => 'string',
                'description' => 'Directory the files currently live in.',
            ],
            'files' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'from' => [
                            'type' => 'string',
                        ],
                        'to' => [
                            'type' => 'string',
                        ],
                    ],
                    'required' => [
                        'from',
                        'to',
                    ],
                ],
            ],
        ],
        'required' => [
            'serverId',
            'root',
            'files',
        ],
    ],
    [
        'name' => 'panel_client_servers_files_copy',
        'description' => 'Copy a single file, creating a new file alongside it.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/files/copy',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'body' => [
            'location' => [
                'type' => 'string',
                'description' => 'Absolute path of the file to copy.',
            ],
        ],
        'required' => [
            'serverId',
            'location',
        ],
    ],
    [
        'name' => 'panel_client_servers_files_write',
        'description' => 'Write raw text to a file, creating it if needed. Overwrites the existing file contents. Body is sent as raw text, not JSON. Destructive: replaces the current file content.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/files/write',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'query' => [
            'file' => [
                'type' => 'string',
                'description' => 'Absolute path of the file to write.',
            ],
        ],
        'required' => [
            'serverId',
            'file',
            'content',
        ],
        'body_type' => 'text',
        'text_field' => 'content',
        'destructive' => true,
    ],
    [
        'name' => 'panel_client_servers_files_compress',
        'description' => 'Compress one or more files/folders into a new archive in the given directory.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/files/compress',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'body' => [
            'root' => [
                'type' => 'string',
                'description' => 'Directory the files live in, defaults to "/".',
            ],
            'files' => [
                'type' => 'array',
                'items' => [
                    'type' => 'string',
                ],
            ],
        ],
        'required' => [
            'serverId',
            'files',
        ],
    ],
    [
        'name' => 'panel_client_servers_files_decompress',
        'description' => 'Extract an archive in place. Destructive: may overwrite existing files with the same names.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/files/decompress',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'body' => [
            'root' => [
                'type' => 'string',
                'description' => 'Directory the archive lives in, defaults to "/".',
            ],
            'file' => [
                'type' => 'string',
                'description' => 'Archive file name to extract.',
            ],
        ],
        'required' => [
            'serverId',
            'file',
        ],
        'destructive' => true,
    ],
    [
        'name' => 'panel_client_servers_files_delete',
        'description' => 'Delete one or more files or folders. Destructive, cannot be undone.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/files/delete',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'body' => [
            'root' => [
                'type' => 'string',
                'description' => 'Directory the files live in.',
            ],
            'files' => [
                'type' => 'array',
                'items' => [
                    'type' => 'string',
                ],
            ],
        ],
        'required' => [
            'serverId',
            'root',
            'files',
        ],
        'destructive' => true,
    ],
    [
        'name' => 'panel_client_servers_files_create_folder',
        'description' => 'Create a new empty folder.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/files/create-folder',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'body' => [
            'root' => [
                'type' => 'string',
                'description' => 'Parent directory, defaults to "/".',
            ],
            'name' => [
                'type' => 'string',
            ],
        ],
        'required' => [
            'serverId',
            'name',
        ],
    ],
    [
        'name' => 'panel_client_servers_files_chmod',
        'description' => 'Change unix file permissions for one or more files.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/files/chmod',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'body' => [
            'root' => [
                'type' => 'string',
                'description' => 'Directory the files live in.',
            ],
            'files' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'file' => [
                            'type' => 'string',
                        ],
                        'mode' => [
                            'type' => 'string',
                            'description' => 'Octal mode, e.g. "0755".',
                        ],
                    ],
                    'required' => [
                        'file',
                        'mode',
                    ],
                ],
            ],
        ],
        'required' => [
            'serverId',
            'root',
            'files',
        ],
    ],
    [
        'name' => 'panel_client_servers_files_pull',
        'description' => 'Have wings download a file from a remote URL directly into the server directory.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/files/pull',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'body' => [
            'url' => [
                'type' => 'string',
                'format' => 'uri',
            ],
            'directory' => [
                'type' => [
                    'string',
                    'null',
                ],
                'description' => 'Destination directory, defaults to "/".',
            ],
            'filename' => [
                'type' => [
                    'string',
                    'null',
                ],
            ],
            'use_header' => [
                'type' => 'boolean',
                'description' => 'Use the filename from the Content-Disposition header if present.',
            ],
            'foreground' => [
                'type' => 'boolean',
                'description' => 'Wait for the download to finish before responding.',
            ],
        ],
        'required' => [
            'serverId',
            'url',
        ],
    ],
    [
        'name' => 'panel_client_servers_files_upload',
        'description' => 'Request a one-time signed upload URL for the server. The tool returns the signed Wings URL, not a place to send file bytes through this call; POST the file to that URL separately and it expires after a short time.',
        'api' => 'client',
        'method' => 'GET',
        'path' => '/servers/{serverId}/files/upload',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'required' => [
            'serverId',
        ],
    ],
    [
        'name' => 'panel_client_servers_schedules_list',
        'description' => "List a server's schedules and their tasks.",
        'api' => 'client',
        'method' => 'GET',
        'path' => '/servers/{serverId}/schedules',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'required' => [
            'serverId',
        ],
    ],
    [
        'name' => 'panel_client_servers_schedules_create',
        'description' => 'Create a new cron-style schedule for a server.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/schedules',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'body' => [
            'name' => [
                'type' => 'string',
            ],
            'is_active' => [
                'type' => 'boolean',
            ],
            'minute' => [
                'type' => 'string',
                'description' => 'Cron minute expression, e.g. "*/5".',
            ],
            'hour' => [
                'type' => 'string',
            ],
            'day_of_month' => [
                'type' => 'string',
            ],
            'day_of_week' => [
                'type' => 'string',
            ],
            'healthchecks_uuid' => [
                'type' => 'string',
                'description' => 'healthchecks.io check UUID to ping when the schedule finishes. Leave unset to disable.',
            ],
        ],
        'required' => [
            'serverId',
            'name',
            'minute',
            'hour',
            'day_of_month',
            'day_of_week',
        ],
    ],
    [
        'name' => 'panel_client_servers_schedules_view',
        'description' => 'View a single schedule and its tasks.',
        'api' => 'client',
        'method' => 'GET',
        'path' => '/servers/{serverId}/schedules/{scheduleId}',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
            'scheduleId' => [
                'type' => 'integer',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'serverId',
            'scheduleId',
        ],
    ],
    [
        'name' => 'panel_client_servers_schedules_update',
        'description' => "Update a schedule's cron expression and active state.",
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/schedules/{scheduleId}',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
            'scheduleId' => [
                'type' => 'integer',
                'maximum' => 9007199254740991,
            ],
        ],
        'body' => [
            'name' => [
                'type' => 'string',
            ],
            'is_active' => [
                'type' => 'boolean',
            ],
            'minute' => [
                'type' => 'string',
            ],
            'hour' => [
                'type' => 'string',
            ],
            'day_of_month' => [
                'type' => 'string',
            ],
            'day_of_week' => [
                'type' => 'string',
            ],
            'healthchecks_uuid' => [
                'type' => 'string',
                'description' => 'healthchecks.io check UUID to ping when the schedule finishes. Leave unset to disable.',
            ],
        ],
        'required' => [
            'serverId',
            'scheduleId',
            'name',
            'minute',
            'hour',
            'day_of_month',
            'day_of_week',
        ],
    ],
    [
        'name' => 'panel_client_servers_schedules_execute',
        'description' => 'Run a schedule immediately, outside of its normal cron trigger.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/schedules/{scheduleId}/execute',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
            'scheduleId' => [
                'type' => 'integer',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'serverId',
            'scheduleId',
        ],
    ],
    [
        'name' => 'panel_client_servers_schedules_delete',
        'description' => 'Delete a schedule and all its tasks. Destructive, cannot be undone.',
        'api' => 'client',
        'method' => 'DELETE',
        'path' => '/servers/{serverId}/schedules/{scheduleId}',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
            'scheduleId' => [
                'type' => 'integer',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'serverId',
            'scheduleId',
        ],
    ],
    [
        'name' => 'panel_client_servers_schedules_tasks_create',
        'description' => 'Add a task (command, power action or backup) to a schedule.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/schedules/{scheduleId}/tasks',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
            'scheduleId' => [
                'type' => 'integer',
                'maximum' => 9007199254740991,
            ],
        ],
        'body' => [
            'action' => [
                'type' => 'string',
                'enum' => [
                    'command',
                    'power',
                    'backup',
                ],
            ],
            'payload' => [
                'type' => [
                    'string',
                    'null',
                ],
                'description' => 'Command text or power signal; not used for the backup action.',
            ],
            'time_offset' => [
                'type' => 'integer',
                'description' => 'Seconds to wait after the previous task before running this one.',
                'minimum' => 0,
                'maximum' => 900,
            ],
            'sequence_id' => [
                'type' => 'integer',
                'description' => 'Position in the task list; appended to the end if omitted.',
                'minimum' => 1,
                'maximum' => 9007199254740991,
            ],
            'continue_on_failure' => [
                'type' => 'boolean',
            ],
        ],
        'required' => [
            'serverId',
            'scheduleId',
            'action',
            'time_offset',
        ],
    ],
    [
        'name' => 'panel_client_servers_schedules_tasks_update',
        'description' => 'Update an existing task on a schedule.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/schedules/{scheduleId}/tasks/{taskId}',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
            'scheduleId' => [
                'type' => 'integer',
                'maximum' => 9007199254740991,
            ],
            'taskId' => [
                'type' => 'integer',
                'maximum' => 9007199254740991,
            ],
        ],
        'body' => [
            'action' => [
                'type' => 'string',
                'enum' => [
                    'command',
                    'power',
                    'backup',
                ],
            ],
            'payload' => [
                'type' => [
                    'string',
                    'null',
                ],
            ],
            'time_offset' => [
                'type' => 'integer',
                'minimum' => 0,
                'maximum' => 900,
            ],
            'sequence_id' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 9007199254740991,
            ],
            'continue_on_failure' => [
                'type' => 'boolean',
            ],
        ],
        'required' => [
            'serverId',
            'scheduleId',
            'taskId',
            'action',
            'time_offset',
        ],
    ],
    [
        'name' => 'panel_client_servers_schedules_tasks_delete',
        'description' => 'Remove a task from a schedule. Destructive, cannot be undone.',
        'api' => 'client',
        'method' => 'DELETE',
        'path' => '/servers/{serverId}/schedules/{scheduleId}/tasks/{taskId}',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
            'scheduleId' => [
                'type' => 'integer',
                'maximum' => 9007199254740991,
            ],
            'taskId' => [
                'type' => 'integer',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'serverId',
            'scheduleId',
            'taskId',
        ],
    ],
    [
        'name' => 'panel_client_servers_network_allocations_list',
        'description' => "List a server's assigned network allocations.",
        'api' => 'client',
        'method' => 'GET',
        'path' => '/servers/{serverId}/network/allocations',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'required' => [
            'serverId',
        ],
    ],
    [
        'name' => 'panel_client_servers_network_allocations_create',
        'description' => 'Assign a new free allocation to the server, subject to the allocation limit set by an admin.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/network/allocations',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'required' => [
            'serverId',
        ],
    ],
    [
        'name' => 'panel_client_servers_network_allocations_update',
        'description' => "Update an allocation's notes.",
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/network/allocations/{allocationId}',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
            'allocationId' => [
                'type' => 'integer',
                'maximum' => 9007199254740991,
            ],
        ],
        'body' => [
            'notes' => [
                'type' => [
                    'string',
                    'null',
                ],
            ],
        ],
        'required' => [
            'serverId',
            'allocationId',
            'notes',
        ],
    ],
    [
        'name' => 'panel_client_servers_network_allocations_set_primary',
        'description' => "Mark an allocation as the server's primary allocation.",
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/network/allocations/{allocationId}/primary',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
            'allocationId' => [
                'type' => 'integer',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'serverId',
            'allocationId',
        ],
    ],
    [
        'name' => 'panel_client_servers_network_allocations_delete',
        'description' => 'Unassign an allocation from the server. Destructive, cannot be undone.',
        'api' => 'client',
        'method' => 'DELETE',
        'path' => '/servers/{serverId}/network/allocations/{allocationId}',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
            'allocationId' => [
                'type' => 'integer',
                'maximum' => 9007199254740991,
            ],
        ],
        'required' => [
            'serverId',
            'allocationId',
        ],
    ],
    [
        'name' => 'panel_client_servers_subusers_list',
        'description' => "List a server's subusers and their permissions.",
        'api' => 'client',
        'method' => 'GET',
        'path' => '/servers/{serverId}/users',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'required' => [
            'serverId',
        ],
    ],
    [
        'name' => 'panel_client_servers_subusers_create',
        'description' => 'Invite a user (by email) as a subuser of the server with the given permissions.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/users',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'body' => [
            'email' => [
                'type' => 'string',
                'format' => 'email',
            ],
            'permissions' => [
                'type' => 'array',
                'description' => 'Permission strings, see panel_client_permissions for the full list.',
                'items' => [
                    'type' => 'string',
                ],
            ],
        ],
        'required' => [
            'serverId',
            'email',
            'permissions',
        ],
    ],
    [
        'name' => 'panel_client_servers_subusers_view',
        'description' => 'View a single subuser and their permissions.',
        'api' => 'client',
        'method' => 'GET',
        'path' => '/servers/{serverId}/users/{userId}',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
            'userId' => [
                'type' => 'string',
                'description' => 'Subuser UUID, as returned by the subuser list.',
            ],
        ],
        'required' => [
            'serverId',
            'userId',
        ],
    ],
    [
        'name' => 'panel_client_servers_subusers_update',
        'description' => "Replace a subuser's permission set.",
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/users/{userId}',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
            'userId' => [
                'type' => 'string',
                'description' => 'Subuser UUID, as returned by the subuser list.',
            ],
        ],
        'body' => [
            'permissions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'string',
                ],
            ],
        ],
        'required' => [
            'serverId',
            'userId',
            'permissions',
        ],
    ],
    [
        'name' => 'panel_client_servers_subusers_delete',
        'description' => 'Remove a subuser from the server. Destructive, cannot be undone.',
        'api' => 'client',
        'method' => 'DELETE',
        'path' => '/servers/{serverId}/users/{userId}',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
            'userId' => [
                'type' => 'string',
                'description' => 'Subuser UUID, as returned by the subuser list.',
            ],
        ],
        'required' => [
            'serverId',
            'userId',
        ],
    ],
    [
        'name' => 'panel_client_servers_backups_list',
        'description' => "List a server's backups.",
        'api' => 'client',
        'method' => 'GET',
        'path' => '/servers/{serverId}/backups',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'query' => [
            'page' => [
                'type' => 'integer',
                'description' => 'Page number, starting at 1.',
                'minimum' => 1,
                'maximum' => 9007199254740991,
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Results per page (panel default is 50).',
                'minimum' => 1,
                'maximum' => 100,
            ],
        ],
        'required' => [
            'serverId',
        ],
    ],
    [
        'name' => 'panel_client_servers_backups_create',
        'description' => 'Start a new backup of the server, subject to the backup limit set by an admin.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/backups',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'body' => [
            'name' => [
                'type' => [
                    'string',
                    'null',
                ],
            ],
            'is_locked' => [
                'type' => [
                    'boolean',
                    'null',
                ],
                'description' => 'Locked backups cannot be deleted until unlocked.',
            ],
            'ignored' => [
                'type' => [
                    'string',
                    'null',
                ],
                'description' => 'Newline-separated gitignore-style patterns to exclude.',
            ],
        ],
        'required' => [
            'serverId',
        ],
    ],
    [
        'name' => 'panel_client_servers_backups_view',
        'description' => 'View a single backup and its status.',
        'api' => 'client',
        'method' => 'GET',
        'path' => '/servers/{serverId}/backups/{backupId}',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
            'backupId' => [
                'type' => 'string',
                'description' => 'Backup UUID.',
            ],
        ],
        'required' => [
            'serverId',
            'backupId',
        ],
    ],
    [
        'name' => 'panel_client_servers_backups_download',
        'description' => 'Request a one-time signed download URL for a backup. The tool returns the signed URL, not the archive bytes; fetch it separately and it expires after a short time.',
        'api' => 'client',
        'method' => 'GET',
        'path' => '/servers/{serverId}/backups/{backupId}/download',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
            'backupId' => [
                'type' => 'string',
                'description' => 'Backup UUID.',
            ],
        ],
        'required' => [
            'serverId',
            'backupId',
        ],
    ],
    [
        'name' => 'panel_client_servers_backups_toggle_lock',
        'description' => 'Toggle whether a backup is locked (locked backups cannot be deleted or pruned).',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/backups/{backupId}/lock',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
            'backupId' => [
                'type' => 'string',
                'description' => 'Backup UUID.',
            ],
        ],
        'required' => [
            'serverId',
            'backupId',
        ],
    ],
    [
        'name' => 'panel_client_servers_backups_restore',
        'description' => 'Restore a backup onto the server. Destructive: overwrites current server files with the contents of the backup.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/backups/{backupId}/restore',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
            'backupId' => [
                'type' => 'string',
                'description' => 'Backup UUID.',
            ],
        ],
        'body' => [
            'truncate' => [
                'type' => 'boolean',
                'description' => 'If true, delete files not present in the backup before restoring.',
            ],
        ],
        'required' => [
            'serverId',
            'backupId',
            'truncate',
        ],
        'destructive' => true,
    ],
    [
        'name' => 'panel_client_servers_backups_delete',
        'description' => 'Delete a backup. Fails if it is locked. Destructive, cannot be undone.',
        'api' => 'client',
        'method' => 'DELETE',
        'path' => '/servers/{serverId}/backups/{backupId}',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
            'backupId' => [
                'type' => 'string',
                'description' => 'Backup UUID.',
            ],
        ],
        'required' => [
            'serverId',
            'backupId',
        ],
    ],
    [
        'name' => 'panel_client_servers_startup_view',
        'description' => "List a server's startup variables and their current values.",
        'api' => 'client',
        'method' => 'GET',
        'path' => '/servers/{serverId}/startup',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'required' => [
            'serverId',
        ],
    ],
    [
        'name' => 'panel_client_servers_startup_update_variable',
        'description' => 'Update the value of a single startup (environment) variable, subject to its egg-defined validation rules.',
        'api' => 'client',
        'method' => 'PUT',
        'path' => '/servers/{serverId}/startup/variable',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'body' => [
            'key' => [
                'type' => 'string',
                'description' => 'Variable environment name, e.g. "SERVER_JARFILE".',
            ],
            'value' => [
                'type' => 'string',
            ],
        ],
        'required' => [
            'serverId',
            'key',
            'value',
        ],
    ],
    [
        'name' => 'panel_client_servers_settings_rename',
        'description' => 'Rename a server and optionally update its description.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/settings/rename',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'body' => [
            'name' => [
                'type' => 'string',
            ],
            'description' => [
                'type' => [
                    'string',
                    'null',
                ],
            ],
        ],
        'required' => [
            'serverId',
            'name',
        ],
    ],
    [
        'name' => 'panel_client_servers_settings_reinstall',
        'description' => 'Reinstall the server by re-running its egg install script. Destructive: may remove or overwrite server files depending on the egg.',
        'api' => 'client',
        'method' => 'POST',
        'path' => '/servers/{serverId}/settings/reinstall',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'required' => [
            'serverId',
        ],
        'destructive' => true,
    ],
    [
        'name' => 'panel_client_servers_settings_docker_image',
        'description' => "Switch the server's docker image to one of the images allowed by its egg.",
        'api' => 'client',
        'method' => 'PUT',
        'path' => '/servers/{serverId}/settings/docker-image',
        'path_params' => [
            'serverId' => [
                'type' => 'string',
                'description' => 'Server identifier: the full UUID is recommended, but the 8-character uuidShort is also accepted.',
            ],
        ],
        'body' => [
            'docker_image' => [
                'type' => 'string',
            ],
        ],
        'required' => [
            'serverId',
            'docker_image',
        ],
    ],
];
