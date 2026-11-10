<?php
/**
 * The print settings the store's designs are printed with, as Pixonaut answers them.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * A connection's `printing`.
 */
final class ConnectedStorePrinting {

	/**
	 * @param float                       $min_print_dpi             `minPrintDpi`, the least resolution a print may have.
	 * @param string                      $print_file_form           `printFileForm`, the form the store's print files are made in: its own choice, else the workspace's.
	 * @param ConnectedStoreTextTolerance $print_file_text_tolerance `printFileTextTolerance`, how far their live text may lie from its outline: its own choice, else the workspace's.
	 */
	public function __construct(
		public readonly float $min_print_dpi,
		public readonly string $print_file_form,
		public readonly ConnectedStoreTextTolerance $print_file_text_tolerance,
	) {
	}

	/**
	 * @param array<string, mixed> $data The object.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path ): self {
		return new self(
			min_print_dpi: Read::float( $data, 'minPrintDpi', $path ),
			print_file_form: Read::string( $data, 'printFileForm', $path ),
			print_file_text_tolerance: Read::object( $data, 'printFileTextTolerance', $path, ConnectedStoreTextTolerance::from_answer( ... ) ),
		);
	}
}
