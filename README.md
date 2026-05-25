# ApiRouter

Lightweight modular API router for ProcessWire. Hooks `/api/{route}/{endpoint}/` and resolves requests to PHP files inside any module that has an `api/` directory.

---

## How It Works

### URL Structure

```
/api/{route}/{endpoint}/
```

| Segment    | Description                                          |
|------------|------------------------------------------------------|
| `route`    | Auto-derived from the module class name (CamelCase → kebab-case). `ProjectTracking` → `project-tracking`. |
| `endpoint` | Maps to a `.php` file inside the module's `api/` folder. Defaults to `index` if omitted. |

**Examples:**

```
/api/project-tracking/          → /site/modules/ProjectTracking/api/index.php
/api/project-tracking/new/      → /site/modules/ProjectTracking/api/new.php
/api/project-tracking/archive/  → /site/modules/ProjectTracking/api/archive.php
```

### Request Flow

1. ProcessWire hook intercepts any request matching `(/api/.*)`.
2. The URL prefix is stripped, leaving `route/endpoint`.
3. ApiRouter builds a **route registry** by scanning the filesystem for modules that contain an `api/` subdirectory. Route names are auto-derived from module class names (`CamelCase` → `kebab-case`). The registry is **cached indefinitely** and invalidated automatically on `Modules::refresh`.
4. The matching module is resolved, and checks run in this order:
   - **CORS headers** are sent via the **Auth** module. `OPTIONS` preflight requests return `204 No Content` immediately at this point, before any auth check, so browser preflight passes even when auth is required.
   - **API key** — Bearer token validated against `$config->apiKeys` (controlled by Auth module config).
   - **Login** — ProcessWire session check (if enabled in ApiRouter config).
5. The endpoint file is resolved: the router first checks `site/api/{route}/{endpoint}.php` for a site-level override, then falls back to `site/modules/{Module}/api/{endpoint}.php`.
6. If the file returns an array, it is automatically JSON-encoded and sent. If it echoes output directly, that is sent as-is.

### Route Registry Cache

The registry is cached under the key `api-router-registry` with no expiry and is cleared automatically whenever you run **Modules → Refresh** in the admin. To clear it manually:

```php
wire('modules')->get('ApiRouter')->clearRouteCache();
```

---

## Registering a Module as an API

Create an `api/` subdirectory inside your module. The route name is auto-derived from the class name — `ProjectTracking` becomes `project-tracking`.

```php
<?php namespace ProcessWire;

class ProjectTracking extends WireData implements Module {

    public static function getModuleInfo() {
        return [
            'title' => 'Project Tracking',
            'version' => 1,
            'autoload' => true,
        ];
    }
}
```

Auth, CORS, and login requirements are configured **globally** in the ApiRouter module settings (admin → Modules → ApiRouter). There is no per-module config.

---

## Extending the Route Registry

`getRouteRegistry` is a hookable method (`___getRouteRegistry`). You can hook into it to inject additional routes — for the site-level `site/api/` folder, or alias routes for backwards compatibility.

### Hook signature

The hook receives the built registry array via `$event->return` and must set `$event->return` to the modified registry. Each entry maps a **kebab-case route name** to a **module class name**:

```php
[
    'route-name' => 'ModuleClassName',
]
```

### Example — add a custom route in `site/init.php` or a custom module

```php
wire()->addHookAfter('ApiRouter::getRouteRegistry', function(HookEvent $event) {
    $registry = $event->return;

    // Add a custom route pointing to an existing module
    $registry['contacts'] = 'Contacts';

    // Add an alias so /api/projects/ still works alongside /api/project-tracking/
    $registry['projects'] = 'ProjectTracking';

    $event->return = $registry;
});
```

### Example — add routes inside a module's `init()` or `ready()`

```php
public function ready() {
    $this->addHookAfter('ApiRouter::getRouteRegistry', function(HookEvent $event) {
        $registry = $event->return;
        $registry['my-route'] = 'MyModule';
        $event->return = $registry;
    });
}
```

> **Note:** The result of `getRouteRegistry` is cached. Your hook will only run when the cache is cold (after a `Modules::refresh` or `clearRouteCache()` call). Do not add hooks that depend on runtime state — they run once and the result is stored.

---

## Endpoint File Structure

Place endpoint files inside an `api/` subfolder of your module:

```
/site/modules/ProjectTracking/
    ProjectTracking.module.php
    api/
        index.php       → /api/project-tracking/
        new.php         → /api/project-tracking/new/
        archive.php     → /api/project-tracking/archive/
```

---

## Site-Level Endpoint Overrides

You can override any module endpoint by creating a file at the same relative path under `site/api/`. The router checks the site-level path **first**; the module file is only used if no override exists.

**Override path structure:**

```
site/api/{route}/{endpoint}.php
```

**Example:** Override `ProjectTracking`'s `new.php` endpoint:

```
site/api/project-tracking/new.php   ← used instead of:
site/modules/ProjectTracking/api/new.php
```

This lets you customise individual endpoints without touching module files, which is especially useful when modules are managed as dependencies.

---

## Writing an Endpoint

Each endpoint file is `include()`d and has access to these pre-injected variables:

| Variable     | Type     | Description                                        |
|--------------|----------|----------------------------------------------------| 
| `$input`     | WireInput | ProcessWire input object. Use `$input->post`, `$input->get`. |
| `$apiRouter` | ApiRouter | The router instance. Use `$apiRouter->json()` for custom responses. |
| `$apiModule` | Module   | The module instance that owns this endpoint.       |
| `$apiClient` | string\|null | The name of the authenticated API client (e.g. `'frontend'`), or `null` if auth is disabled. |

---

## Request Data

ApiRouter normalizes the incoming request so endpoints can read input the same way regardless of whether the client sends `application/json` or `multipart/form-data` / `application/x-www-form-urlencoded`.

When `Content-Type: application/json` is detected the JSON body is parsed and each key is injected into both `$_POST` and ProcessWire's `$input->post`. Standard form submissions are already handled natively by PHP, so no extra work is done for those.

| How the client sends data | How to read it in an endpoint |
|---------------------------|-------------------------------|
| JSON body | `$input->post->name`, `$_POST['name']` |
| Form-data / URL-encoded | `$input->post->name`, `$_POST['name']` |
| Query string | `$input->get->name`, `$_GET['name']` |

```php
<?php namespace ProcessWire;

// Works for JSON body, form-data, and URL-encoded — no special handling needed
$name  = $input->post->name;      // via ProcessWire (sanitized)
$email = $_POST['email'] ?? null;  // via superglobal
$page  = $input->get->int('page'); // query-string param, cast to int

return ['name' => $name, 'email' => $email];
```

> **Note:** `$input->post` applies ProcessWire's output formatting. For raw values use `$input->post->name` or `$_POST['name']`. For typed values use helpers like `$input->post->int('qty')`, `$input->post->email('email')`, etc.

---

Return an array to send a JSON response automatically:

```php
<?php namespace ProcessWire;

return [
    'success' => true,
    'client'  => $apiClient,
    'method'  => wire('input')->requestMethod(),
    'data'    => [
        'message' => 'Hello from Projects API'
    ]
];
```

Or echo output manually and return nothing:

```php
<?php namespace ProcessWire;

http_response_code(201);
echo json_encode(['success' => true, 'id' => 42]);
```

#### Documenting an Endpoint for OpenAPI

To include an endpoint in the generated OpenAPI spec, add a `_meta` key to the returned array. The `_meta` block is read only by `ApiRouterOpenApi` and is stripped from the actual API response — it has no effect on what the client receives.

```php
<?php namespace ProcessWire;

return [
    '_meta' => [
        'method'  => 'post',
        'summary' => 'Create a new project',
        'tags'    => ['Projects'],
        'auth'    => true,

        'requestBody' => [
            'title'       => 'string',
            'description' => 'text',
            'budget'      => 'float',
            'active'      => 'bool',
        ],

        'responses' => [
            201 => [
                'id'      => 'integer',
                'success' => 'bool',
            ],
            422 => [
                'success' => 'bool',
                'message' => 'string',
            ],
        ],
    ],

    // actual response data
    'success' => true,
    'project' => [
        'id'    => 123,
        'title' => 'New project',
    ],
];
```

Endpoints without a `_meta` key are silently excluded from the spec. See the [OpenAPI — ApiRouterOpenApi](#openapi--apirouteropenapi) section for the full `_meta` reference.

---

## Authentication

> **Auth is delegated to the [Auth](../Auth/) module.** API key validation and CORS are configured there — ApiRouter only controls the **Require Login** toggle.

### API Key Auth

When **Require API Key** is enabled in **Admin → Modules → Auth**, every request must include a valid Bearer token:

```
Authorization: Bearer YOUR_API_KEY
```

Keys are defined in `site/config.php`:

```php
$config->apiKeys = [
    'frontend' => 'abc123secrettoken',
    'mobile'   => 'xyz456secrettoken',
    'server'   => 'qrs789secrettoken',
];
```

On success, the resolved client name is available as `$apiClient` inside endpoint files.

> **Note:** If `$config->apiKeys` is empty or undefined, all authenticated requests will be rejected even when **Require API Key** is enabled.

### Making Authenticated Requests (JavaScript)

**Bearer token only** — suitable for server-to-server or SPA requests:

```js
const response = await fetch('/api/project-tracking/new/', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer xyz456secrettoken',
  },
  body: JSON.stringify({ title: 'New project' }),
});

const data = await response.json();
```

**Login session only** — the browser sends the ProcessWire session cookie automatically; no token needed:

```js
const response = await fetch('/api/project-tracking/new/', {
  method: 'POST',
  credentials: 'same-origin', // include session cookie
  headers: {
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({ title: 'New project' }),
});

const data = await response.json();
```

**Both token and login session** — when **Require API Key** and **Require Login** are both enabled:

```js
const response = await fetch('/api/project-tracking/new/', {
  method: 'POST',
  credentials: 'same-origin',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer xyz456secrettoken',
  },
  body: JSON.stringify({ title: 'New project' }),
});

const data = await response.json();
```

> **Cross-origin requests:** If your frontend is on a different domain, set `credentials: 'include'` instead of `'same-origin'`, enable **Allow Credentials** in the CORS settings, and add your frontend origin to **Allowed Origins**.

### Enabling / Disabling Auth

Toggle **Require API Key** in **Admin → Modules → Auth**. This applies to all routes served by ApiRouter and JsonApi. To expose a fully public API, leave the checkbox unchecked.

---

## Login-Gated Routes

Enable **Require Login** in **Admin → Modules → ApiRouter** to restrict all routes to **logged-in ProcessWire users only**. Any request made by a guest session will receive a `401 Unauthorized` response.

The check uses ProcessWire's built-in `wire('user')->isLoggedin()`. This is independent of the **Require API Key** setting — you can combine both, use either one alone, or leave both off for fully public access:

| Require API Key (Auth) | Require Login (ApiRouter) | Who can access |
|------------------------|---------------------------|----------------|
| off | off | Everyone |
| on  | off | Valid Bearer token holders |
| off | on  | Logged-in PW users (no token needed) |
| on  | on  | Logged-in PW users **with** a valid Bearer token |

---

## CORS

CORS is configured globally in **Admin → Modules → Auth** and applies to all routes (ApiRouter, JsonApi, and the Auth endpoints themselves).

CORS headers are sent **before** auth and login checks run. `OPTIONS` preflight requests return `204 No Content` immediately after CORS headers are sent, so browsers complete the preflight handshake even when auth is required.

See the [Auth module README](../Auth/README.md) for the full list of CORS settings.

---

## OpenAPI — ApiRouterOpenApi

`ApiRouterOpenApi` is a companion module that generates an **OpenAPI 3.1** specification by scanning all registered ApiRouter modules and reading metadata declared inside endpoint files.

### Setup

Place `ApiRouterOpenApi.module.php` alongside `ApiRouter.module.php` and install it in ProcessWire. It does not autoload — instantiate it on demand.

### Generating the Spec

```php
$openApi = wire('modules')->get('ApiRouterOpenApi');

// Get spec as a PHP array
$spec = $openApi->generate();

// Output as JSON (sets Content-Type header)
$openApi->renderJson();
```

A common pattern is to expose the spec via a dedicated endpoint:

```php
// site/templates/api-docs.php
$openApi = wire('modules')->get('ApiRouterOpenApi');
$openApi->renderJson();
```

### `_meta` Reference

| Key | Type | Description |
|-----|------|-------------|
| `method` | string | HTTP method (`get`, `post`, `put`, `delete`, …). Defaults to `get`. |
| `summary` | string | Short description shown in API explorer tools. |
| `tags` | string[] | Groups endpoints in the spec UI (e.g. Swagger UI). |
| `auth` | bool | When `true`, adds `bearerAuth` security requirement to the operation. |
| `requestBody` | array | Field map of `name => type` describing the JSON request body. |
| `responses` | array | Map of HTTP status code to field map, e.g. `[200 => ['id' => 'integer']]`. |

### Supported Field Types

| Shorthand | OpenAPI type |
|-----------|--------------|
| `string`, `text` | `string` |
| `int`, `integer` | `integer` |
| `bool`, `boolean` | `boolean` |
| `float`, `number` | `number` |
| `array` | `array` |
| `object` | `object` |

Nested objects are supported — use an array as the value:

```php
'requestBody' => [
    'title'  => 'string',
    'author' => [
        'name'  => 'string',
        'email' => 'string',
    ],
],
```

### Bearer Auth

The generated spec includes a `bearerAuth` security scheme in `components.securitySchemes`. Any endpoint with `auth => true` in `_meta` will reference it automatically, so tools like Swagger UI display an **Authorize** button.

---

## Response Format

All error responses follow a consistent JSON structure:

| Status | Condition                              | Body                                          |
|--------|----------------------------------------|-----------------------------------------------|
| 401    | Missing or invalid Bearer token        | `{"success": false, "message": "Unauthorized"}` |
| 404    | Unknown route, missing module, or no endpoint file | `{"success": false, "message": "..."}` |
| 500    | Uncaught exception inside endpoint file | `{"success": false, "message": "..."}` |

All responses set `Content-Type: application/json; charset=utf-8`.

---

## Future Improvements

### Middleware

```php
return [
    'middleware' => ['auth', 'throttle']
];
```

### Method Restrictions

```php
return [
    'methods' => ['POST', 'PUT']
];
```

### Versioning

```
/api/v1/projects/
```

### Controller Support

```
/api/projects/create/
→ ProjectApi::create()
```

