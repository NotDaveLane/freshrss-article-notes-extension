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
		'settings' => array(
			'menu' => 'Article notes',
			'title' => 'Article notes',
			'label_heading' => 'Label',
			'label_enabled' => 'Add a label to articles I annotate',
			'label_enabled_help' => 'Annotated articles are listed under this label in the sidebar, and FreshRSS keeps labelled articles when it archives old ones (default archiving setting “keep labelled articles”).',
			'label_name' => 'Label name',
			'label_name_help' => '1 to %s characters.',
			'label_name_invalid' => 'The label name must have 1 to %s characters.',
			'future_only' => 'Changes apply to notes you add from now on. Turning the label off or renaming it does not touch articles that are already labelled.',
			'api_heading' => 'API',
			'api_url' => 'Your API endpoint',
			'api_help' => 'Other systems can fetch your notes from this URL. See the API section of the README for authentication and usage.',
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
