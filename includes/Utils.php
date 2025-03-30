<?php

namespace TranslationManager;

class Utils {
	/**
	 * Take a normal $key=>$valumakeDropdownOptionse array and return MW-compatible dropdown option array
	 * @param array $data
	 * @param array $flags
	 * @return array
	 */
	public static function makeDropdownOptions( array $data = [], array $flags = [] ): array {
		$additionalOptions = [];

		// Convert arrays with default sequential keys to associative arrays where value=key
		if ( !empty( $data ) && self::isSequentialArray( $data ) ) {
			$data = array_combine( $data, $data );
		}
		if ( !in_array( 'no_flip', $flags ) ) {
			$data = array_flip( $data );
		}

		if ( in_array( 'include_all', $flags ) ) {
			$additionalOptions[wfMessage( 'ext-tm-dropdown-all' )->text()] = '';
		}

		if ( in_array( 'include_none', $flags ) ) {
			$additionalOptions[wfMessage( 'ext-tm-dropdown-none' )->text()] = '';
		}

		return array_merge( $additionalOptions, $data );
	}

	/**
	 * Check if an array is sequential (numeric indexes starting from 0)
	 *
	 * @param array $array The array to check
	 * @return bool True if array is sequential, false otherwise
	 */
	private static function isSequentialArray( array $array ): bool {
		return array_keys( $array ) === range( 0, count( $array ) - 1 );
	}
}
