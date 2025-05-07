<?php
if (!defined('MEDIAWIKI'))
	die("MediaWiki extensions cannot be run directly.");
if (!defined('WikiDB')) {
	die("The WikiDB extension has not been set up correctly.<br>'"
		. basename(__FILE__) . "' should not be included directly.<br>Instead, "
		. "add the following to LocalSettings.php: "
		. "<tt><b>include_once(\"WikiDB/WikiDB.php\");</b></tt>");
}
/**
 * Messages used in the WikiDB extension.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2439 $
 */

$messages = array();

$messages['en'] = array(
	// Extension description
		'wikidb-description'		=> "A wiki-based database system",

	// General purpose terms (unprefixed so they are generally available).
	// This is the kind of thing that may be added to MediaWiki one day, and we
	// don't need multiple occurrences of it in the system message store.
		'undefined'		=> "undefined",

	// WikiDB general interface messages
		'nstab-wikidb-tabledef'		=> "Table definition",
		'nstab-wikidb-tabledata'	=> "Data ($1 {{PLURAL: $1|record|records}})",

	// Edit form - tables used (based on the structure for 'templatesused' in the
	// MediaWiki core).
		'wikidb-tablesused'				=> "Table data defined on this page:",
		'wikidb-tablesusedpreview'		=> "Table data defined in this preview:",
		'wikidb-tablesusedsection'		=> "Table data defined in this section:",
		'wikidb-tablesused-rowcount'	=> "$1 {{PLURAL: $1|record|records}}",

	// Table definition page
		'wikidb-tabledef-sourceheading'		=> "Source",
		'wikidb-tabledef-aliasesheading'	=> "Aliases",
		'wikidb-tabledef-definitionheading'	=> "Definition",

		'wikidb-tabledef-field'			=> "Field",
		'wikidb-tabledef-type'			=> "Type",
		'wikidb-tabledef-foreignkey'	=> "Foreign Key",
		'wikidb-tabledef-comment'		=> "Comment",
		'wikidb-tabledef-alias'			=> "Alias",

		'wikidb-tabledef-nofields'	=> "No fields defined.",

		'wikidb-tabledef-untyped'	=> "untyped",
		'wikidb-tabledef-badtype'	=> "unknown type: $1",

	// Data-related messages
		'wikidb-tabledata-heading'	=> "Viewing data for $1",
		'wikidb-tabledata-redirect'	=> "'''This table is a redirect.'''\n\n"
									   . "All data for this table ends up in $1.",
		'wikidb-nodata'				=> "(No records were found)",
		'wikidb-viewdata-record'	=> "$1 {{PLURAL: $1|record|records}} found.",
		'wikidb-missing-tablename'	=> "No table specified in $1 tag!",

		'wikidb-fields-meta'		=> "Meta-Data",
		'wikidb-fields-defined'		=> "Defined Fields",
		'wikidb-fields-undefined'	=> "Undefined Fields",
		'wikidb-fields-migrated'	=> "Migrated Fields",

	// Form fields.
		'wikidb-viewdata-options'	=> "Filter options",
		'wikidb-formfield-submit'	=> "Submit",
		'wikidb-formfield-criteria'	=> "Criteria:",
		'wikidb-formfield-order'	=> "Order:",

	// User-visible query errors.
		'wikidb-queryerror-unexpectedtoken'
							=> "Bad criteria: Unexpected token '$1'.",
		'wikidb-queryerror-unopenedparentheses'
							=> "Bad criteria: Unexpected closing parentheses.",
		'wikidb-queryerror-unclosedparentheses'
							=> "Bad criteria: Unclosed parentheses.",
		'wikidb-queryerror-partialexpression'
							=> "Bad criteria: Unfinished expression.",
		'wikidb-queryerror-badfieldname'
							=> "Bad criteria: Invalid field name '$1'.",
		'wikidb-queryerror-badtablename'
							=> "Bad criteria: Invalid table name '$1'.",
		'wikidb-queryerror-ambiguousfieldname'
							=> "Ambiguous fields in WHERE clause.",
		'wikidb-queryerror-undefinedtable'
							=> "Undefined table '$1' in WHERE clause.",
		'wikidb-queryerror-badfieldsyntax'
							=> "Invalid field syntax: $1",
		'wikidb-queryerror-multitablequery'
							=> "Currently only queries involving a single table "
							 . "are supported.",
		'wikidb-queryerror-freetextonly' =>
							"Kindly use the above search bar for free text search. Use this search only with conditions.",

		'wikidb-queryerror-expression'	=> "expression",

	// Messages for <guesstypes> tag.
		'wikidb-guesstypes-description'	=> "Description",
		'wikidb-guesstypes-original'	=> "Original value",
		'wikidb-guesstypes-typed'		=> "Typed value",
		'wikidb-guesstypes-formatted'	=> "Formatted for sorting",

		'wikidb-guesstypes-formatted-note'
				=> "Spaces are currently collapsed, for ease of reading.  Check "
				   . "source for full string.",

		'wikidb-guesstypes-rendered'	=> "Rendered",
		'wikidb-guesstypes-unrendered'	=> "Unrendered",

	// Messages for special page 'UndefinedTables'
		'undefinedtables' 					=> "Undefined tables",
		'undefinedtables-header'
				=> "'''This page lists all undefined tables on the "
				   . "wiki.'''<br>These tables contain data, but have no "
				   . "definition, and are ordered by the number of rows of data "
				   . "they contain.",
		'undefinedtables-none' 				=> "No entries were found.",
		'undefinedtables-record' 			=> "$1 {{PLURAL: $1|record|records}}",

	// Messages for special page 'EmptyTables'
		'emptytables' 					=> "Empty tables",
		'emptytables-header'
					=> "'''This page lists all empty tables on the "
					   . "wiki.'''<br>These tables have a definition but contain "
					   . "no data.",
		'emptytables-none' 				=> "No entries were found.",

	// Messages for special page 'AllTables'
		'alltables' 					=> "All defined tables",
		'alltables-header'
					=> "'''This page lists all tables that have a "
					 . "definition on the wiki, ordered alphabetically.'''",
		'alltables-none' 				=> "No entries were found.",
		'alltables-record' 				=> "$1 {{PLURAL: $1|record|records}}",

);
