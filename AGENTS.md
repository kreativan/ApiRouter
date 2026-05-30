# ApiRouter

Lightweight modular API router for ProcessWire. Intercepts `/api/{route}/{endpoint}/` URLs and dispatches them to PHP files inside module `api/` directories. CORS and API key authentication are delegated to the **Auth** module.

## API variable

None. The module hooks `/api/.*` before ProcessWire routing and handles the request directly.

## URL structure

```
/api/{route}/{endpoint}/
```

| Segment    | Derived from                                               |
|------------|-----------------------------------------------------------|
| `route`    | Module class name converted to kebab-case (`ProjectTracking` → `project-tracking`) |
| `endpoint` | File name under `ModuleName/api/` (default: `index`)      |

Examples:
```
GET  /api/my-module/          → site/modules/MyModule/api/index.php
POST /api/my-module/create/   → site/modules/MyModule/api/create.php
GET  /api/my-module/reports/monthly/  → site/modules/MyModule/api/reports/monthly.php
```

## Adding a new API route

Create an `api/` subdirectory inside any module. The route name is auto-derived — no registration required.

```php
// site/modules/MyModule/api/index.php
<?php namespace ProcessWire;
// $input, $apiRouter, $apiModule, $apiClient are already in scope.
return ['items' => $pages->find('template=product')->explode('title')];
```

Returning an array auto-encodes it as JSON with `200 OK`. To control the status code use `$apiRouter->json($data, $statusCode)` or echo manually.

## Site-level overrides

A file at `site/api/{route}/{endpoint}.php` takes precedence over the module file. Use this to override an endpoint without editing the module.

## Variables available in endpoint files

| Variable     | Type         | Description                             |
|--------------|--------------|-----------------------------------------|
| `$input`     | `WireInput`  | ProcessWire input (GET/POST normalized) |
| `$apiRouter` | `ApiRouter`  | This module instance                    |
| `$apiModule` | `Module`     | The resolved module for this route      |
| `$apiClient` | `string|null`| API key client name if authenticated    |

JSON request bodies (`Content-Type: application/json`) are parsed and injected into `$input->post` and `$_POST` automatically.

## Config settings

| Setting        | Key     | Default | Description                                         |
|----------------|---------|---------|-----------------------------------------------------|
| Require Login  | `login` | `0`     | Require a logged-in PW session for all routes       |

CORS settings and API key requirement are in the **Auth** module.

## Request lifecycle

1. URL hook intercepts `(/api/.*)`.
2. `Auth::sendCorsHeaders()` runs — OPTIONS preflight returns `204` immediately.
3. If `Auth::requiresApiKey()` → validate `Authorization: Bearer <token>`.
4. If `login` config is on → check `$user->isLoggedin()`.
5. Endpoint file resolved (site override → module file).
6. File `include`d; array return is JSON-encoded automatically.

## Route registry cache

Auto-built by scanning all `site/modules/*/api/` directories. Cached indefinitely under the key `api-router-registry`. Cleared automatically on **Modules → Refresh** or manually:

```php
wire('modules')->get('ApiRouter')->clearRouteCache();
```

## Helper methods

```php
$apiRouter->json(array $data, int $status = 200);
$apiRouter->notFound(string $message = 'Not found');
$apiRouter->unauthorized();
$apiRouter->serverError(string $message);
```

## Dependencies

Requires **Auth** module. Auth must be installed before ApiRouter.

## Common patterns

```php
// Return paginated results
return [
    'items' => $pages->find('template=product, limit=20')->each(fn($p) => [
        'id'    => $p->id,
        'title' => $p->title,
        'url'   => $p->httpUrl,
    ]),
    'total' => $pages->count('template=product'),
];

// Validate method
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    return $apiRouter->notFound('POST required');
}

// Read POST body
$name = $sanitizer->text($input->post('name'));
```

## Notes

- Route names are case-insensitive kebab-case — `MyModule` and `my-module` are the same route.
- Nested endpoints map to nested directory paths: `api/reports/monthly.php` → `/api/my-module/reports/monthly/`.
- Never put sensitive logic in the endpoint file based on unvalidated `$_SERVER['REQUEST_METHOD']`; always check it explicitly.
