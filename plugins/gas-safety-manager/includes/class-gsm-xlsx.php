<?php
/**
 * Minimal XLSX (OOXML) writer.
 *
 * Builds a real .xlsx file server-side via ZipArchive — no external library,
 * and correct UTF-8 (Hebrew) with no CSV/locale ambiguity. Falls back to a
 * UTF-8 BOM CSV when ZipArchive is unavailable.
 *
 * Usage:
 *   $out = GSM_Xlsx::build( array(
 *       array( 'name' => 'ציוד', 'rows' => array( array('a','b'), array('1','2') ) ),
 *   ) );
 *   // $out = array( 'data' => <binary>, 'mime' => ..., 'ext' => 'xlsx'|'csv' )
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Xlsx
 */
class GSM_Xlsx {

	/**
	 * Build a workbook.
	 *
	 * @param array $sheets Array of array( 'name' => string, 'rows' => array<array<scalar>> ).
	 * @return array{data:string,mime:string,ext:string}
	 */
	public static function build( $sheets ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return self::csv_fallback( $sheets );
		}

		// wp_tempnam() lives in wp-admin/includes/file.php, which WordPress does
		// not load on REST requests — without this the export fatals on a clean site.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = wp_tempnam( 'gsm_xlsx' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			return self::csv_fallback( $sheets );
		}

		$zip->addFromString( '[Content_Types].xml', self::content_types( count( $sheets ) ) );
		$zip->addFromString( '_rels/.rels', self::root_rels() );
		$zip->addFromString( 'xl/workbook.xml', self::workbook( $sheets ) );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', self::wb_rels( $sheets ) );
		foreach ( $sheets as $i => $s ) {
			$rows = isset( $s['rows'] ) ? $s['rows'] : array();
			$zip->addFromString( 'xl/worksheets/sheet' . ( $i + 1 ) . '.xml', self::sheet_xml( $rows ) );
		}
		$zip->close();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = file_get_contents( $tmp );
		wp_delete_file( $tmp );

		return array(
			'data' => (string) $data,
			'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'ext'  => 'xlsx',
		);
	}

	/**
	 * XML-escape a cell value.
	 *
	 * @param mixed $s Value.
	 * @return string
	 */
	private static function esc( $s ) {
		$s = (string) ( null === $s ? '' : $s );
		return str_replace(
			array( '&', '<', '>', '"', "'" ),
			array( '&amp;', '&lt;', '&gt;', '&quot;', '&apos;' ),
			$s
		);
	}

	/**
	 * Column letter from a 0-based index (0 -> A, 26 -> AA).
	 *
	 * @param int $n Index.
	 * @return string
	 */
	private static function col( $n ) {
		$s = '';
		$n = $n + 1;
		while ( $n > 0 ) {
			$m = ( $n - 1 ) % 26;
			$s = chr( 65 + $m ) . $s;
			$n = (int) floor( ( $n - 1 ) / 26 );
		}
		return $s;
	}

	/**
	 * Worksheet XML (all cells as inline strings — safe for Hebrew + numbers).
	 *
	 * @param array $rows Rows.
	 * @return string
	 */
	private static function sheet_xml( $rows ) {
		$out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
		$r = 0;
		foreach ( (array) $rows as $row ) {
			$r++;
			$out .= '<row r="' . $r . '">';
			$c = 0;
			foreach ( (array) $row as $cell ) {
				$ref  = self::col( $c ) . $r;
				$out .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::esc( $cell ) . '</t></is></c>';
				$c++;
			}
			$out .= '</row>';
		}
		$out .= '</sheetData></worksheet>';
		return $out;
	}

	/**
	 * [Content_Types].xml
	 *
	 * @param int $n Sheet count.
	 * @return string
	 */
	private static function content_types( $n ) {
		$overrides = '';
		for ( $i = 1; $i <= $n; $i++ ) {
			$overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. $overrides . '</Types>';
	}

	/**
	 * Root _rels/.rels
	 *
	 * @return string
	 */
	private static function root_rels() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
	}

	/**
	 * xl/workbook.xml
	 *
	 * @param array $sheets Sheets.
	 * @return string
	 */
	private static function workbook( $sheets ) {
		$s = '';
		foreach ( $sheets as $i => $sh ) {
			$name = isset( $sh['name'] ) ? $sh['name'] : ( 'Sheet' . ( $i + 1 ) );
			// Sheet names: max 31 chars, no : \ / ? * [ ]
			$name = preg_replace( '/[:\\\\\/?*\[\]]/', ' ', (string) $name );
			$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 31 ) : substr( $name, 0, 31 );
			$s   .= '<sheet name="' . self::esc( $name ) . '" sheetId="' . ( $i + 1 ) . '" r:id="rId' . ( $i + 1 ) . '"/>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
			. $s . '</sheets></workbook>';
	}

	/**
	 * xl/_rels/workbook.xml.rels
	 *
	 * @param array $sheets Sheets.
	 * @return string
	 */
	private static function wb_rels( $sheets ) {
		$s = '';
		foreach ( $sheets as $i => $sh ) {
			$s .= '<Relationship Id="rId' . ( $i + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ( $i + 1 ) . '.xml"/>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $s . '</Relationships>';
	}

	/**
	 * CSV fallback (first sheet only), UTF-8 with BOM so Excel reads Hebrew.
	 *
	 * @param array $sheets Sheets.
	 * @return array{data:string,mime:string,ext:string}
	 */
	private static function csv_fallback( $sheets ) {
		$rows = ( isset( $sheets[0]['rows'] ) ) ? $sheets[0]['rows'] : array();
		$lines = array();
		foreach ( (array) $rows as $row ) {
			$cells = array();
			foreach ( (array) $row as $cell ) {
				$v       = GSM_Security::csv_cell( $cell );
				$v       = str_replace( '"', '""', $v );
				$cells[] = '"' . $v . '"';
			}
			$lines[] = implode( ',', $cells );
		}
		$data = "\xEF\xBB\xBF" . implode( "\r\n", $lines );
		return array(
			'data' => $data,
			'mime' => 'text/csv; charset=utf-8',
			'ext'  => 'csv',
		);
	}
}
