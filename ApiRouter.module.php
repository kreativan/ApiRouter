<?php

namespace ProcessWire;

/**
 * ApiRouter
 *
 * Lightweight modular API router for ProcessWire.
 * 
 * @author Ivan Milincic <ivan@milincic.com>
 *
 * Example:
 * /json-api/project-tracking/new/
 *
 * Resolves to:
 * /site/modules/ProjectTracking/api/new.php
 *
 * Supports:
 * - Module based routing
 * - API key authentication
 * - JSON responses
 * - Route registry caching
 * - Nested endpoints
 *
 */
class ApiRouter extends WireData implements Module, ConfigurableModule {

  protected ?string $currentApiClient = null;
  const API_PREFIX = 'api';

  /**
   * Module info
   */
  public static function getModuleInfo() {
    return [
      'title'        => 'Api Router',
      'version'      => 1,
      'icon'         => 'code-fork',
      'summary'      => 'Lightweight module based API router',
      'author'       => 'Ivan Milincic',
      'autoload'     => true,
      'singular'     => true,
      'configurable' => true,
    ];
  }

  /**
   * Default module config data
   */
  public static function getDefaultData(): array {
    return [
      'auth'            => 0,
      'login'           => 0,
      'cors'            => 0,
      'corsOrigins'     => '*',
      'corsMethods'     => 'GET, POST, OPTIONS',
      'corsHeaders'     => 'Content-Type, Authorization',
      'corsCredentials' => 0,
      'corsMaxAge'      => '',
    ];
  }

  /**
   * Module config inputfields
   */
  public function getModuleConfigInputfields(array $data): InputfieldWrapper {
    $data       = array_merge(self::getDefaultData(), $data);
    $modules    = $this->wire('modules');
    $inputfields = $this->wire(new InputfieldWrapper());

    /** Auth */
    $f = $modules->get('InputfieldCheckbox');
    $f->attr('name', 'auth');
    $f->attr('value', 1);
    $f->label       = __('Require API Key (Global)');
    $f->description = __('Require a Bearer token from $config->apiKeys for all routes.');
    $f->notes       = __('API keys are defined in site/config.php as $config->apiKeys = ["client" => "secret"]');
    if ($data['auth']) $f->attr('checked', 'checked');
    $inputfields->append($f);

    /** Login */
    $f = $modules->get('InputfieldCheckbox');
    $f->attr('name', 'login');
    $f->attr('value', 1);
    $f->label       = __('Require Login (Global)');
    $f->description = __('Require a logged-in ProcessWire user for all routes.');
    if ($data['login']) $f->attr('checked', 'checked');
    $inputfields->append($f);

    /** CORS fieldset */
    $fieldset = $modules->get('InputfieldFieldset');
    $fieldset->label = __('CORS Settings (Global)');
    $fieldset->description = __('These settings apply to all routes.');

    $f = $modules->get('InputfieldCheckbox');
    $f->attr('name', 'cors');
    $f->attr('value', 1);
    $f->label = __('Enable CORS');
    if ($data['cors']) $f->attr('checked', 'checked');
    $fieldset->append($f);

    $f = $modules->get('InputfieldText');
    $f->attr('name', 'corsOrigins');
    $f->attr('value', $data['corsOrigins']);
    $f->label       = __('Allowed Origins');
    $f->description = __('Comma-separated list of origins, or * for all. E.g. https://example.com, https://app.example.com');
    $fieldset->append($f);

    $f = $modules->get('InputfieldText');
    $f->attr('name', 'corsMethods');
    $f->attr('value', $data['corsMethods']);
    $f->label = __('Allowed Methods');
    $f->description = __('Comma-separated list. OPTIONS is always included.');
    $fieldset->append($f);

    $f = $modules->get('InputfieldText');
    $f->attr('name', 'corsHeaders');
    $f->attr('value', $data['corsHeaders']);
    $f->label = __('Allowed Headers');
    $f->description = __('Comma-separated list of allowed request headers.');
    $fieldset->append($f);

    $f = $modules->get('InputfieldCheckbox');
    $f->attr('name', 'corsCredentials');
    $f->attr('value', 1);
    $f->label = __('Allow Credentials');
    $f->description = __('Send Access-Control-Allow-Credentials: true. Cannot be used with wildcard origin.');
    if ($data['corsCredentials']) $f->attr('checked', 'checked');
    $fieldset->append($f);

    $f = $modules->get('InputfieldInteger');
    $f->attr('name', 'corsMaxAge');
    $f->attr('value', $data['corsMaxAge']);
    $f->label       = __('Preflight Max Age (seconds)');
    $f->description = __('How long browsers may cache the preflight response. Leave blank to omit.');
    $f->required    = false;
    $fieldset->append($f);

    $inputfields->append($fieldset);

    return $inputfields;
  }

  /**
   * Register API route hook
   */
  public function init() {
    /** Main URL Hook */
    $apiPrefix = self::API_PREFIX;
    $this->addHookBefore("(/$apiPrefix/.*)", $this, 'handleApi');

    /** Clear route cache on Modules refresh */
    $this->addHookAfter('Modules::refresh', function () {
      wire('cache')->delete('api-router-registry');
    });
  }

  /**
   * Main API request handler
   */
  public function handleApi(HookEvent $event) {
    $event->replace = true; // prevent ProcessWire from falling through to 404

    $modules = wire('modules');
    $config = wire('config');

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');

    /**
     * arguments(1) = capture group from '(/json-api/.*)'
     * e.g. "/json-api/project-tracking/test"
     */
    $rawPath = $event->arguments(1);

    if (!$rawPath) {
      return $this->notFound('Missing API route');
    }

    /**
     * Strip the /json-api/ prefix, leaving "route/endpoint"
     */
    $apiPrefix = self::API_PREFIX;
    $path = trim(preg_replace("#^/$apiPrefix/#", '', $rawPath), '/');

    if (!$path) {
      return $this->notFound('Missing API route');
    }

    /**
     * First segment = route, remainder = endpoint
     * e.g. "project-tracking/test" -> route="project-tracking", endpoint="test"
     */
    $segments = explode('/', $path);
    $route    = array_shift($segments);
    $endpoint = implode('/', $segments) ?: 'index';

    /**
     * Sanitize route
     */
    $route = strtolower(
      preg_replace('/[^a-z0-9_-]/i', '', $route)
    );

    /**
     * Sanitize endpoint (allow slashes for nested paths)
     */
    $endpoint = preg_replace(
      '/[^a-zA-Z0-9_\\/-]/',
      '',
      $endpoint
    );

    /**
     * Get route registry
     */
    $registry = $this->getRouteRegistry();

    if (!isset($registry[$route])) {
      return $this->notFound('Unknown API route');
    }

    /**
     * Resolve module
     */
    $moduleName = $registry[$route];

    $module = $modules->get($moduleName);

    if (!$module) {
      return $this->notFound('Module not found');
    }

    /**
     * Build config from global ApiRouter module settings
     */
    $cors = false;
    if ($this->cors) {
      $rawOrigins = $this->corsOrigins ?: '*';
      $origins    = array_filter(array_map('trim', explode(',', $rawOrigins)));
      $cors       = ['origin' => $origins ?: ['*']];
      if ($this->corsMethods)     $cors['methods']     = array_map('trim', explode(',', $this->corsMethods));
      if ($this->corsHeaders)     $cors['headers']     = array_map('trim', explode(',', $this->corsHeaders));
      if ($this->corsCredentials) $cors['credentials'] = true;
      if ($this->corsMaxAge)      $cors['maxAge']      = (int) $this->corsMaxAge;
    }

    $effectiveConfig = [
      'auth'  => (bool) $this->auth,
      'login' => (bool) $this->login,
      'cors'  => $cors,
    ];

    /**
     * CORS headers — must run before auth so that OPTIONS preflight
     * requests (which carry no credentials) are not rejected.
     */
    if (!empty($effectiveConfig['cors'])) {

      $this->sendCorsHeaders($effectiveConfig['cors']);

      /**
       * Preflight request — respond immediately, no auth required.
       */
      if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        return;
      }
    }

    /**
     * Auth check
     */
    if (!empty($effectiveConfig['auth'])) {

      if (!$this->validateApiKey()) {

        return $this->unauthorized();
      }
    }

    /**
     * Login check
     */
    if (!empty($effectiveConfig['login'])) {

      if (!wire('user')->isLoggedin()) {

        return $this->unauthorized();
      }
    }

    /**
     * Resolve endpoint file.
     *
     * Override lookup order:
     * 1. site/api/{route}/{endpoint}.php  (site-level override)
     * 2. site/modules/{Module}/api/{endpoint}.php  (module default)
     */
    $siteApiOverride =
      $config->paths->site .
      'api/' .
      $route . '/' .
      $endpoint . '.php';

    $moduleApiPath =
      $config->paths->siteModules .
      $moduleName .
      '/api/';

    $moduleFile = $moduleApiPath . $endpoint . '.php';

    if (is_file($siteApiOverride)) {
      $file = $siteApiOverride;
    } elseif (is_file($moduleFile)) {
      $file = $moduleFile;
    } else {
      return $this->notFound('Endpoint not found');
    }

    /**
     * Normalize JSON body into $_POST / $input->post so endpoints
     * can use either content-type transparently.
     */
    $this->normalizeRequestData();

    /**
     * Expose useful variables to endpoint
     */
    $input     = $this->wire('input');
    $apiRouter = $this;
    $apiModule = $module;
    $apiClient = $this->currentApiClient;

    try {

      /**
       * Endpoint can:
       * - return array
       * - echo manually
       * - return string
       */
      $result = include($file);

      /**
       * Auto JSON encode arrays
       */
      if (is_array($result)) {
        return $this->json($result, 200);
      }

      if ($result === null) {
        return true;
      }

      return $result;
    } catch (\Throwable $e) {

      return $this->serverError($e->getMessage());
    }
  }

  /**
   * Normalize request data.
   *
   * When the request body is JSON (Content-Type: application/json), parse it
   * and inject each key into both the $_POST superglobal and ProcessWire's
   * $input->post so that endpoints can read data with $input->post, $_POST,
   * $input->get, or $_GET regardless of how the client sent the payload.
   */
  protected function normalizeRequestData(): void {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (strpos($contentType, 'application/json') === false) {
      return;
    }

    $body = file_get_contents('php://input');

    if (!$body) {
      return;
    }

    $json = json_decode($body, true);

    if (!is_array($json)) {
      return;
    }

    $post = $this->wire('input')->post;

    foreach ($json as $key => $value) {
      $_POST[$key] = $value;
      $post->set($key, $value);
    }
  }

  /**
   * Build route registry
   */
  protected function ___getRouteRegistry(): array {
    $cache    = wire('cache');
    $cacheKey = 'api-router-registry';

    $registry = $cache->get($cacheKey);

    if (is_array($registry)) {
      return $registry;
    }

    $registry   = [];
    $modulesDir = wire('config')->paths->siteModules;

    foreach (glob($modulesDir . '*/api/', GLOB_ONLYDIR) as $apiDir) {
      $moduleName = basename(dirname($apiDir));
      $route = strtolower(
        preg_replace('/([a-z])([A-Z])/', '$1-$2', $moduleName)
      );
      $registry[$route] = $moduleName;
    }

    // Cache indefinitely until the module registry is refreshed or manually cleared.
    $cache->save($cacheKey, $registry, WireCache::expireNever);

    return $registry;
  }

  /**
   * Validate API key
   *
   * Authorization: Bearer YOUR_KEY
   */
  protected function validateApiKey(): bool {
    $config = wire('config');

    /**
     * API keys must exist
     */
    if (empty($config->apiKeys)) {
      return false;
    }

    /**
     * Get auth header
     */
    $header =
      $_SERVER['HTTP_AUTHORIZATION']
      ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
      ?? '';

    if (!$header) {
      return false;
    }

    /**
     * Parse Bearer token
     */
    if (!preg_match('/Bearer\\s+(.*)$/i', $header, $matches)) {
      return false;
    }

    $token = trim($matches[1]);

    /**
     * Named keys
     *
     * Example:
     * [
     *   'frontend' => 'abc123',
     *   'mobile' => 'xyz456'
     * ]
     */
    $client = array_search(
      $token,
      $config->apiKeys,
      true
    );

    if ($client === false) {
      return false;
    }

    $this->currentApiClient = $client;

    return true;
  }

  /**
   * Send CORS headers
   *
   * cors => true
   * cors => [
   *   'origin'      => 'https://example.com',
   *   'methods'     => ['GET', 'POST'],
   *   'headers'     => ['Content-Type', 'Authorization'],
   *   'credentials' => true,
   *   'maxAge'      => 3600,
   * ]
   */
  protected function sendCorsHeaders($cors): void {
    $allowedOrigins = ['*'];
    $methods        = ['GET', 'POST', 'OPTIONS'];
    $headers        = ['Content-Type', 'Authorization'];
    $credentials    = false;
    $maxAge         = null;

    if (is_array($cors)) {
      if (!empty($cors['origin']))     $allowedOrigins = (array) $cors['origin'];
      if (!empty($cors['methods']))    $methods        = array_unique(array_merge((array) $cors['methods'], ['OPTIONS']));
      if (!empty($cors['headers']))    $headers        = (array) $cors['headers'];
      if (isset($cors['credentials'])) $credentials    = (bool) $cors['credentials'];
      if (!empty($cors['maxAge']))     $maxAge         = (int) $cors['maxAge'];
    }

    /**
     * Single wildcard — send as-is
     */
    if ($allowedOrigins === ['*']) {

      header('Access-Control-Allow-Origin: *');
    } else {

      /**
       * Reflect the request origin if it is in the allowed list.
       * Always send Vary: Origin so caches store separate responses
       * per origin.
       */
      $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

      header('Vary: Origin');

      if (in_array($requestOrigin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
      }
    }

    header('Access-Control-Allow-Methods: ' . implode(', ', $methods));
    header('Access-Control-Allow-Headers: ' . implode(', ', $headers));

    if ($credentials) {
      header('Access-Control-Allow-Credentials: true');
    }

    if ($maxAge !== null) {
      header('Access-Control-Max-Age: ' . $maxAge);
    }
  }

  /**
   * JSON response helper
   */
  public function json(
    array $data,
    int $status = 200
  ) {
    http_response_code($status);

    echo json_encode(
      $data,
      JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    return;
  }

  /**
   * 401
   */
  protected function unauthorized() {
    return $this->json([
      'success' => false,
      'message' => 'Unauthorized'
    ], 401);
  }

  /**
   * 404
   */
  protected function notFound(
    string $message = 'Not found'
  ) {
    return $this->json([
      'success' => false,
      'message' => $message
    ], 404);
  }

  /**
   * 500
   */
  protected function serverError(
    string $message = 'Server error'
  ) {
    return $this->json([
      'success' => false,
      'message' => $message
    ], 500);
  }

  /**
   * Clear route cache
   */
  public function clearRouteCache() {
    wire('cache')->delete(
      'api-router-registry'
    );
  }
}
