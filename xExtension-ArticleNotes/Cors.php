<?php

declare(strict_types=1);

namespace ArticleNotes;

/**
 * Cross-origin access for web-page clients. An administrator lists the allowed client origins; requests from any other
 * origin (and every request while the list is empty) get no CORS header at all. Credentials travel in the `Authorization`
 * header, so `Access-Control-Allow-Credentials` is never sent.
 */
final class Cors {
	public const MAX_AGE = 600;
	public const ALLOWED_HEADERS = 'Authorization, Content-Type, If-Match';
	/** Upper bound for the list, so the setting cannot grow the configuration file without limit. */
	public const MAX_ORIGINS = 50;

	/**
	 * Parses the "Allowed client origins" setting: one origin per line, blank lines ignored. An origin is
	 * `http(s)://host[:port]`, `moz-extension://<uuid>` or `chrome-extension://<id>` (no path, no wildcard).
	 *
	 * @return array{origins:list<string>,invalid:list<string>} the normalized, de-duplicated origins and the lines refused
	 */
	public static function parse(string $text): array {
		$origins = [];
		$invalid = [];
		$lines = preg_split('/\R/', $text);
		foreach ($lines === false ? [] : $lines as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			$origin = self::normalize($line);
			if ($origin === null) {
				$invalid[] = $line;
			} elseif (!in_array($origin, $origins, true)) {
				$origins[] = $origin;
			}
		}
		if (count($origins) > self::MAX_ORIGINS) {
			$invalid[] = 'more than ' . self::MAX_ORIGINS . ' origins';
		}
		return ['origins' => $origins, 'invalid' => $invalid];
	}

	/** The canonical form of an origin (lowercase scheme and host, no default port), null when it is not an exact origin. */
	public static function normalize(string $origin): ?string {
		if (preg_match('#^(https?)://((?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*|\[[0-9a-f:]+\])(?::([0-9]{1,5}))?$#i',
			$origin, $matches) === 1) {
			$scheme = strtolower($matches[1]);
			$port = $matches[3] ?? '';
			if ($port !== '' && ((int)$port < 1 || (int)$port > 65535)) {
				return null;
			}
			if (($scheme === 'http' && $port === '80') || ($scheme === 'https' && $port === '443')) {
				$port = '';
			}
			return $scheme . '://' . strtolower($matches[2]) . ($port === '' ? '' : ':' . ltrim($port, '0'));
		}
		if (preg_match('#^moz-extension://([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$#i', $origin, $matches) === 1) {
			return 'moz-extension://' . strtolower($matches[1]);
		}
		if (preg_match('#^chrome-extension://([a-p]{32})$#', $origin, $matches) === 1) {
			return 'chrome-extension://' . $matches[1];
		}
		return null;
	}

	/**
	 * The request's origin when it is in the allowed list, else null.
	 *
	 * @param list<string> $allowed
	 */
	public static function allowedOrigin(?string $origin, array $allowed): ?string {
		return $origin !== null && $origin !== '' && in_array($origin, $allowed, true) ? $origin : null;
	}

	/**
	 * Headers for a response to an allowed origin.
	 *
	 * @return array<string,string>
	 */
	public static function responseHeaders(string $origin): array {
		return ['Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin', 'Access-Control-Expose-Headers' => 'ETag'];
	}

	/**
	 * Headers for the answer to a preflight request.
	 *
	 * @param list<string> $methods
	 * @return array<string,string>
	 */
	public static function preflightHeaders(string $origin, array $methods): array {
		return self::responseHeaders($origin) + [
			'Access-Control-Allow-Methods' => implode(', ', $methods),
			'Access-Control-Allow-Headers' => self::ALLOWED_HEADERS,
			'Access-Control-Max-Age' => (string)self::MAX_AGE,
		];
	}
}
