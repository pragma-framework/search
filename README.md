# Pragma Search

A simple Pragma module which can help to index and search text in Pragma Framework.

## Installation

In composer.json add:

	require {"pragma-framework/search": "dev-master"}

## Config

Pragma\Search use a stemmer (wamania/php-stemmer) in order to extends the search to other words with the same root.

To handle your language, you can specify the lang of your text:

	define('STEMMER_LANGUAGE', 'French');

By default, the stemmer works with "English"

And define:

	define('PRAGMA_MODULES','core,search');

For CLI indexation.

## Define your own min length for words the system should index

By default, the min length is 3 characters. But you can define a custom constant named `PRAGMA_SEARCH_MIN_WORD_LENGTH` in order to change this behavior.

## CLI exec

	php public/index.php indexer:run

or

	php public/index.php indexer:rebuild
