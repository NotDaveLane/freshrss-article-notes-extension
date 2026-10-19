<?php

declare(strict_types=1);

namespace ArticleNotes;

/**
 * An API request: method, path below the extension (e.g. `v1/notes`), query string, `Authorization` header and, for
 * writes, the body (read up to {@see MAX_BODY_BYTES} + 1 bytes), its `Content-Type` and `If-Match` headers.
 */
final class ApiRequest {
	public const MAX_BODY_BYTES = 65536;

	/** @param array<string,string> $query */
	public function __construct(
		public readonly string $method,
		public readonly string $path,
		public readonly array $query,
		#[\SensitiveParameter]
		public readonly string $authorization,
		public readonly string $body = '',
		public readonly string $contentType = '',
		public readonly ?string $ifMatch = null,
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

		$ifMatch = $_SERVER['HTTP_IF_MATCH'] ?? null;
		return new self($method, $path, $query, self::authorizationHeader(), self::bodyFromInput($method),
			is_string($_SERVER['CONTENT_TYPE'] ?? null) ? $_SERVER['CONTENT_TYPE'] : '', is_string($ifMatch) ? $ifMatch : null);
	}

	/** The request body of a `PUT`, `POST` or `PATCH`, at most {@see MAX_BODY_BYTES} + 1 bytes: one more than allowed means too large. */
	private static function bodyFromInput(string $method): string {
		if (!in_array($method, ['PUT', 'POST', 'PATCH'], true)) {
			return '';
		}
		$length = $_SERVER['CONTENT_LENGTH'] ?? null;
		if (is_string($length) && ctype_digit($length) && (int)$length > self::MAX_BODY_BYTES) {
			return str_repeat(' ', self::MAX_BODY_BYTES + 1);	// no need to read what is refused anyway
		}
		$input = fopen('php://input', 'r');
		if ($input === false) {
			return '';
		}
		$body = stream_get_contents($input, self::MAX_BODY_BYTES + 1);
		fclose($input);
		return $body === false ? '' : $body;
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
