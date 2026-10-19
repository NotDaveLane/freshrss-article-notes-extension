<?php

declare(strict_types=1);

namespace ArticleNotes;

/** A request the API refuses with a specific HTTP status and error code. */
final class ApiException extends \RuntimeException {
	public function __construct(public readonly int $status, public readonly string $errorCode, string $message) {
		parent::__construct($message);
	}
}
