<?php

declare(strict_types=1);

namespace ArticleNotes;

/**
 * Canonical form of a web page URL, so the variants of one page (tracking parameters, `http` vs `https`, `www.`, a
 * fragment, a trailing slash, …) compare equal. Pure functions; the rules are documented in the `url-lookup` spec.
 */
final class UrlNormalizer {
	public const MAX_LENGTH = 16383;
	private const TRACKING_PARAMETERS = ['fbclid', 'gclid', 'mc_cid', 'mc_eid', 'ref_src'];
	private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

	private function __construct() {
	}

	/**
	 * The normalized form of an http(s) URL, or null for anything else (relative, other schemes, malformed). The
	 * scheme is always written `https` and a leading `www.` is dropped: this form is for matching, not for display.
	 */
	public static function normalize(string $url): ?string {
		$url = trim($url);
		if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
			return null;
		}
		if (preg_match('~^(https?)://([^/?#]*)([^?#]*)(?:\?([^#]*))?(?:#.*)?$~i', $url, $matches) !== 1) {
			return null;
		}
		$scheme = strtolower($matches[1]);
		$host = self::host($matches[2], $scheme);
		if ($host === null) {
			return null;
		}
		$path = self::path($matches[3]);
		$query = self::query($matches[4] ?? '');
		return 'https://' . $host . $path . ($query === '' ? '' : '?' . $query);
	}

	/** The key to index and look a normalized URL up by (a hex SHA-1). */
	public static function hash(string $normalizedUrl): string {
		return sha1($normalizedUrl);
	}

	/** The normalized URL of an article: its link, or its GUID when the link is empty and the GUID is a URL. */
	public static function forEntry(string $link, string $guid): ?string {
		return self::normalize($link) ?? self::normalize($guid);
	}

	/** @return string|null host with its non-default port, lowercased, without `www.` and credentials */
	private static function host(string $authority, string $scheme): ?string {
		$at = strrpos($authority, '@');
		$hostPort = $at === false ? $authority : substr($authority, $at + 1);
		if (preg_match('/^(?:(\[[0-9a-f:.]+\])|([^:\[\]]+))(?::(\d*))?$/i', $hostPort, $matches) !== 1) {
			return null;
		}
		$ipv6 = $matches[1] ?? '';
		$host = strtolower($ipv6 !== '' ? $ipv6 : $matches[2] ?? '');
		if ($ipv6 === '') {
			$host = rtrim($host, '.');
			if (preg_match('/[^\x00-\x7f]/', $host) === 1) {
				$ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46) : false;
				if ($ascii === false) {
					return null;
				}
				$host = $ascii;
			}
			if (str_starts_with($host, 'www.') && strlen($host) > 4) {
				$host = substr($host, 4);
			}
			if ($host === '' || preg_match('/^[a-z0-9._~%-]+$/', $host) !== 1) {
				return null;
			}
		}
		$port = $matches[3] ?? '';
		if ($port !== '') {
			if (strlen($port) > 5 || (int)$port < 1 || (int)$port > 65535) {
				return null;
			}
			if ((int)$port !== self::DEFAULT_PORTS[$scheme]) {
				$host .= ':' . (int)$port;
			}
		}
		return $host;
	}

	private static function path(string $path): string {
		$path = self::normalizeEncoding($path);
		if ($path !== '/') {
			$path = rtrim($path, '/');
		}
		return $path === '' ? '/' : $path;
	}

	private static function query(string $query): string {
		$pairs = [];
		foreach (explode('&', $query) as $pair) {
			if ($pair === '') {
				continue;
			}
			$pair = self::normalizeEncoding($pair);
			$name = strtolower(explode('=', $pair, 2)[0]);
			if (str_starts_with($name, 'utm_') || in_array($name, self::TRACKING_PARAMETERS, true)) {
				continue;
			}
			$pairs[] = $pair;
		}
		sort($pairs, SORT_STRING);
		return implode('&', $pairs);
	}

	/** Decodes the percent-encoded unreserved characters, upper-cases the other escapes and encodes non-ASCII bytes. */
	private static function normalizeEncoding(string $value): string {
		$value = preg_replace_callback('/[^\x00-\x7f]/', static fn (array $m): string => rawurlencode($m[0]), $value) ?? $value;
		return preg_replace_callback('/%([0-9a-fA-F]{2})/', static function (array $m): string {
			$character = chr((int)hexdec($m[1]));
			return preg_match('/^[A-Za-z0-9\-._~]$/', $character) === 1 ? $character : '%' . strtoupper($m[1]);
		}, $value) ?? $value;
	}
}
