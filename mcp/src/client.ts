// Thin HTTP layer over the panel's Application and Client REST APIs. Nothing in this
// file is allowed to hand a raw request/response/error object back to a caller: the
// only things that ever leave here are a status code and the panel's own JSON body.

export type ApiName = 'application' | 'client';
export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export interface RequestOptions {
    api: ApiName;
    method: HttpMethod;
    path: string;
    pathParams?: Record<string, string | number>;
    query?: Record<string, unknown>;
    jsonBody?: unknown;
    textBody?: string;
    responseType?: 'text';
    /**
     * Overrides the env-configured key for this one call. Used by the HTTP transport to
     * forward the calling session's own bearer token instead of a shared static key.
     */
    token?: string;
}

export interface ApiSuccess {
    ok: true;
    status: number;
    body: unknown;
}

export interface ApiFailure {
    ok: false;
    status: number;
    errors: unknown;
}

export type ApiResult = ApiSuccess | ApiFailure;

const API_BASE_PATHS: Record<ApiName, string> = {
    application: '/api/application',
    client: '/api/client',
};

function resolveKey(api: ApiName): string | undefined {
    return api === 'application' ? process.env.PANEL_APPLICATION_KEY : process.env.PANEL_CLIENT_KEY;
}

function buildUrl(options: RequestOptions): string {
    const base = (process.env.PANEL_URL ?? '').replace(/\/+$/, '');

    let path = options.path;
    for (const [key, value] of Object.entries(options.pathParams ?? {})) {
        path = path.replace(`{${key}}`, encodeURIComponent(String(value)));
    }

    const url = new URL(base + API_BASE_PATHS[options.api] + path);
    for (const [key, value] of Object.entries(options.query ?? {})) {
        if (value === undefined || value === null || value === '') {
            continue;
        }

        if (key === 'filter' && typeof value === 'object') {
            for (const [filterKey, filterValue] of Object.entries(value as Record<string, unknown>)) {
                if (filterValue !== undefined && filterValue !== null) {
                    url.searchParams.set(`filter[${filterKey}]`, String(filterValue));
                }
            }
            continue;
        }

        if (Array.isArray(value)) {
            for (const item of value) {
                url.searchParams.append(`${key}[]`, String(item));
            }
            continue;
        }

        url.searchParams.set(key, String(value));
    }

    return url.toString();
}

// Only ever surface the error message string. Axios-style errors (and some fetch
// polyfills) attach the full request - including the Authorization header - to other
// properties of the error object, so nothing but `.message` may be read here.
function networkErrorMessage(error: unknown): string {
    return error instanceof Error ? error.message : 'Unknown network error while contacting the panel.';
}

export async function request(options: RequestOptions): Promise<ApiResult> {
    const key = options.token ?? resolveKey(options.api);
    if (!key) {
        return {
            ok: false,
            status: 0,
            errors: [{ detail: `No ${options.api} API key is configured on this MCP server.` }],
        };
    }

    const headers: Record<string, string> = {
        Authorization: `Bearer ${key}`,
        Accept: 'application/json',
    };

    let body: string | undefined;
    if (options.textBody !== undefined) {
        headers['Content-Type'] = 'text/plain';
        body = options.textBody;
    } else if (options.jsonBody !== undefined) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(options.jsonBody);
    }

    let response: Response;
    try {
        response = await fetch(buildUrl(options), { method: options.method, headers, body });
    } catch (error) {
        return { ok: false, status: 0, errors: [{ detail: networkErrorMessage(error) }] };
    }

    if (response.status === 204) {
        return { ok: true, status: response.status, body: null };
    }

    if (!response.ok) {
        let errors: unknown;
        try {
            const parsed: unknown = await response.json();
            const maybeErrors = (parsed as { errors?: unknown } | null)?.errors;
            errors = Array.isArray(maybeErrors) ? maybeErrors : parsed;
        } catch {
            errors = [{ detail: 'The panel returned a non-JSON error response.' }];
        }
        return { ok: false, status: response.status, errors };
    }

    if (options.responseType === 'text') {
        return { ok: true, status: response.status, body: await response.text() };
    }

    try {
        return { ok: true, status: response.status, body: await response.json() };
    } catch {
        return { ok: true, status: response.status, body: null };
    }
}
