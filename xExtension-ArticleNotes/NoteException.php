<?php

declare(strict_types=1);

namespace ArticleNotes;

/** A note cannot be saved because of the request (as opposed to a storage failure). */
final class NoteException extends \RuntimeException {
	public const UNKNOWN_ENTRY = 1;
	public const TOO_LONG = 2;
	public const INVALID_URL = 3;
	public const TITLE_TOO_LONG = 4;
	/** The URL of a page note is a FreshRSS article: use the article note (see `$entryId`). */
	public const URL_IS_ARTICLE = 5;

	/** @param string|null $entryId the article behind {@see URL_IS_ARTICLE} */
	public function __construct(string $message, int $code, public readonly ?string $entryId = null) {
		parent::__construct($message, $code);
	}
}
