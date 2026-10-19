<?php

declare(strict_types=1);

namespace ArticleNotes;

/** A JSON API response. */
final class ApiResponse {
	/**
	 * @param array<string,mixed> $body
	 * @param array<string,string> $headers
	 */
	public function __construct(
		public readonly int $status,
		public readonly array $body,
		public readonly array $headers = [],
	) {
	}

	/**
	 * @param array<string,mixed> $body
	 * @param array<string,string> $headers
	 */
	public static function withStatus(int $status, array $body, array $headers = []): self {
		return new self($status, $body, $headers + self::defaultHeaders());
	}

	/** @param array<string,string> $headers */
	public static function noContent(array $headers = []): self {
		return new self(204, [], $headers + ['Cache-Control' => 'no-store']);
	}

	/** @param array<string,mixed> $body */
	public static function ok(array $body): self {
		return new self(200, $body, self::defaultHeaders());
	}

	/** @param array<string,string> $headers */
	public static function error(int $status, string $code, string $message, array $headers = []): self {
		return new self($status, ['error' => ['code' => $code, 'message' => $message]], $headers + self::defaultHeaders());
	}

	/** @return array<string,string> */
	private static function defaultHeaders(): array {
		return ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store'];
	}

	public function json(): string {
		return json_encode($this->body,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
	}

	/** Sends the status, headers and (unless answering a HEAD request) the body. */
	public function send(bool $includeBody = true): void {
		$json = $this->json();
		if (!headers_sent()) {
			http_response_code($this->status);
			foreach ($this->headers as $name => $value) {
				header($name . ': ' . $value);
			}
		}
		if ($includeBody && $this->status !== 204) {
			echo $json;
		}
	}
}
