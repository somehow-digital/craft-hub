<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\enums;

/**
 * How the file of a bulk action is sent.
 *
 * @internal
 */
enum FileDownloadKind: string
{
	/**
	 * a stream, sent as a file of its own size and type
	 */
	case Stream = 'stream';

	/**
	 * a file on disk, which is deleted once it's sent
	 */
	case TemporaryFile = 'temporaryFile';

	/**
	 * rows to format as the format of the download
	 */
	case Formatted = 'formatted';

	/**
	 * what an exporter returns for itself, a stream or raw data
	 */
	case Raw = 'raw';
}
