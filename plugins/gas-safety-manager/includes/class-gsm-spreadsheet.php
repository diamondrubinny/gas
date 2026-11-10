<?php
/**
 * Minimal spreadsheet reader — CSV and XLSX — with no external library.
 *
 * XLSX is read straight from the OOXML package (shared strings + the first
 * worksheet) via ZipArchive. CSV is read with UTF-8/BOM handling and simple
 * delimiter detection. Both paths are capped (rows/cols/size) so a hostile or
 * huge file can't exhaust memory.
 *
 * Returns: array( 'headers' => string[], 'rows' => array<array<string>>, 'truncated' => bool )
 * where headers = the first row, rows = the remaining data rows (ragged rows
 * are padded to the header width).
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Spreadsheet
 */
class GSM_Spreadsheet {

	const MAX_ROWS        = 10000;   // data rows (excludes header).
	const MAX_COLS        = 200;
	const MAX_UNCOMPRESSED = 31457280; // 30 MB total unzipped (zip-bomb guard).
	const MAX_ENTRIES      = 50;

	/**
	 * Read a file into headers + rows.
	 *
	 * @param string $path File path (an uploaded temp file).
	 * @param string $ext  Lower-case extension ('csv'|'xlsx').
	 * @return array|WP_Error
	 */
	public static function read( $path, $ext ) {
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'gsm_file', __( 'File is not readable.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$ext = strtolower( $ext );
		if ( 'csv' === $ext || 'txt' === $ext ) {
			return self::read_csv( $path );
		}
		if ( 'xlsx' === $ext ) {
			return self::read_xlsx( $path );
		}
		return new WP_Error( 'gsm_file_type', __( 'Unsupported file type. Use CSV or XLSX.', 'gas-safety-manager' ), array( 'status' => 400 ) );
	}

	/**
	 * Package a grid (all rows incl. header) into the return shape.
	 *
	 * @param array $grid      Rows (first = header).
	 * @param bool  $truncated Whether rows were capped.
	 * @return array
	 */
	private static function package( $grid, $truncated ) {
		if ( empty( $grid ) ) {
			return array( 'headers' => array(), 'rows' => array(), 'truncated' => false );
		}
		$headers = array_map( 'strval', array_shift( $grid ) );
		$width   = count( $headers );
		$rows    = array();
		foreach ( $grid as $r ) {
			$r = array_map( 'strval', (array) $r );
			if ( count( $r ) < $width ) {
				$r = array_pad( $r, $width, '' );
			} elseif ( count( $r ) > $width ) {
				$r = array_slice( $r, 0, $width );
			}
			// Skip fully-empty rows.
			$has = false;
			foreach ( $r as $v ) {
				if ( '' !== trim( $v ) ) { $has = true; break; }
			}
			if ( $has ) {
				$rows[] = $r;
			}
		}
		return array( 'headers' => $headers, 'rows' => $rows, 'truncated' => (bool) $truncated );
	}

	/**
	 * Read a CSV file.
	 *
	 * @param string $path Path.
	 * @return array
	 */
	private static function read_csv( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			return new WP_Error( 'gsm_file', __( 'Could not read file.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		// Strip UTF-8 BOM.
		if ( 0 === strncmp( $raw, "\xEF\xBB\xBF", 3 ) ) {
			$raw = substr( $raw, 3 );
		}
		// If it's not valid UTF-8, assume Windows-1255 (Hebrew Excel CSV).
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$conv = @mb_convert_encoding( $raw, 'UTF-8', 'Windows-1255' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $conv && '' !== $conv ) {
				$raw = $conv;
			}
		}
		$raw   = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		$lines = explode( "\n", $raw );

		// Delimiter detection from the first non-empty line.
		$delim = ',';
		foreach ( $lines as $l ) {
			if ( '' === trim( $l ) ) { continue; }
			$counts = array(
				',' => substr_count( $l, ',' ),
				';' => substr_count( $l, ';' ),
				"\t" => substr_count( $l, "\t" ),
			);
			arsort( $counts );
			$delim = key( $counts );
			break;
		}

		$grid      = array();
		$truncated = false;
		foreach ( $lines as $l ) {
			if ( '' === $l ) { continue; }
			if ( count( $grid ) > self::MAX_ROWS ) { $truncated = true; break; }
			$cells = str_getcsv( $l, $delim );
			if ( count( $cells ) > self::MAX_COLS ) {
				$cells = array_slice( $cells, 0, self::MAX_COLS );
			}
			$grid[] = $cells;
		}
		return self::package( $grid, $truncated );
	}

	/**
	 * Read an XLSX file (first worksheet).
	 *
	 * @param string $path Path.
	 * @return array|WP_Error
	 */
	private static function read_xlsx( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'gsm_zip', __( 'XLSX reading needs the PHP Zip extension. Save the file as CSV and try again.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'gsm_xlsx', __( 'Not a valid XLSX file.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}

		// Zip-bomb guard: cap entry count and total uncompressed size.
		if ( $zip->numFiles > self::MAX_ENTRIES ) {
			$zip->close();
			return new WP_Error( 'gsm_xlsx', __( 'File has too many internal parts.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$total = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$st = $zip->statIndex( $i );
			if ( $st && isset( $st['size'] ) ) {
				$total += (int) $st['size'];
			}
		}
		if ( $total > self::MAX_UNCOMPRESSED ) {
			$zip->close();
			return new WP_Error( 'gsm_xlsx', __( 'File is too large to import.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}

		$shared = self::xlsx_shared_strings( $zip );
		$sheet  = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		if ( false === $sheet ) {
			// Fall back to the first worksheet part found.
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = $zip->getNameIndex( $i );
				if ( 0 === strpos( $name, 'xl/worksheets/' ) && '.xml' === substr( $name, -4 ) ) {
					$sheet = $zip->getFromName( $name );
					break;
				}
			}
		}
		$zip->close();

		if ( false === $sheet || '' === (string) $sheet ) {
			return new WP_Error( 'gsm_xlsx', __( 'No worksheet found in the file.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		return self::xlsx_parse_sheet( $sheet, $shared );
	}

	/**
	 * Parse xl/sharedStrings.xml into an indexed array.
	 *
	 * @param ZipArchive $zip Open archive.
	 * @return string[]
	 */
	private static function xlsx_shared_strings( $zip ) {
		$xml = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false === $xml || '' === (string) $xml ) {
			return array();
		}
		$prev = libxml_use_internal_errors( true );
		$sst  = simplexml_load_string( $xml );
		libxml_use_internal_errors( $prev );
		if ( false === $sst ) {
			return array();
		}
		$out = array();
		foreach ( $sst->si as $si ) {
			if ( isset( $si->t ) && 0 === $si->r->count() ) {
				$out[] = (string) $si->t;
			} else {
				// Rich text: concatenate all run <t> parts.
				$s = '';
				foreach ( $si->r as $r ) {
					$s .= (string) $r->t;
				}
				if ( '' === $s && isset( $si->t ) ) {
					$s = (string) $si->t;
				}
				$out[] = $s;
			}
		}
		return $out;
	}

	/**
	 * Parse a worksheet XML string into the grid.
	 *
	 * @param string   $xml    Worksheet XML.
	 * @param string[] $shared Shared strings.
	 * @return array
	 */
	private static function xlsx_parse_sheet( $xml, $shared ) {
		$prev = libxml_use_internal_errors( true );
		$sx   = simplexml_load_string( $xml );
		libxml_use_internal_errors( $prev );
		if ( false === $sx || ! isset( $sx->sheetData ) ) {
			return array( 'headers' => array(), 'rows' => array(), 'truncated' => false );
		}

		$grid      = array();
		$truncated = false;
		foreach ( $sx->sheetData->row as $row ) {
			if ( count( $grid ) > self::MAX_ROWS ) { $truncated = true; break; }
			$cells = array();
			foreach ( $row->c as $c ) {
				$ref  = (string) $c['r'];
				$col  = self::col_index( $ref );
				if ( $col < 0 ) { continue; }
				if ( $col >= self::MAX_COLS ) { continue; }
				$type = (string) $c['t'];
				$val  = '';
				if ( 's' === $type ) {
					$idx = (int) ( (string) $c->v );
					$val = isset( $shared[ $idx ] ) ? $shared[ $idx ] : '';
				} elseif ( 'inlineStr' === $type ) {
					$val = isset( $c->is->t ) ? (string) $c->is->t : '';
				} else {
					// number, boolean, formula string -> literal <v>.
					$val = isset( $c->v ) ? (string) $c->v : '';
				}
				$cells[ $col ] = $val;
			}
			if ( empty( $cells ) ) {
				$grid[] = array();
				continue;
			}
			// Fill gaps 0..max.
			$max = max( array_keys( $cells ) );
			$flat = array();
			for ( $i = 0; $i <= $max; $i++ ) {
				$flat[] = isset( $cells[ $i ] ) ? $cells[ $i ] : '';
			}
			$grid[] = $flat;
		}
		return self::package( $grid, $truncated );
	}

	/**
	 * Column index (0-based) from an A1-style cell reference ("C7" -> 2).
	 *
	 * @param string $ref Cell ref.
	 * @return int -1 if none.
	 */
	private static function col_index( $ref ) {
		if ( ! preg_match( '/^([A-Z]+)/', strtoupper( $ref ), $m ) ) {
			return -1;
		}
		$letters = $m[1];
		$n       = 0;
		$len     = strlen( $letters );
		for ( $i = 0; $i < $len; $i++ ) {
			$n = $n * 26 + ( ord( $letters[ $i ] ) - 64 );
		}
		return $n - 1;
	}
}
