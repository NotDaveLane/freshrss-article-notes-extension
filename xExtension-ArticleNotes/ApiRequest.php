<?php

declare(strict_types=1);

namespace ArticleNotes;

/** An API request: method, path below the extension (e.g. `v1/notes`), query string and `Authorization` header. */
final class ApiRequest {
	/** @param array<string,string> $query */
	public function __construct(
		public readonly string $method,
		public readonly string $path,
		public readonly array $query,
		#[\SensitiveParameter]
		public readonly string $authorization,
	) {
	}

	/** The current request to `p/api/misc.php/<extension>/<path>` (or `misc.php/<path>?ext=<extension>`). */
	public static function fromGlobals(string $extensionName): self {
		$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

		$pathInfo = '';
		foreach (['PATH_INFO', 'ORIG_PATH_INFO'] as $name) {
			if (is_string($_SERVER[$name] ?? null) && $_SERVER[$name] !== '') {
				$pathInfo = $_SERVER[$name];
				break;
			}
		}
		// Same normalisation as misc.php
		$path = trim(preg_replace('%^(/api)?(/misc\.php)?%', '', rawurldecode($pathInfo)) ?? '', '/');
		if ($path === $extensionName || str_starts_with($path, $extensionName . '/')) {
			$path = substr($path, strlen($extensionName) + 1);
		}

		$query = [];
		foreach ($_GET as $name => $value) {
			if (is_string($value)) {
				$query[(string)$name] = $value;
			}
		}

		return new self($method, $path, $query, self::authorizationHeader());
	}

	private static function authorizationHeader(): string {
		foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $name) {
			if (is_string($_SERVER[$name] ?? null) && $_SERVER[$name] !== '') {
				return $_SERVER[$name];
			}
		}
		if (function_exists('getallheaders')) {
			foreach (getallheaders() as $name => $value) {
				if (strcasecmp((string)$name, 'Authorization') === 0 && is_string($value)) {
					return $value;
				}
			}
		}
		// Basic credentials already decoded by the web server
		if (is_string($_SERVER['PHP_AUTH_USER'] ?? null) && $_SERVER['PHP_AUTH_USER'] !== '') {
			$password = is_string($_SERVER['PHP_AUTH_PW'] ?? null) ? $_SERVER['PHP_AUTH_PW'] : '';
			return 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $password);
		}
		return '';
	}
}
