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
 * /api/project-tracking/new/
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

  const API_PREFIX = 'api';

  /**
   * Module info
   */
  public static function getModuleInfo() {
    return [
      'title'        => 'Api Router',
      'version'      => 200,
      'icon'         => 'code-fork',
      'summary'      => 'Lightweight module based API router. CORS and API-key auth delegated to Auth module.',
      'author'       => 'Ivan Milincic',
      'autoload'     => true,
      'singular'     => true,
      'configurable' => true,
      'requires'     => ['Auth'],
    ];
  }

  /**
   * Default module config data
   */
  public static function getDefaultData(): array {
    return [
      'login' => 0,
    ];
  }

  /**
   * Module config inputfields
   */
  public function getModuleConfigInputfields(array $data): InputfieldWrapper {
    $data        = array_merge(self::getDefaultData(), $data);
    $modules     = $this->wire('modules');
    $inputfields = $this->wire(new InputfieldWrapper());

    $f = $modules->get('InputfieldCheckbox');
    $f->attr('name', 'login');
    $f->attr('value', 1);
    $f->label       = __('Require Login');
    $f->description = __('Require a logged-in ProcessWire user for all routes.');
    if ($data['login']) $f->attr('checked', 'checked');
    $inputfields->append($f);

    $note = $modules->get('InputfieldMarkup');
    $note->label = __('CORS & API Key Auth');
    $note->value = '<p>' . __('CORS settings and API key authentication are configured in the <strong>Auth</strong> module.') . '</p>';
    $inputfields->append($note);

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
     * arguments(1) = capture group from '(/api/.*)'
     * e.g. "/api/project-tracking/test"
     */
    $rawPath = $event->arguments(1);

    if (!$rawPath) {
      return $this->notFound('Missing API route');
    }

    /**
     * Strip the /api/ prefix, leaving "route/endpoint"
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

    /** @var Auth $auth */
    $auth = $modules->get('Auth');

    /**
     * CORS headers — must run before auth so that OPTIONS preflight
     * requests (which carry no credentials) are not rejected.
     */
    $auth->sendCorsHeaders();

    /**
     * Preflight request — respond immediately, no auth required.
     */
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
      http_response_code(204);
      return;
    }

    /**
     * API key check — controlled by Auth module config.
     */
    if ($auth->requiresApiKey()) {
      if (!$auth->validateApiKey()) {
        return $this->unauthorized();
      }
    }

    /**
     * Login check — controlled by ApiRouter module config.
     */
    if ($this->login) {
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
    $apiClient = $auth->getApiClient();

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
      // Only inject keys that are valid PHP/PW field names.
      // Reject anything that could shadow PW internals or inject selector fragments.
      if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) continue;
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

    foreach (glob($modulesDir . '*/api/', GLOB_ONLYDIR) ?: [] as $apiDir) {
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
   * JSON response helper
   */
  public function json(
    array $data,
    int $status = 200
  ) {
    // Clear any buffered output (e.g. Tracy Debugger) before sending JSON.
    while (ob_get_level()) ob_end_clean();

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
