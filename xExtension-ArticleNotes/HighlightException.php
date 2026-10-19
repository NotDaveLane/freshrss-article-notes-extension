<?php

declare(strict_types=1);

namespace ArticleNotes;

/** A highlight request is refused because of its content or target (as opposed to a storage failure). */
final class HighlightException extends \RuntimeException {
	public const INVALID_FIELD = 'invalid_field';
	public const INVALID_UUID = 'invalid_uuid';
	public const INVALID_TARGET = 'invalid_target';
	public const INVALID_URL = 'invalid_url';
	public const INVALID_SELECTOR = 'invalid_selector';
	public const INVALID_SOURCE = 'invalid_source';
	public const INVALID_COLOR = 'invalid_color';
	public const NOTE_TOO_LONG = 'note_too_long';
	public const TITLE_TOO_LONG = 'title_too_long';
	public const TOO_MANY = 'too_many_highlights';
	public const SELECTOR_IMMUTABLE = 'selector_immutable';
	public const UNKNOWN_ENTRY = 'unknown_entry';
	public const NOT_FOUND = 'not_found';
	public const UUID_CONFLICT = 'uuid_conflict';

	private const STATUSES = [
		self::INVALID_FIELD => 400,
		self::INVALID_UUID => 400,
		self::INVALID_TARGET => 400,
		self::INVALID_URL => 400,
		self::UNKNOWN_ENTRY => 404,
		self::NOT_FOUND => 404,
		self::UUID_CONFLICT => 409,
	];

	public function __construct(public readonly string $errorCode, string $message) {
		parent::__construct($message);
	}

	/** The HTTP status for this error: 400 malformed request, 404 unknown target, 409 conflict, else 422 invalid value. */
	public function status(): int {
		return self::STATUSES[$this->errorCode] ?? 422;
	}
}
