<?php

namespace ProcessWire;

/**
 * ApiRouterOpenApi
 *
 * Generates OpenAPI 3.1 specification
 * from ApiRouter module endpoints.
 *
 * Example:
 * echo json_encode(
 *     (new ApiRouterOpenApi())->generate(),
 *     JSON_PRETTY_PRINT
 * );
 *
 */
class ApiRouterOpenApi extends WireData {
  /**
   * Generate OpenAPI spec
   */
  public function generate(): array {
    $paths = [];

    $registry = $this->getRouteRegistry();

    foreach ($registry as $route => $moduleName) {

      $modulePaths = wire('config')->paths->siteModules;

      $apiPath =
        $modulePaths .
        $moduleName .
        '/api/';

      if (!is_dir($apiPath)) {
        continue;
      }

      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($apiPath, \FilesystemIterator::SKIP_DOTS)
      );

      foreach ($iterator as $file) {

        if ($file->isDir()) {
          continue;
        }

        if ($file->getExtension() !== 'php') {
          continue;
        }

        $fullPath = $file->getPathname();

        $relativePath = str_replace(
          $apiPath,
          '',
          $fullPath
        );

        /**
         * Convert:
         * tasks/create.php
         * → tasks/create
         */
        $relativePath = preg_replace(
          '/\\.php$/',
          '',
          $relativePath
        );

        /**
         * index.php = root endpoint
         */
        if ($relativePath === 'index') {
          $relativePath = '';
        }

        /**
         * Build API URL
         */
        $apiUrl =
          '/api/' .
          $route;

        if ($relativePath) {
          $apiUrl .= '/' . $relativePath;
        }

        /**
         * Normalize slashes
         */
        $apiUrl = preg_replace(
          '#/+#',
          '/',
          $apiUrl
        );

        /**
         * Load endpoint metadata — buffer output to prevent endpoint
         * side-effects (echo, debug output) from polluting the spec JSON.
         */
        ob_start();
        try {
          $endpoint = include($fullPath);
        } catch (\Throwable $e) {
          ob_end_clean();
          continue; // skip endpoints that fail to load cleanly
        }
        ob_end_clean();

        $meta = $endpoint['_meta'] ?? [];

        /**
         * Skip undocumented endpoints
         */
        if (empty($meta)) {
          continue;
        }

        $method = strtolower(
          $meta['method'] ?? 'get'
        );

        $paths[$apiUrl][$method] = [

          'summary' =>
          $meta['summary'] ?? '',

          'tags' =>
          $meta['tags'] ?? [],

          'responses' =>
          $this->buildResponses(
            $meta['responses'] ?? []
          ),
        ];

        /**
         * Auth
         */
        if (!empty($meta['auth'])) {

          $paths[$apiUrl][$method]['security'] = [
            [
              'bearerAuth' => []
            ]
          ];
        }

        /**
         * Request body
         */
        if (!empty($meta['requestBody'])) {

          $paths[$apiUrl][$method]['requestBody'] = [

            'required' => true,

            'content' => [

              'application/json' => [

                'schema' =>
                $this->buildSchema(
                  $meta['requestBody']
                )
              ]
            ]
          ];
        }
      }
    }

    return [

      'openapi' => '3.1.0',

      'info' => [

        'title' =>
        wire('config')->httpHost .
          ' API',

        'version' => '1.0.0',
      ],

      'paths' => $paths,

      'components' => [

        'securitySchemes' => [

          'bearerAuth' => [

            'type' => 'http',

            'scheme' => 'bearer',
          ]
        ]
      ]
    ];
  }

  /**
   * Build OpenAPI responses
   */
  protected function buildResponses(
    array $responses
  ): array {

    $output = [];

    foreach ($responses as $code => $schema) {

      $output[$code] = [

        'description' =>
        'Response ' . $code,
      ];

      if (is_array($schema)) {

        $output[$code]['content'] = [

          'application/json' => [

            'schema' =>
            $this->buildSchema($schema)
          ]
        ];
      }
    }

    /**
     * Fallback response
     */
    if (empty($output)) {

      $output[200] = [
        'description' => 'Success'
      ];
    }

    return $output;
  }

  /**
   * Build JSON schema
   */
  protected function buildSchema(
    array $fields
  ): array {

    $properties = [];

    foreach ($fields as $field => $type) {

      /**
       * Nested object
       */
      if (is_array($type)) {

        $properties[$field] = [

          'type' => 'object',

          'properties' =>
          $this->buildSchema($type)['properties']
        ];

        continue;
      }

      $properties[$field] = [
        'type' => $this->normalizeType($type)
      ];
    }

    return [

      'type' => 'object',

      'properties' => $properties,
    ];
  }

  /**
   * Normalize field types
   */
  protected function normalizeType(
    string $type
  ): string {

    $map = [

      'string' => 'string',
      'text' => 'string',

      'int' => 'integer',
      'integer' => 'integer',

      'bool' => 'boolean',
      'boolean' => 'boolean',

      'float' => 'number',
      'number' => 'number',

      'array' => 'array',
      'object' => 'object',
    ];

    return $map[$type] ?? 'string';
  }

  /**
   * Get route registry from ApiRouter
   */
  protected function getRouteRegistry(): array {
    $apiRouter = wire('modules')->get('ApiRouter');

    if (!$apiRouter) {
      return [];
    }

    return $apiRouter->getRouteRegistry();
  }

  /**
   * Output JSON
   */
  public function renderJson() {
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
      $this->generate(),
      JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );
  }
}
