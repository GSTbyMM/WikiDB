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
 * WikiDB HTMLRenderer class.
 * A set of static functions used to output table data to the wiki.
 * All functions return HTML text ready for directly outputting to the browser.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2439 $
 */

class WikiDB_HTMLRenderer {

//////////////////////////////////////////////////////////////////
// TABLE DEFINITIONS

// FormatTableDefinitionPage()
// Formats the supplied data ready for output on a table definition page.  If $Input
// is a string, it treats it as the page's wikitext and parses it using
// ParseTableDef().
// If $Input is an array, it assumes it has already been parsed using ParseTableDef()
// and so skips that step.
// In either case, $Title must be specified, to indicate the page that the input
// comes from, as this is needed for rendering certain properties.
// The result is the modified wikitext, which renders the individual field
// definitions in a special format, leaving other wikitext unmodified.
	static function FormatTableDefinitionPage($Title, $Input) {
		if (!is_array($Input))
			$Input = WikiDB_Parser::ParseTableDef($Input);

		$Output = "";
		foreach ($Input as $Key => $Value) {
			if (is_array($Value))
				$Output .= self::pFormatTableDefinitionPageLine($Title, $Value);
			else
				$Output .= $Value;
		}

		return $Output;
	}

// pFormatTableDefinitionPageLine()
// Internal function to format a field definition line on a table definition page.
// The input contains the parsed data for this line, as defined in the comments for
// WikiDB_Parser::pParseFieldDef().
	static function pFormatTableDefinitionPageLine($Title, $FieldDef) {
		if (isset($FieldDef['Alias'])) {
			$Result = "'''" . WikiDB_Msg('wikidb-tabledef-alias') . ":''' "
					. $FieldDef['FieldName'] . " &rarr; "
					. $FieldDef['Alias'];
		}

		else {
			if (isset($FieldDef['ForeignTable'])) {
				$Result = "'''" . WikiDB_Msg('wikidb-tabledef-foreignkey') . ":''' "
						. $FieldDef['FieldName'];
			}
			else {
				$Result = "'''" . WikiDB_Msg('wikidb-tabledef-field') . ":''' "
						. $FieldDef['FieldName'];
			}

			$Result .= " (";

			if (!isset($FieldDef['Type']))
				$FieldDef['Type'] = "";
			$Result .= WikiDB_TypeHandler::GetTypeNameForDisplay($FieldDef['Type']);

			if (isset($FieldDef['Options'])) {
				$Result .= "," . implode(",", $FieldDef['Options']);
			}

			$Result .= ")";

			if (isset($FieldDef['ForeignTable'])) {
				$Result .= " -> "
						 . self::pFormatForeignTable($Title,
													 $FieldDef['ForeignTable'],
													 $FieldDef['ForeignField']);
			}
		}
		if (isset($FieldDef['Comment']))
			$Result .= "\n ''" . $FieldDef['Comment'] . "''";

		$Result = " " . trim($Result) . "\n";

		return $Result;
	}

// FormatTableDefinitionSummary()
// Takes an array of field definitions, as returned from
// WikiDB_Parser::ParseTableDef(), and returns an HTML table summarising the defined
// fields.
	static function FormatTableDefinitionSummary($Title, $Fields) {
		$DefinedFields = array();
		$Aliases = array();

		$TableData = array();
		$HasComments = false;
		$HasForeignKeys = false;
		$AliasesHaveComments = false;

		foreach ($Fields as $Field) {
		// Skip over any non-field lines.
			if (!is_array($Field))
				continue;

			if (isset($Field['Alias'])) {
				$Aliases[$Field['FieldName']] = $Field['Alias'];
				if (isset($Field['Comment']))
					$AliasesHaveComments = true;
			}
			else {
				$TableRow = array();

				$TableRow['FieldName'] = $Field['FieldName'];

				if (isset($Field['Type'])
					&& WikiDB_TypeHandler::TypeDefined($Field['Type']))
				{
					$TableRow['Type']
						= WikiDB_TypeHandler::GetTypeNameForDisplay($Field['Type']);
					if (isset($Field['Options'])
						&& count($Field['Options']) > 0)
					{
						$TableRow['Type']
								.= " (" . implode(",", $Field['Options']) . ")";
					}
				}
				else {
					$TableRow['Type'] = "";
				}

				if (isset($Field['ForeignTable'])) {
					$TableRow['ForeignKey']
							= self::pFormatForeignTable($Title,
														$Field['ForeignTable'],
														$Field['ForeignField']);
					$HasForeignKeys = true;
				}
				else {
					$TableRow['ForeignKey'] = "";
				}

				if (isset($Field['Comment'])) {
					$TableRow['Comment'] = $Field['Comment'];
					$HasComments = true;
				}
				else {
					$TableRow['Comment'] = "";
				}

				$TableData[] = $TableRow;
				$DefinedFields[] = $Field['FieldName'];
			}
		}

		$Colspan = 2;
		$Definition = self::pStartTable("WikiDBFieldDefinitions")
					. self::pStartRow()
					. self::pHeaderCell(WikiDB_Msg('wikidb-tabledef-field'))
					. self::pHeaderCell(WikiDB_Msg('wikidb-tabledef-type'));
		if ($HasForeignKeys) {
			$Colspan++;
			$Definition
					.= self::pHeaderCell(WikiDB_Msg('wikidb-tabledef-foreignkey'));
		}
		if ($HasComments) {
			$Colspan++;
			$Definition .= self::pHeaderCell(WikiDB_Msg('wikidb-tabledef-comment'));
		}
		$Definition .= self::pEndRow();

		if (count($TableData) > 0) {
			foreach ($TableData as $TableRow) {
				$Definition .= self::pStartRow();
				$Definition .= self::pDataCell($TableRow['FieldName']);
				$Definition .= self::pDataCell($TableRow['Type']);
				if ($HasForeignKeys) {
					$Definition .= self::pDataCell($TableRow['ForeignKey']);
				}
				if ($HasComments) {
					$Definition .= self::pDataCell($TableRow['Comment']);
				}
				$Definition .= self::pEndRow();
			}
		}
		else {
			$Msg = '<i>' . WikiDB_Msg('wikidb-tabledef-nofields') . '</i>';

			$Definition .= self::pStartRow();
			$Definition .= self::pDataCell($Msg, "", $Colspan);
			$Definition .= self::pEndRow();
		}

		$Definition .= self::pEndTable();

		if (count($Aliases) > 0) {
			$Definition .= "\n=== " . WikiDB_Msg('wikidb-tabledef-aliasesheading')
						 . " ===\n";
			$Definition .= self::pStartTable("WikiDBAliasDefinitions");
			$Definition .= self::pStartRow()
						 . self::pHeaderCell(WikiDB_Msg('wikidb-tabledef-field'))
						 . self::pHeaderCell(WikiDB_Msg('wikidb-tabledef-alias'));
			if ($AliasesHaveComments) {
				$Definition
						.= self::pHeaderCell(WikiDB_Msg('wikidb-tabledef-comment'));
			}
			$Definition .= self::pEndRow();
			foreach ($Fields as $Field) {
				if (is_array($Field) && isset($Field['Alias'])) {
					$Definition .= self::pStartRow();
					$Definition .= self::pDataCell($Field['FieldName']);
					$Definition .= self::pDataCell($Field['Alias']);
					if ($AliasesHaveComments) {
						if (isset($Field['Comment'])) {
							$Definition .= self::pDataCell($Field['Comment']);
						}
						else {
							$Definition .= self::pDataCell("");
						}
					}
					$Definition .= self::pEndRow();
				}
			}
			$Definition .= self::pEndTable();
		}

		return $Definition;
	}

// pFormatForeignTable()
// Helper function to format the foreign table as a clickable link.
	static function pFormatForeignTable($Title, $ForeignTableName,
										$ForeignFieldName)
	{
		$objForeignTable = new WikiDB_Table($ForeignTableName,
											$Title->getNamespace());

		if ($objForeignTable->GetNamespace() == $Title->getNamespace())
			$DisplayName = $objForeignTable->GetName();
		else
			$DisplayName = $objForeignTable->GetFullName();

		$Result = "[[" . $objForeignTable->GetFullName() . "|"
				. $DisplayName . "#" . $ForeignFieldName
				. "]]";

		return $Result;
	}

//////////////////////////////////////////////////////////////////
// TABLE DATA

// FormatTableData()
// Formats the supplied WikiDB_QueryResult data using our default styling.
// Called from:
// * The 'data' tab in the table namespace.
// * The <repeat> tag.
// * <data> tag parser.
	static function FormatTableData($objResult, $ShowExtraInfo = false) {
		$Output = "";

		$RowCount = $objResult->CountRows();

		$MetaDataFields = $objResult->GetMetaDataFields();
		$DefinedFields = $objResult->GetDefinedFields();
		$UndefinedFields = $objResult->GetUndefinedFields();

	// If there are no data rows, and the table doesn't have any fields defined,
	// output nothing (rather than a table with no rows or columns defined).
	// TODO: Or we could output an error message, e.g. "No data defined."
		if ($RowCount == 0 && count($DefinedFields) == 0)
			return $Output;

	// Set the style for undefined field cells (both headers and data).
	// If all extra info is being shown (so the column groups are displayed), or if
	// there are no fields defined for this table at all, then no special styling
	// is used, but in other cases we colour them grey to differentiate them from the
	// defined fields.
		if (count($DefinedFields) > 0 && !$ShowExtraInfo)
			$UndefinedFieldStyle = "color: #999999;";
		else
			$UndefinedFieldStyle = "";

		$Output .= self::pStartTable("WikiDBDataTable");
		if ($ShowExtraInfo) {
			$Output .= self::pStartRow();
		// Note: We assume there will be at least two meta-data fields,
		//		 with the first one being the RowID.  I can't see this changing, but
		//		 if it does then this cell layout will need reworking.
			$Output .= self::pHeaderCell("");
			$Output .= self::pHeaderCell(WikiDB_Msg('wikidb-fields-meta'), "",
										 count($MetaDataFields) - 1);
			if (count($DefinedFields) > 0) {
				$Output .= self::pHeaderCell(WikiDB_Msg('wikidb-fields-defined'), "",
											 count($DefinedFields));
			}
			if ($objResult->HasMigratedFields()) {
				$Output .= self::pHeaderCell(WikiDB_Msg('wikidb-fields-migrated'),
											 "", "", 2);
			}
			if (count($UndefinedFields) > 0) {
				$Output .= self::pHeaderCell(WikiDB_Msg('wikidb-fields-undefined'),
											 "", count($UndefinedFields));
			}
			$Output .= self::pEndRow();
		}

		$Output .= self::pStartRow();
		if ($ShowExtraInfo) {
			foreach ($MetaDataFields as $FieldName) {
				$FieldName = WikiDB_EscapeHTML(substr($FieldName, 1));
				$Output .= self::pHeaderCell($FieldName);
			}
		}

		foreach ($DefinedFields as $FieldName) {
			$FieldName = WikiDB_EscapeHTML($FieldName);
			$Output .= self::pHeaderCell($FieldName);
		}

		foreach ($UndefinedFields as $FieldName) {
			$FieldName = WikiDB_EscapeHTML($FieldName);
			$Output .= self::pHeaderCell($FieldName, $UndefinedFieldStyle);
		}
		$Output .= self::pEndRow();

		for ($Row = 0; $Row < $RowCount; $Row++) {
			$Output .= self::pStartRow();

			if ($ShowExtraInfo) {
				$arrData = $objResult->GetMetaDataFieldValues($Row);
				foreach ($arrData as $FieldValue) {
					$Output .= self::pDataCell($FieldValue);
				}
			}

			$arrData = $objResult->GetDefinedFieldValues($Row);
			foreach ($arrData as $FieldValue) {
				$Output .= self::pDataCell($FieldValue);
			}

			if ($objResult->HasMigratedFields() && $ShowExtraInfo) {
				$arrAliases = $objResult->GetMigratedFields($Row);

				$AliasString = "";
				foreach ($arrAliases as $FieldName => $Alias) {
					if ($AliasString != "")
						$AliasString .= '<br>';
					$AliasString .= WikiDB_EscapeHTML($FieldName)
								  . "&nbsp;&rarr;&nbsp;"
								  . WikiDB_EscapeHTML($Alias)
								  . "\n";
				}

				$Output .= self::pDataCell($AliasString);
			}

			$arrData = $objResult->GetUndefinedFieldValues($Row);
			foreach ($arrData as $FieldValue) {
				$Output .= self::pDataCell($FieldValue, $UndefinedFieldStyle);
			}

			$Output .= self::pEndRow();
		}
		$Output .= self::pEndTable();

		return $Output;
	}

// TemplateFormatTableData()
// Formats the supplied WikiDB_QueryResult using the specified templates.
	static function TemplateFormatTableData($objResult, $BodyTemplate,
											$HeaderTemplate = "",
											$FooterTemplate = "")
	{
		$Output = "";

	// If a header template is specified, include that.
		if ($HeaderTemplate != "")
			$Output .= "{{" . $HeaderTemplate . "}}";

	// Include the body, once for each row of data.
		$RowCount = $objResult->CountRows();
		for ($Row = 0; $Row < $RowCount; $Row++) {
		// Get the field values for this row.
		// TODO: Currently, any multi-field values are collapsed by GetFieldValues()
		//		 into a single string.  Is this the correct behaviour in this
		//		 scenario, or should we (can we, even?) pass the individual values
		//		 into the template?
			$RowData = $objResult->GetFieldValues($Row);

		// The _SourceArticle meta-data field is a wiki link (e.g. "[[Article]]"),
		// whereas when I originally wrote this code we output it as just the name
		// (i.e. "Article", without the square brackets).
		// I'm not sure what the correct behaviour should be, but until I decide we
		// replace this value with the raw value so the old behaviour is retained.
		// TODO: Resolve this issue.
			$RowData['_SourceArticle']
							= $objResult->GetRawFieldValue($Row, '_SourceArticle');

			$Output .= "{{" . $BodyTemplate;
			foreach ($RowData as $FieldName => $FieldValue) {
				$Output .= "|" . $FieldName . "=" . $FieldValue;
			}
			$Output .= "}}";
		}

	// If a footer template is specified, include that.
		if ($FooterTemplate != "")
			$Output .= "{{" . $FooterTemplate . "}}";

		return $Output;
	}

// CustomFormatTableData()
// Formats the supplied WikiDB_QueryResult using the specified wikitext as template
// text.
	static function CustomFormatTableData($objResult, $Parser, $Frame, $Body,
										  $Header = "", $Footer = "")
	{
		$Output = "";

		$RowCount = $objResult->CountRows();
		for ($Row = 0; $Row < $RowCount; $Row++) {
		// Get the normalised data for this row.
		// TODO: Currently, any multi-field values are collapsed by
		//		 GetNormalisedRow() into a single string.  Is this the correct
		//		 behaviour in this scenario, or should we (can we, even?) pass the
		//		 individual values into the template?
			$arrData = $objResult->GetNormalisedRow($Row);
			$Output .= pWikiDB_ExpandVariables($Body, $arrData, $Parser, $Frame);
		}

	// HACK: Remove the 'edit section' links, which get rendered incorrectly
	//		 and which wouldn't work even if they rendered fine.
	// Note: I tried adding "__NOEDITSECTION__" to the body before parsing, but
	//		 this doesn't have any effect.
	// TODO: Is there a way of disabling this behaviour when parsing, instead
	//		 of having to hack the result like this?  I couldn't find a way, but
	//		 this bit of MediaWiki doesn't seem to be very well documented...
	// Note that the regex is different depending on the MW version.  Later
	// versions use a marker which is expanded after this point, whilst earlier
	// versions have already done the full HTML replacement by this stage.
	// @back-compat MW < 1.18
		if (version_compare(MW_VERSION, "1.18", "<")) {
			$Regex = '/<span class="editsection">\[<a href="[^"]*" '
				   . 'title="[^"]*">[^<]*<\/a>\]<\/span>\s*/U';
		}
		else {
			$Regex = '/<mw:editsection page="[^"]*" section="[^"]*">'
				   . '.*<\/mw:editsection>/U';
		}
		$Output = preg_replace($Regex, "", $Output);

	// Add any header/footer that was specified.
		$Output = $Header . $Output . $Footer;

		return $Output;
	}

// CollapseFieldValue()
// Takes a field value, which may either be a single value (as a string) or a
// multi-field value (as an array) and converts them into a single value, suitable
// for output.
	static function CollapseFieldValue($Value) {
		if (is_array($Value)) {
			$Value = implode(" &bull; ", $Value);
		}

		return $Value;
	}

//////////////////////////////////////////////////////////////////
// GUESS-TYPES TAG

// FormatGuessTypesOutput()
// Takes an array of <guesstypes> data, as generated by
// WikiDB_Parser::ParseGuessTypesTag() and returns the HTML to render the results of
// the tests as a table.  See efWikiDB_GuessTypes() for details about the
// output format.
// TODO: Use CSS classes rather than in-line styling.
	static function FormatGuessTypesOutput($FormattedLines, $Parser, $Frame = null) {
		$Output = "";

	// If the input contained any valid lines, then output the table.
		if (count($FormattedLines) > 0) {
		// Open table tag:
			$Output .= self::pStartTable("WikiDBGuessTypesOutput");

		// Header rows:
			$FormattedValueMsg = WikiDB_Msg('wikidb-guesstypes-formatted')
							   . "<br><small>"
							   . WikiDB_Msg('wikidb-guesstypes-formatted-note')
							   . "</small>";

			$Output .= self::pStartRow();
			$Output .= self::pHeaderCell(WikiDB_Msg('wikidb-guesstypes-description'),
										 "", "", 2);
			$Output .= self::pHeaderCell(WikiDB_Msg('wikidb-guesstypes-original'),
										 "", 2);
			$Output .= self::pHeaderCell(WikiDB_Msg('wikidb-tabledef-type'),
										 "", "", 2);
			$Output .= self::pHeaderCell(WikiDB_Msg('wikidb-guesstypes-typed'),
										 "", 2);
			$Output .= self::pHeaderCell($FormattedValueMsg, "", "", 2);
			$Output .= self::pEndRow();

			$Output .= self::pStartRow();
			$Output .= self::pHeaderCell(WikiDB_Msg('wikidb-guesstypes-unrendered'));
			$Output .= self::pHeaderCell(WikiDB_Msg('wikidb-guesstypes-rendered'));
			$Output .= self::pHeaderCell(WikiDB_Msg('wikidb-guesstypes-unrendered'));
			$Output .= self::pHeaderCell(WikiDB_Msg('wikidb-guesstypes-rendered'));
			$Output .= self::pEndRow();

		// One row per valid line:
			foreach ($FormattedLines as $Line) {
				$Output .= self::pStartRow();

			// Description of this item
				$Value = WikiDB_Parse($Line['Description'], $Parser, $Frame);
				$Output .= self::pDataCell($Value);

			// Original value: unrendered
				$Value = WikiDB_EscapeHTML($Line['Data']);
				$Output .= self::pDataCell($Value);

			// Original value: rendered
				$Value = WikiDB_Parse($Line['Data'], $Parser, $Frame);
				$Output .= self::pDataCell($Value);

			// Guessed type (highlighted in green or red to indicate whether the
			// result was correct or not, if an expected type was given.
				if (isset($Line['ExpectedType'])) {
					$Style = self::pStyleCorrectness($Line['ExpectedType'],
													 $Line['Type']);
				}
				else {
					$Style = "";
				}
				$Output .= self::pDataCell($Line['Type'], $Style);

			// Typed value: unrendered
			// Highlighted in green or red to indicate whether the
			// result was correct or not, if an expected output was given.
				if (isset($Line['ExpectedOutput'])) {
					$Style = self::pStyleCorrectness($Line['ExpectedOutput'],
													 $Line['FormattedData']);
				}
				else {
					$Style = "";
				}
				$Value = WikiDB_EscapeHTML($Line['FormattedData']);
				$Output .= self::pDataCell($Value, $Style);

			// Typed value: rendered
				$Value = WikiDB_Parse($Line['FormattedData'], $Parser, $Frame);
				$Output .= self::pDataCell($Value);

			// Stored value:
				$Value = "'''['''<code><nowiki>"
					   . $Line['SortFormat']
					   . "</nowiki></code>''']'''";
				$Value = WikiDB_Parse($Value, $Parser, $Frame);
				$Output .= self::pDataCell($Value);

				$Output .= self::pEndRow();
			}

		// Close table tag:
			$Output .= self::pEndTable();
		}

		return $Output;
	}

// pStyleCorrectness()
// Returns a style string depending on whether the two items are equal or not.
	static function pStyleCorrectness($Val1, $Val2) {
		$Style = "background-color: ";
		if ($Val1 == $Val2)
			$Style .= "#D0FFD0";
		else
			$Style .= "#FFD0D0";
		$Style .= ';';

		return $Style;
	}

//////////////////////////////////////////////////////////////////
// HTML OUTPUT FUNCTIONS

// pStartTable()
// Opens a <table> tag to output WikiDB information in tabular format.
// All tables have the class "WikiDBTable" plus whatever class string has been
// defined for the wiki in $wgWikiDBDefaultClasses.
// In addition, each type of table should include an appropriate extra class via
// the $Class argument, which helps indicate the purpose of the table, e.g. whether
// it is outputting data, field definitions, aliases, etc.
	static function pStartTable($Class = "") {
		global $wgWikiDBDefaultClasses;

		$ClassList = "WikiDBTable";
		if (!IsBlank($Class))
			$ClassList .= " " . $Class;
		if (!IsBlank($wgWikiDBDefaultClasses))
			$ClassList .= " " . $wgWikiDBDefaultClasses;

		return '<table class="' . $ClassList . '">' . "\n";
	}

// pEndTable()
// Closes off a <table> tag.
	static function pEndTable() {
		return "</table>\n";
	}

// pStartRow() / pEndRow()
// Starts/ends a table row (<tr> tag).

	static function pStartRow() {
		return "\t<tr>\n";
	}

	static function pEndRow() {
		return "\t</tr>\n";
	}

// pHeaderCell()
// Helper function, to output a <th> tag.
// See pTableCell() for details.
	static function pHeaderCell($Value, $Style = "", $Colspan = "", $Rowspan = "") {
		return self::pTableCell("th", $Value, $Style, $Colspan, $Rowspan);
	}

// pDataCell()
// Helper function, to output a <td> tag.
// See pTableCell() for details.
	static function pDataCell($Value, $Style = "", $Colspan = "", $Rowspan = "") {
		return self::pTableCell("td", $Value, $Style, $Colspan, $Rowspan);
	}

// pTableCell()
// Helper function used by pHeaderCell() and pDataCell(), as both of these functions
// are identical apart from the tag name that they output (<th> or <td>,
// respectively).
// $TagName is the element type, $Value is the contents of the cell, and the other
// arguments provide the contents of some optional attributes on the element, which
// are only output if non-blank.  If $Value is empty after a trim(), then a
// non-breaking-space is output, to stop the cell collapsing to nothing.
// White-space (indentation and new-lines) are output to aid readability in the HTML
// source, which is most useful for debugging purposes.
// Should not be called except from within those two wrapper functions, which is why
// none of the arguments are optional.
	static function pTableCell($TagName, $Value, $Style, $Colspan, $Rowspan) {
		if (!IsBlank($Colspan))
			$Colspan = ' colspan="' . $Colspan . '"';

		if (!IsBlank($Rowspan))
			$Rowspan = ' rowspan="' . $Rowspan . '"';

		if (!IsBlank($Style))
			$Style = ' style="' . $Style . '"';

	// If value is blank, we output a non-breaking-space so that the cell doesn't
	// collapse to nothing.
		if (IsBlank(trim($Value)))
			$Value = "&nbsp;";

		return "\t\t<" . $TagName . $Colspan . $Rowspan . $Style . ">"
			   . $Value
			   . "</" . $TagName . ">\n";
	}

} // END: class WikiDB_HTMLRenderer
