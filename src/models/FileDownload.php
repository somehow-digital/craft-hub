<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\models;

use SomehowDigital\Craft\Hub\enums\FileDownloadKind;

/**
 * A file that a bulk action has prepared, which the controller sends as the response.
 *
 * @internal
 */
final readonly class FileDownload
{
	/**
	 * @param mixed $content the stream, the path of the file, the rows, or what the exporter returned, by the kind
	 * @param array<string,mixed> $formatter options of the formatter of the format
	 */
	private function __construct(
		public FileDownloadKind $kind,
		public string $filename,
		public mixed $content,
		public ?string $format = null,
		public array $formatter = [],
		public ?string $mimeType = null,
		public ?int $size = null,
	) {}

	/**
	 * @param resource $stream
	 */
	public static function stream(mixed $stream, string $filename, ?string $mimeType, ?int $size): self
	{
		return new self(FileDownloadKind::Stream, $filename, $stream, mimeType: $mimeType, size: $size);
	}

	public static function temporaryFile(string $path, string $filename): self
	{
		return new self(FileDownloadKind::TemporaryFile, $filename, $path);
	}

	/**
	 * @param iterable<mixed> $rows
	 * @param array<string,mixed> $formatter
	 */
	public static function formatted(iterable $rows, string $filename, string $format, array $formatter = []): self
	{
		return new self(FileDownloadKind::Formatted, $filename, $rows, $format, $formatter);
	}

	public static function raw(mixed $export, string $filename): self
	{
		return new self(FileDownloadKind::Raw, $filename, $export);
	}
}
