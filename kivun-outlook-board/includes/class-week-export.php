<?php
/**
 * Excel (.xlsx) file of the week view: the same day × room table [kivun_week] shows.
 *
 * An .xlsx file is a ZIP of a few XML files, so it is written here without any library
 * (not even ZipArchive, which many hosts lack).
 *
 * Pure PHP: no WordPress dependency, so it can be unit tested on its own.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'KIVUN_BOARD_TESTING' ) ) {
	exit;
}

class Kivun_Board_Week_Export {

	const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

	const SHEET_NAME = 'לו״ז שבועי';

	/** Rows above the table: title, subtitle + dates, room names. They stay in view when scrolling. */
	const HEADER_ROWS = 3;

	/* Cell styles: positions in <cellXfs> of styles_xml(). */
	const XF_TITLE    = 1;
	const XF_SUBTITLE = 2;
	const XF_ROOM     = 3;
	const XF_DAY      = 4;
	const XF_CELL     = 5;
	const XF_FREE     = 6;

	const FONT = 'Arial';
	const GRAY = '6B6B72';

	/**
	 * Builds the .xlsx file.
	 *
	 * @param array $week {
	 *     title:    heading; its last word is red, as on the page.
	 *     subtitle: line under the heading (may be empty).
	 *     range:    dates of the week ("01.11 – 05.11.2026").
	 *     rooms:    [ { name, sub } ].
	 *     days:     [ { day_name, date_label, heb (Hebrew date, may be empty), cells: one list per room of { time, title, details[] } } ].
	 *     colors:   { red, dark, sky } as #rrggbb.
	 * }
	 * @return string File contents.
	 */
	public static function build( array $week ) {
		$colors = array_merge(
			array(
				'red'  => '#df2950',
				'dark' => '#2e2e31',
				'sky'  => '#5cc8f0',
			),
			isset( $week['colors'] ) ? (array) $week['colors'] : array()
		);
		foreach ( $colors as $key => $value ) {
			$colors[ $key ] = self::argb( $value );
		}

		return self::zip(
			array(
				'[Content_Types].xml'        => self::content_types_xml(),
				'_rels/.rels'                => self::root_rels_xml(),
				'xl/workbook.xml'            => self::workbook_xml(),
				'xl/_rels/workbook.xml.rels' => self::workbook_rels_xml(),
				'xl/styles.xml'              => self::styles_xml( $colors ),
				'xl/worksheets/sheet1.xml'   => self::sheet_xml( $week, $colors ),
			)
		);
	}

	/**
	 * Download name: "לוח שבועי 01.11-05.11.2026.xlsx".
	 *
	 * @param array $week Same as build() (uses days[].date as Y-m-d).
	 * @return string
	 */
	public static function filename( array $week ) {
		$days = isset( $week['days'] ) ? array_values( $week['days'] ) : array();
		if ( ! $days || empty( $days[0]['date'] ) ) {
			return 'לוח שבועי.xlsx';
		}
		$first = explode( '-', $days[0]['date'] );
		$last  = explode( '-', $days[ count( $days ) - 1 ]['date'] );
		if ( count( $first ) !== 3 || count( $last ) !== 3 ) {
			return 'לוח שבועי.xlsx';
		}
		return sprintf( 'לוח שבועי %s.%s-%s.%s.%s.xlsx', $first[2], $first[1], $last[2], $last[1], $last[0] );
	}

	/* ------------------------------------------------------------------ */
	/* Worksheet                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array    $week   See build().
	 * @param string[] $colors ARGB colors.
	 * @return string
	 */
	private static function sheet_xml( array $week, array $colors ) {
		$rooms = isset( $week['rooms'] ) ? array_values( $week['rooms'] ) : array();
		$days  = isset( $week['days'] ) ? array_values( $week['days'] ) : array();
		$last  = self::column( count( $rooms ) ); // Column A is the day, then one per room.
		$rows  = array();

		// 1: the heading, its last word in the accent color.
		$title = isset( $week['title'] ) ? trim( (string) $week['title'] ) : '';
		$words = preg_split( '/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY );
		$words = $words ? $words : array();
		$runs  = array();
		if ( count( $words ) > 1 ) {
			$accent = array_pop( $words );
			$runs[] = self::run( implode( ' ', $words ) . ' ', $colors['dark'], 18, true );
			$runs[] = self::run( $accent, $colors['red'], 18, true );
		} elseif ( $words ) {
			$runs[] = self::run( $words[0], $colors['dark'], 18, true );
		}
		$rows[1] = self::cell( 'A1', self::XF_TITLE, $runs );

		// 2: the subtitle and the dates of the week.
		$line = array();
		if ( isset( $week['subtitle'] ) && '' !== trim( (string) $week['subtitle'] ) ) {
			$line[] = trim( (string) $week['subtitle'] );
		}
		if ( isset( $week['range'] ) && '' !== (string) $week['range'] ) {
			$line[] = (string) $week['range'];
		}
		$rows[2] = self::cell( 'A2', self::XF_SUBTITLE, implode( ' · ', $line ) );

		// 3: a column per room.
		$row = self::cell( 'A3', self::XF_ROOM, 'יום' );
		foreach ( $rooms as $i => $room ) {
			$runs = array( self::run( (string) $room['name'], $colors['dark'], 12, true ) );
			if ( isset( $room['sub'] ) && '' !== (string) $room['sub'] ) {
				$runs[] = self::run( "\n" . $room['sub'], self::GRAY, 10 );
			}
			$row .= self::cell( self::column( $i + 1 ) . '3', self::XF_ROOM, $runs );
		}
		$rows[3] = $row;

		// A row per day.
		foreach ( $days as $d => $day ) {
			$r    = self::HEADER_ROWS + 1 + $d;
			$date = (string) $day['date_label'];
			if ( ! empty( $day['heb'] ) ) {
				$date .= ' · ' . $day['heb'];
			}
			$row = self::cell(
				'A' . $r,
				self::XF_DAY,
				array(
					self::run( 'יום ' . $day['day_name'], 'FFFFFFFF', 12, true ),
					self::run( "\n" . $date, 'FFFFFFFF', 10 ),
				)
			);

			foreach ( $rooms as $i => $room ) {
				$ref   = self::column( $i + 1 ) . $r;
				$items = isset( $day['cells'][ $i ] ) ? (array) $day['cells'][ $i ] : array();
				if ( ! $items ) {
					$row .= self::cell( $ref, self::XF_FREE, 'פנוי' );
					continue;
				}
				$runs = array();
				foreach ( array_values( $items ) as $n => $item ) {
					// A blank line between classes, like the divider on the page.
					$runs[] = self::run( ( $n ? "\n\n" : '' ) . $item['time'], self::GRAY, 10 );
					$runs[] = self::run( "\n" . $item['title'], $colors['dark'], 11, true );
					foreach ( isset( $item['details'] ) ? (array) $item['details'] : array() as $detail ) {
						$runs[] = self::run( "\n" . $detail, self::GRAY, 10 );
					}
				}
				$row .= self::cell( $ref, self::XF_CELL, $runs );
			}
			$rows[ $r ] = $row;
		}

		$data = '';
		foreach ( $rows as $r => $cells ) {
			$data .= '<row r="' . $r . '">' . $cells . '</row>';
		}

		$merge = '';
		if ( 'A' !== $last ) {
			$merge = '<mergeCells count="2"><mergeCell ref="A1:' . $last . '1"/><mergeCell ref="A2:' . $last . '2"/></mergeCells>';
		}

		$cols = '<col min="1" max="1" width="18" customWidth="1"/>';
		if ( $rooms ) {
			$cols .= '<col min="2" max="' . ( count( $rooms ) + 1 ) . '" width="30" customWidth="1"/>';
		}

		$top = 'B' . ( self::HEADER_ROWS + 1 );

		return self::XML_HEADER
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
			. '<dimension ref="A1:' . $last . max( self::HEADER_ROWS, self::HEADER_ROWS + count( $days ) ) . '"/>'
			// Right to left, with the heading rows and the day column frozen.
			. '<sheetViews><sheetView rightToLeft="1" workbookViewId="0">'
			. '<pane xSplit="1" ySplit="' . self::HEADER_ROWS . '" topLeftCell="' . $top . '" activePane="bottomRight" state="frozen"/>'
			. '<selection pane="bottomRight" activeCell="' . $top . '" sqref="' . $top . '"/>'
			. '</sheetView></sheetViews>'
			. '<sheetFormatPr defaultRowHeight="15"/>'
			. '<cols>' . $cols . '</cols>'
			. '<sheetData>' . $data . '</sheetData>'
			. $merge
			. '<printOptions horizontalCentered="1"/>'
			. '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
			// A4 landscape, the whole week one page wide.
			. '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
			. '</worksheet>';
	}

	/**
	 * One text cell. Inline strings are never read as formulas, so text such as "=..." stays text.
	 *
	 * @param string          $ref   Cell reference ("B4").
	 * @param int             $style Index in <cellXfs>.
	 * @param string|string[] $text  Plain text, or rich text runs from run().
	 * @return string
	 */
	private static function cell( $ref, $style, $text ) {
		if ( is_array( $text ) ) {
			$inner = implode( '', $text );
		} else {
			$inner = '<t xml:space="preserve">' . self::esc( $text ) . '</t>';
		}
		return '<c r="' . $ref . '" s="' . (int) $style . '" t="inlineStr"><is>' . $inner . '</is></c>';
	}

	/**
	 * A piece of rich text with its own font.
	 *
	 * @param string $text  Text ("\n" breaks the line).
	 * @param string $color ARGB.
	 * @param int    $size  Points.
	 * @param bool   $bold  Bold.
	 * @return string
	 */
	private static function run( $text, $color, $size, $bold = false ) {
		return '<r><rPr>' . ( $bold ? '<b/>' : '' ) . '<sz val="' . (int) $size . '"/><color rgb="' . self::argb( $color ) . '"/><rFont val="' . self::FONT . '"/></rPr>'
			. '<t xml:space="preserve">' . self::esc( $text ) . '</t></r>';
	}

	/**
	 * Column letters: 0 → A, 25 → Z, 26 → AA.
	 *
	 * @param int $index Zero-based column.
	 * @return string
	 */
	public static function column( $index ) {
		$name = '';
		for ( $n = (int) $index + 1; $n > 0; $n = intdiv( $n - 1, 26 ) ) {
			$name = chr( 65 + ( $n - 1 ) % 26 ) . $name;
		}
		return $name;
	}

	/* ------------------------------------------------------------------ */
	/* Package parts                                                      */
	/* ------------------------------------------------------------------ */

	const XML_HEADER = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";

	/**
	 * @param string[] $colors ARGB colors.
	 * @return string
	 */
	private static function styles_xml( array $colors ) {
		$font = function ( $size, $color, $bold = false ) {
			return '<font>' . ( $bold ? '<b/>' : '' ) . '<sz val="' . $size . '"/><color rgb="' . $color . '"/><name val="' . self::FONT . '"/><family val="2"/></font>';
		};
		$fill = function ( $color ) {
			return '<fill><patternFill patternType="solid"><fgColor rgb="' . $color . '"/><bgColor indexed="64"/></patternFill></fill>';
		};
		$line = '<left style="thin"><color rgb="FFD9D9DE"/></left><right style="thin"><color rgb="FFD9D9DE"/></right><top style="thin"><color rgb="FFD9D9DE"/></top><bottom style="thin"><color rgb="FFD9D9DE"/></bottom>';
		$wrap = function ( $vertical ) {
			return '<alignment vertical="' . $vertical . '" wrapText="1" readingOrder="2"/>';
		};

		$fonts = array(
			$font( 11, $colors['dark'] ),          // 0: default.
			$font( 18, $colors['dark'], true ),    // 1: title.
			$font( 11, 'FF' . self::GRAY ),        // 2: subtitle.
			$font( 12, $colors['dark'], true ),    // 3: room.
			$font( 12, 'FFFFFFFF', true ),         // 4: day.
			$font( 11, 'FFA8A8AE' ),               // 5: free.
		);
		$fills = array(
			'<fill><patternFill patternType="none"/></fill>',
			'<fill><patternFill patternType="gray125"/></fill>',
			$fill( $colors['dark'] ),              // 2: day.
			$fill( 'FFF7F7F8' ),                   // 3: free.
		);
		$borders = array(
			'<border><left/><right/><top/><bottom/><diagonal/></border>',
			'<border><left/><right/><top/><bottom style="medium"><color rgb="' . $colors['sky'] . '"/></bottom><diagonal/></border>', // 1: under the room names.
			'<border>' . $line . '<diagonal/></border>',  // 2: table cells.
		);
		// Order must match the XF_* constants.
		$xfs = array(
			'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>',
			'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="center" readingOrder="2"/></xf>',
			'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="center" readingOrder="2"/></xf>',
			'<xf numFmtId="0" fontId="3" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1">' . $wrap( 'bottom' ) . '</xf>',
			'<xf numFmtId="0" fontId="4" fillId="2" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">' . $wrap( 'center' ) . '</xf>',
			'<xf numFmtId="0" fontId="0" fillId="0" borderId="2" xfId="0" applyBorder="1" applyAlignment="1">' . $wrap( 'top' ) . '</xf>',
			'<xf numFmtId="0" fontId="5" fillId="3" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">' . $wrap( 'top' ) . '</xf>',
		);

		return self::XML_HEADER
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="' . count( $fonts ) . '">' . implode( '', $fonts ) . '</fonts>'
			. '<fills count="' . count( $fills ) . '">' . implode( '', $fills ) . '</fills>'
			. '<borders count="' . count( $borders ) . '">' . implode( '', $borders ) . '</borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="' . count( $xfs ) . '">' . implode( '', $xfs ) . '</cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>';
	}

	/** @return string */
	private static function content_types_xml() {
		return self::XML_HEADER
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '</Types>';
	}

	/** @return string */
	private static function root_rels_xml() {
		return self::XML_HEADER
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
	}

	/** @return string */
	private static function workbook_xml() {
		return self::XML_HEADER
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<bookViews><workbookView/></bookViews>'
			. '<sheets><sheet name="' . self::esc( self::SHEET_NAME ) . '" sheetId="1" r:id="rId1"/></sheets>'
			. '</workbook>';
	}

	/** @return string */
	private static function workbook_rels_xml() {
		return self::XML_HEADER
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>';
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Escapes text for XML and drops what XML cannot hold (control characters, broken UTF-8),
	 * which Excel would otherwise report as a damaged file.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function esc( $text ) {
		$text = (string) $text;
		if ( ! preg_match( '//u', $text ) ) {
			$text = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $text, 'UTF-8', 'UTF-8' ) : (string) iconv( 'UTF-8', 'UTF-8//IGNORE', $text );
		}
		$text = (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text );
		return htmlspecialchars( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * "#df2950" → "FFDF2950". Anything else is returned as is when already ARGB, or black.
	 *
	 * @param string $color Color.
	 * @return string
	 */
	private static function argb( $color ) {
		$color = strtoupper( ltrim( trim( (string) $color ), '#' ) );
		if ( preg_match( '/^[0-9A-F]{3}$/', $color ) ) {
			$color = $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2];
		}
		if ( preg_match( '/^[0-9A-F]{6}$/', $color ) ) {
			return 'FF' . $color;
		}
		return preg_match( '/^[0-9A-F]{8}$/', $color ) ? $color : 'FF000000';
	}

	/**
	 * Minimal ZIP archive (deflated when zlib is available, stored otherwise).
	 *
	 * @param string[] $files Path inside the archive => contents.
	 * @return string
	 */
	public static function zip( array $files ) {
		$now     = getdate();
		$time    = ( $now['hours'] << 11 ) | ( $now['minutes'] << 5 ) | intdiv( $now['seconds'], 2 );
		$date    = ( max( 0, $now['year'] - 1980 ) << 9 ) | ( $now['mon'] << 5 ) | $now['mday'];
		$body    = '';
		$central = '';

		foreach ( $files as $name => $content ) {
			$name    = (string) $name;
			$content = (string) $content;
			$crc     = crc32( $content );
			$method  = 0;
			$packed  = $content;
			if ( function_exists( 'gzdeflate' ) ) {
				$deflated = gzdeflate( $content, 6 );
				if ( false !== $deflated && strlen( $deflated ) < strlen( $content ) ) {
					$method = 8;
					$packed = $deflated;
				}
			}
			$offset = strlen( $body );
			$sizes  = pack( 'VVV', $crc, strlen( $packed ), strlen( $content ) );

			$body    .= pack( 'Vvvvvv', 0x04034b50, 20, 0, $method, $time, $date ) . $sizes . pack( 'vv', strlen( $name ), 0 ) . $name . $packed;
			$central .= pack( 'Vvvvvvv', 0x02014b50, 20, 20, 0, $method, $time, $date ) . $sizes . pack( 'vvvvvVV', strlen( $name ), 0, 0, 0, 0, 0, $offset ) . $name;
		}

		return $body . $central . pack( 'VvvvvVVv', 0x06054b50, 0, 0, count( $files ), count( $files ), strlen( $central ), strlen( $body ), 0 );
	}
}
