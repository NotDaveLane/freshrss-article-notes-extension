<?php

declare(strict_types=1);

return array(
	'article_notes' => array(
		'name' => 'ArticleNotes',
		'description' => 'Attach personal notes to articles and fetch them through the FreshRSS API.',
		'note' => array(
			'title' => 'Your note',
			'updated' => 'Last updated %s',
			'action' => 'Note',
			'action_title' => 'Add or edit your note on this article',
			'label' => 'Your note on this article',
			'save' => 'Save',
			'cancel' => 'Cancel',
			'delete' => 'Delete',
			'confirm_delete' => 'Delete your note on this article?',
			'counter' => '%1$s / %2$s',
			'saving' => 'Saving…',
		),
		'error' => array(
			'signed_out' => 'You must be signed in to edit notes.',
			'csrf' => 'The security token is invalid. Reload the page and try again.',
			'bad_request' => 'Invalid request.',
			'unknown_entry' => 'This article no longer exists.',
			'too_long' => 'The note is too long (20,000 characters at most).',
			'server' => 'The note could not be saved. Try again later.',
			'network' => 'The request failed. Check your connection and try again.',
		),
	),
);
