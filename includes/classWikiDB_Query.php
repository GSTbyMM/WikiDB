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
 * Class to handle queries of the WikiDB.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2439 $
 */

class WikiDB_Query {
	var $pDB;
	var $pErrorMsg;

	var $pTables = array();
	var $pSortFields = array();
	var $pCriteria = array();
	var $pSourceArticle = "";

	var $pSQL = "";
	var $pResult;

/////////////// TOKEN CONSTANTS ///////////////

	const TOKEN_UnquotedLiteral		= 0;

	const TOKEN_String				= 1;
	const TOKEN_Identifier			= 2;

	const TOKEN_LeftParenthesis		= 3;
	const TOKEN_RightParenthesis	= 4;
	const TOKEN_Conjunction			= 5;
	const TOKEN_Operator			= 6;

/////////////// CONSTRUCTOR/DESTRUCTOR ///////////////

// Constructor.
// Takes a table string, and optional criteria and sort strings, which are used to
// set up the query object ready for running.
// Alternatively, $TableString may be a single WikiDB_Table object.
// When the object has been initialised, call the GetRows() function to perform the
// actual query and return the full set of results.
// Note that if you need to restrict the results to a particular source article then
// currently this needs to be passed in as a separate argument.  Ideally we would
// simply recognise _SourceArticle as a valid field name in the criteria string, but
// this is quite hard (see TODO note).  Therefore, this method is provided as a
// workaround until a more holistic solution is found (if, indeed, it is possible at
// all).
// TODO: Allow meta-data fields in the query string.  This is hard because it
//		 not only requires that we recognise them when parsing the query string (not
//		 very hard), but that we recognise what we're comparing them to (shouldn't
//		 be hard but current process doesn't make it as easy as it could be) and
//		 converting the other operand to a normalised pair of NS/DBKey values (quite
//		 difficult with literals, probably impossible with stored data).
	function __construct($TableString, $CriteriaString = "", $SortString = "",
						 $SourceArticle = "")
	{
		$this->pDB = wfGetDB(DB_REPLICA);
		$this->pErrorMsg = "";

	// For simplicity, if a Table object is passed in, we just extract the table
	// name and use this as the table string.  I'm sure we could improve performance
	// by using the supplied table object rather than extracting the name, parsing
	// it and creating a new table object, but that would require a bit more work.
	// TODO: Optimise this as described above.
		$TableClass = "WikiDB_Table";
		if (is_object($TableString) && $TableString instanceof $TableClass) {
			$TableString = $TableString->GetFullName();
		}

		if (!$this->pSetTablesFromString($TableString))
			return;
		if (!$this->pSetCriteriaFromString($CriteriaString))
			return;
		if (!$this->pSetSortFromString($SortString))
			return;

		$this->pSourceArticle = $SourceArticle;

		$this->pPerformQuery();
	}

// Destructor.
// Frees the database result, if this was created.
	function __destruct() {
		if (isset($this->pResult)) {
			$this->pResult->free();
		}
	}

/////////////// PUBLIC FUNCTIONS ///////////////

// HasErrors()
// Returns true if the query had errors, or false otherwise.
	function HasErrors() {
		if ($this->pErrorMsg == "")
			return false;
		else
			return true;
	}

// GetErrorMessage()
// Returns the error message that was generated if the query contained errors.
// Returns an empty string if no error occurred.
	function GetErrorMessage() {
		return $this->pErrorMsg;
	}

// GetTable()
// Returns the WikiDB_Table object used for this query.  If the table string was
// invalid, so no Table was specified, then null is returned.
// TODO: Currently only single-table queries are allowed, so this is fine, but this
//		 will need to be removed/replaced when we allow for join expressions.
	function GetTable() {
		$Result = null;

		if (count($this->pTables) == 1) {
			reset($this->pTables);
			$arrTableInfo = current($this->pTables);
			$Result = $arrTableInfo['table'];
		}

		return $Result;
	}

// GetRows()
// Runs the defined query and returns an array containing the results, one item per
// row, indexed numerically.
// By default, all rows are returned, but the $Offset and $Limit arguments can be
// used to restrict this to a narrower range.  $Offset is the row offset, indexed
// from zero, and $Limit is the maximum number of records to be returned (use null
// for no limit).  Note that the result may contain fewer rows than specified by
// $Limit, if there are fewer rows available.
// If an error occurs, the results array will be empty.
	function GetRows($Offset = 0, $Limit = null) {
	// Initialise some variables.  $RowData is the results array that will be
	// returned, whilst $RowID is the current row number.  As we want the index
	// to be the actual row number from the data, we initialise it to the specified
	// start row, rather than always counting from zero.
		$RowData = array();
		$RowID = 0;

	// Only pull out data if there is some (i.e. there were no errors, and the
	// row-count is non-zero).
		if (!$this->HasErrors() && $this->pResult->numRows() > 0) {
		// Seek to the position we are looking for.  We do this every time, even when
		// $Offset is zero, in case this function has already been called and we are
		// no longer at the start of the record set.
			$this->pResult->seek($Offset);

		// Get the Table object, which is used as part of unserialisation.
		// We get it here so it doesn't need to be retrieved for each row.
			$Table = $this->GetTable();

		// Build the results array, an array with one element per data row.
		// For each element: 'Data' is an array containing FieldName => Value pairs
		// for each field defined in this row of data, and 'MetaData' is a similar
		// array containing meta-data for the row (these field names are always
		// preceded by an underscore).
		// We loop through the results until either there are no more rows, or we
		// reach the specified limit (if a limit has been set).
			while (($Limit === null || $Limit > $RowID)
					&& $Row = $this->pResult->fetchObject())
			{
			// Extract the data for this row.
				$RowData[$RowID]['Data'] = unserialize($Row->parsed_data);

			// Sort any multi-value fields.
				$this->pSortMultiValueFields($RowData[$RowID]['Data'], $Table);

			///////////////////
			// Add the meta-data.

			// Row number:
				$RowData[$RowID]['MetaData']['_Row'] = $RowID + $Offset + 1;

			// Source article (where data is defined):
				$SourceTitle = Title::newFromText($Row->page_title,
												  $Row->page_namespace);
				$RowData[$RowID]['MetaData']['_SourceArticle']
												= $SourceTitle->getFullText();

			// END: MetaData
			///////////////////

				$RowID++;
			}
		}

		return WikiDB_QueryResult::NewFromQuery($RowData, $Offset, $Limit, $this);
	}

// pSortMultiValueFields()
// Helper function to apply a sort to values in multi-value fields, if those fields
// are included in the sort criteria for the query.
// The data for the row is passed-in by reference and sorted in-place.
// The sort criteria are pulled from the object's $pSortFields property.
// TODO: This will need to be made more sophisticated once we allow multi-table
//		 queries.
	function pSortMultiValueFields(&$arrData, $objTable) {
	// Variable to track fields we have already used, to ensure multiple instances
	// of the same field are sorted correctly.
		$arrUsedFields = array();

	// Check each of the fields in the sort list to see if they exist in the data.
		foreach ($this->pSortFields as $arrSortInfo) {
			$Field = $arrSortInfo['field'];

		// The field needs sorting if (a) it is present in the data for this row;
		// (b) it is a multi-value field; and (c) it has not already been sorted.
			if (isset($arrData[$Field])
				&& is_array($arrData[$Field])
				&& !isset($arrAlreadySorted[$Field]))
			{
				$arrSortKeys = array();
				foreach ($arrData[$Field] as $Index => $Value) {
					$arrSortKeys[$Index]
								= $objTable->FormatFieldForSorting($Field, $Value);
				}

			// Sort according to direction.
			// Note that DB sorts are case-insensitive, so we set the appropriate
			// flag here, to ensure consistency.
			// TODO: Is there a native MediaWiki sort function that we should be
			//		 using in preference to the built-in PHP sort?
				if ($arrSortInfo['dir'] == "DESC")
					$Direction = SORT_DESC;
				else
					$Direction = SORT_ASC;

			// Set the sort flags to ensure proper ordering.
			// SORT_FLAG_CASE was only added in PHP 5.4, so on earlier versions we
			// omit the flag and a case-sensitive sort will be performed.  On PHP 5.4
			// and above, the sorting is case-insensitive.
			// @back-compat PHP < 5.4
				$SortFlags = SORT_STRING;
				if (version_compare(PHP_VERSION, "5.4", ">="))
					$SortFlags |= SORT_FLAG_CASE;

				array_multisort($arrSortKeys, $Direction, $SortFlags,
								$arrData[$Field]);

			// Set a flag so that we don't re-sort the field if there are multiple
			// instances of the field in the sort order.  If there are multiple, the
			// first instance should always win.
			// We use the field as the index, rather than the value, for a small
			// performance boost.
				$arrAlreadySorted[$Field] = true;
			}
		}
	}

// CountRows()
// Returns the total number of rows that were returned by the query.
// Will return zero if an error occurred.
	function CountRows() {
		if ($this->HasErrors())
			return 0;
		else
			return $this->pResult->numRows();
	}

// DebugGetSQL()
// Returns the SQL that was run, to aid debugging.
// Should never be exposed to users.
	function DebugGetSQL() {
		return $this->pSQL;
	}

/////////////// SETTING UP INPUTS ///////////////

// pSetTablesFromString()
// Takes a table string and sets up the array of tables used in this query.
// Currently a table string may only contain a single table.  In future we may
// allow multiple tables, or joins.
// Table aliases are resolved before we add the data to our array.
// The tables array is indexed by the full table name, and each element contains the
// necessary data pertaining to the given table (the table object, the name of the
// alias that will be used in the query, and an array of referenced fields, initially
// empty).
// TODO: Implement multi-table queries.
	function pSetTablesFromString($TableString) {
	// Create a table object for this table, resolving any redirects to get the
	// actual table that the data ends up in.
	// The query that we build will ensure that all aliases are also checked.
		$Table = new WikiDB_Table($TableString);
		if (!$Table->IsValidTable()) {
			$this->pErrorMsg = WikiDB_Msg('wikidb-queryerror-badtablename',
										  $TableString);
			return false;
		}

		$Table = $Table->GetDataDestination();

	// Get canonical name of table
		$TableName = $Table->GetFullName();

	// If this table is already set up, then we don't need to define it a second
	// time.
		if (isset($this->pTables[$TableName]))
			return true;

	// Create an alias for this table, which will be used to reference it within the
	// query (as the query may contain multiple WikiDB tables, each built from the
	// same set of DB tables).
	// The alias will be used as the name for this table's instance of the rowdata
	// table, and as a prefix to the field name when naming each instance of the
	// fielddata table that relates to this table.
	// We simply use a numeric counter for this, with the first table being called
	// "table1".
		$Alias = "table" . (count($this->pTables) + 1);

	// Create the entry for this table, initially with an empty field list.
	// The field list will be added to when we parse the other query parameters,
	// and will ultimately contain a list of all fields in this table that are
	// referenced by the query (and therefore which need to be included in the
	// JOIN expression).
		$this->pTables[$TableName]['table'] = $Table;
		$this->pTables[$TableName]['alias'] = $Alias;
		$this->pTables[$TableName]['fields'] = array();

	// Return true to indicate success.
		return true;
	}

// pSetCriteriaFromString()
// Parses the criteria string, and sets up an array of criteria data indicating
// the involved fields and the operator(s) that apply.
// This function is currently very crude, because the allowed syntax is very
// crude - this all needs expanding.
// TODO: Allow for multiple tables.
// TODO: Expand, expand, expand!
//
// The current criteria string supports multiple criteria, separated by logical
// operators, and optionally grouped by brackets to specify precedence.
//
//  * If brackets are not used, precedence is defined by the current database
//	  back-end.
//	* Whitespace is allowed in the criteria string, and is automatically removed.
//  * Supported operators: AND and OR.  These are case-insensitive.
//	* Comma is recognised as a synonym for AND, for historical reasons.
//
//	* Each criteria is of the form "FIELD OPERATOR VALUE", e.g. "Artist=Madonna".
//
//	* FIELD is of the form "TABLE.FIELDNAME", where "TABLE." is optional if there is
//	  only one table being used in the query.  If more than one table is being used,
//	  then it is required for all fields (not applicable currently).
//  * TABLE aliases are resolved properly.
//	* The resulting TABLE (after alias resolution) must match one of the tables
//    defined for the query.
//	* FIELDNAME does not have to be defined in the table, as undefined fields may
//    still contain data.
//  * If FIELDNAME is not syntactically valid then that particular criteria is
//	  ignored.
//	  TODO: Not true - currently the whole query fails!
//	* Field aliases are resolved properly if FIELDNAME exists in the table
//	  definition.
//
//	* OPERATOR is any of the valid operators listed in $ValidOperators.
//
//	* VALUE is the value for comparison, unquoted.  Currently fields cannot be
// 	  compared to each other.  Value is allowed to be blank.  Currently there is
//    no way to distinguish between "" (field is defined but had no value set)
//    and null (field was omitted from the <data> definition).
//
	function pSetCriteriaFromString($CriteriaString) {
	// Array defining how tokens may be ordered.
	// Each token has an array with three values:
	// * Boolean indicating whether token can start string.
	// * Boolean indicating whether token can end string.
	// * array of tokens indicating tokens that are allowed to come before this token
	//   type.
	// Note that there will not be any TOKEN_UnquotedLiteral in the tokens array,
	// so this doesn't need to be included in this array at all.
		$arrTokenOrdering = array(
			// Expressions must come at start of criteria, after an opening
			// bracket or after a conjunction.
			// Currently expressions are always of the form FieldName OP Value
			// which means that strings always follow operators and identifiers are
			// always the first part of the expression.  This will change in the
			// future.
				self::TOKEN_String => array(true, true, array(
							self::TOKEN_LeftParenthesis,
							self::TOKEN_Conjunction,
							self::TOKEN_Operator,
						)),
				self::TOKEN_Identifier => array(true, true, array(
							self::TOKEN_LeftParenthesis,
							self::TOKEN_Conjunction,
							self::TOKEN_Operator,
						)),

			// Opening bracket can come at the start of the expression, after
			// another opening bracket or after a conjunction.
				self::TOKEN_LeftParenthesis => array(true, false, array(
							self::TOKEN_LeftParenthesis,
							self::TOKEN_Conjunction,
						)),

			// Closing bracket must come after another closing bracket or after an
			// expression.  The rule for conjunctions is the same, but these can't
			// come at the end of the string.
				self::TOKEN_RightParenthesis => array(false, true, array(
							self::TOKEN_RightParenthesis,
							self::TOKEN_String,
							self::TOKEN_Identifier,
						)),
				self::TOKEN_Conjunction => array(false, false, array(
							self::TOKEN_RightParenthesis,
							self::TOKEN_String,
							self::TOKEN_Identifier,
						)),

			// An operator can only come after an identifier.
				self::TOKEN_Operator => array(false, false, array(
							self::TOKEN_String,
							self::TOKEN_Identifier,
						)),

			);

	// Split the string up into separate tokens.
		$arrTokens = $this->pTokeniseCriteria($CriteriaString);
	// If criteria ends up empty, then it is valid and we don't bother with our
	// other checks.
		if (count($arrTokens) == 0)
			return true;

	// Perform expansion of implicit tokens, which tidies the token stream so there
	// everything is explicit and fully defined.
		$arrTokens = $this->pExpandImplicitTokens($arrTokens);

	// Check the syntax is valid, i.e. that the token list matches the rules defined
	// in $arrTokenOrdering.
		$LastType = null;
		$BracketCount = 0;
		foreach ($arrTokens as $arrToken) {
			$TokenType = $arrToken[0];
			$Value = $arrToken[1];
			$arrRules = $arrTokenOrdering[$TokenType];

		// If this is the start of the string, check that the token is allowed to be
		// at the start of the string.
			if ($LastType === null && !$arrRules[0]) {
				$this->pErrorMsg = WikiDB_Msg('wikidb-queryerror-unexpectedtoken',
											 $Value);
				return false;
			}
		// Otherwise, check that this token is allowed to follow the preceding token.
			elseif ($LastType !== null && !in_array($LastType, $arrRules[2])) {
				$this->pErrorMsg = WikiDB_Msg('wikidb-queryerror-unexpectedtoken',
											  $Value);
				return false;
			}

		// We need to check that we have the right number of parentheses.
		// We increment/decrement the bracket count whenever we enter/leave a
		// parenthesised section, and throw an error if this ever goes below zero.
		// At the end of the string, we will check that the final result is zero,
		// i.e. that all brackets were closed.
			if ($TokenType == self::TOKEN_LeftParenthesis) {
				$BracketCount++;
			}
			elseif ($TokenType == self::TOKEN_RightParenthesis) {
				$BracketCount--;
				if ($BracketCount < 0) {
					$this->pErrorMsg
							= WikiDB_Msg('wikidb-queryerror-unopenedparentheses');
					return false;
				}
			}

			$LastType = $TokenType;
		}

	// The last item must be one that is allowed to end the expression.
		if ($LastType !== null && !$arrTokenOrdering[$LastType][1]) {
			$this->pErrorMsg = WikiDB_Msg('wikidb-queryerror-partialexpression');
			return false;
		}

	// If brackets don't match, report the error.
		if ($BracketCount != 0) {
			$this->pErrorMsg = WikiDB_Msg('wikidb-queryerror-unclosedparentheses');
			return false;
		}

	// If all validation passed, we do a final pass to tidy the operands, ready for
	// use in the query.
		$this->pCriteria = $this->pTidyOperands($arrTokens);

/*
// DEBUG:
		print('<pre style="z-index: 1000; position: relative; float: left; '
			  . 'clear: left;">');
		print($CriteriaString . "\n");
		print_r($this->pCriteria);
		print("</pre>");
/**/

	// Return true to indicate success.
		return true;
	}

	function pTokeniseCriteria($CriteriaString) {
		global $pRegexFindEndQuote, $pRegexUnescapeString;

	// Token patterns.
	// These are regex expressions which must match at the END of the current string.
	// Start the string with \b if it begins with alpha-numeric characters,
	// to indicate that it only appears after a word-boundary.
	// Remember to quote any characters that have a special meaning in regular
	// expressions.
	// All matches are case-insensitive.
	// To handle situations where one string is a subset of another (e.g. "=" and
	// "==") the shorter string should use a syntax that ensures it only matches if
	// the subsequent character(s) are not present, closing the existing capturing
	// expression and opening a new one at the appropriate point, e.g. instead of
	// "=" you would use "=)(.".  The tokeniser will automatically add the
	// additional captured text back onto the input for subsequent processing, as
	// well as handling the situation where this token is at the end of the string
	// (i.e. there is nothing to match the dot).  Note that identifiers consisting
	// only of letters will have this applied to them automatically, along with
	// automatic wrapping of the string in \b markers, to ensure that we match on
	// whole-words only.
	// To handle situations where there are sub-strings that are right-aligned (e.g.
	// "eq" and "neq", simply list the longer item first.
	// Note that single-character tokens must be at the end of the list, otherwise
	// they will take precedence over expressions that contain a lookahead, and
	// syntax such as "(X =) AND ..." won't work, as the ) would be matched before
	// the = sign and the second token would be "X =".
		$arrRecognisedTokens = array(
			// Operators.
				"<>"		=> self::TOKEN_Operator,	// not equal
				"!="		=> self::TOKEN_Operator,	// not equal (alt.)
				"<="		=> self::TOKEN_Operator,	// less than or equal
				"<)(."		=> self::TOKEN_Operator,	// less than
				">="		=> self::TOKEN_Operator,	// greater than or equal
				">)(."		=> self::TOKEN_Operator,	// greater than
				"=="		=> self::TOKEN_Operator,	// equal (alt.)
				"=)(."		=> self::TOKEN_Operator,	// equal

				"NEQ"		=> self::TOKEN_Operator,	// not equal
				"EQ"		=> self::TOKEN_Operator,	// equal
				"LTE"		=> self::TOKEN_Operator,	// less than or equal
				"LT"		=> self::TOKEN_Operator,	// less than
				"GTE"		=> self::TOKEN_Operator,	// greater than or equal
				"GT"		=> self::TOKEN_Operator,	// greater than

			// Conjunctions.
				"AND"		=> self::TOKEN_Conjunction,
				"XOR"		=> self::TOKEN_Conjunction,
				"OR"		=> self::TOKEN_Conjunction,
				"&&"		=> self::TOKEN_Conjunction,
				"\\|\\|"	=> self::TOKEN_Conjunction,
				","			=> self::TOKEN_Conjunction,

			// Expression grouping.
				"\\("		=> self::TOKEN_LeftParenthesis,
				"\\)"		=> self::TOKEN_RightParenthesis,
			);

	// Automatically apply word-boundary markers to all text strings, plus the
	// additional bit of regex hokeyness to capture the next character (which is
	// required because otherwise we will capture the expression too early.
		foreach ($arrRecognisedTokens as $Regex => $TokenType) {
			if (preg_match("/^[a-z]+$/i", $Regex)) {
				unset($arrRecognisedTokens[$Regex]);
				$Regex = "\b" . $Regex . "\b)(.";
				$arrRecognisedTokens[$Regex] = $TokenType;
			}
		}

	// Array of token mappings.  Anything on the left-hand side of the array will
	// be replaced by the item on the right once captured.  Both tokens must be of
	// the same type, otherwise results may be unpredictable.
	// Use upper-case on both sides of the array.
		$arrTokenMappings = array(
				","		=> "AND",
				"&&"	=> "AND",
				"||"	=> "OR",

				"!="	=> "<>",
				"=="	=> "=",

				"NEQ"	=> "<>",
				"EQ"	=> "=",
				"LT"	=> "<",
				"LTE"	=> "<=",
				"GT"	=> ">",
				"GTE"	=> ">=",
			);

	// Characters that indicated a quoted string, mapping starting character to
	// its details: token type and ending character.  All quotes may be escaped
	// using \, which only escapes the end character.
		$arrQuoteCharacters = array(
			// Wikilink- and backtick-quoted identifiers.
				"[["	=> array(self::TOKEN_Identifier, "]]"),
				"`"		=> array(self::TOKEN_Identifier, "`"),

			// Double- and single-quoted strings.
				"\""	=> array(self::TOKEN_String, "\""),
				"'"		=> array(self::TOKEN_String, "'"),
			);

		$arrTokens = array();

		$QuoteType = null;
		$EndQuote = "";
		$EndQuoteRegex = "";
		$CurrentToken = "";

		while (strlen($CriteriaString) > 0) {
		// Extract the next character from the string and add it to the current
		// token (which may be blank).
			$CurrentToken .= substr($CriteriaString, 0, 1);
			$CriteriaString = substr($CriteriaString, 1);

		// If we are in a quote, only thing ending it is our quote character.
			if ($QuoteType !== null) {
				if (preg_match($EndQuoteRegex, $CurrentToken, $Matches)) {
				// Remove closing quote from token.
					$CurrentToken = substr($CurrentToken, 0,
										   0 - strlen($Matches[1]));

				// Unescape any escape characters.
					$UnescapeRegex = str_replace("XX", $EndQuote,
												 $pRegexUnescapeString);

					$CurrentToken = preg_replace($UnescapeRegex, "$1$2$3",
												 $CurrentToken);
					$CurrentToken = str_replace("\\\\", "\\", $CurrentToken);

				// Add to token array.
					$arrTokens[] = array($QuoteType, $CurrentToken);

					$QuoteType = null;
					$CurrentToken = "";
				}

			// Skip rest of loop and continue with next character, as we are in, or
			// have just ended, a quoted item.
				continue;
			}

		// Check each of our quote characters against the current string, to see if
		// this is a quoted item.  Quote characters after literal characters are
		// not treated as special.
			foreach ($arrQuoteCharacters as $StartQuote => $arrDetails) {
				if ($StartQuote == ltrim($CurrentToken)) {
					$CurrentToken = "";
					$QuoteType = $arrDetails[0];
					$EndQuote = preg_quote($arrDetails[1], "/");

				// Make regex to match end quote.  We have to do it this way rather
				// than just processing character-by-character, as not all end-quotes
				// are single-character.
				// The template regex has a placeholder "XX" that needs to be
				// replaced by the ending quote we are searching for.
					$EndQuoteRegex = str_replace("XX", $EndQuote,
												 $pRegexFindEndQuote);

				// Break out of foreach and continue next iteration of while loop.
					continue 2;
				}
			}

		// Check each of our recognised tokens against the tail of the input string.
			foreach ($arrRecognisedTokens as $Regex => $TokenType) {
			// If we have reached the end of the string, then any of our rules that
			// have a 1-character look-ahead need to also match the end of string.
			// In other words, if the regex ends "(.)" then we change it to "(.|$)"
			// when the input string is empty.
			// This ensures that constructions such as "Foo=", which is comparing the
			// field Foo to an empty string, correctly recognise the = sign.
			// Note that this will need rewriting if we ever add a multi-character
			// look-ahead for any of our search regexes.
				if (strlen($CriteriaString) == 0 && substr($Regex, -2) == "(.") {
					$Regex .= "|$";
				}

			// Make the partial regex into a full regex, including case-insensitivity
			// modifier and capturing parentheses.
				$Regex = '/^(.*)(' . $Regex . ')$/i';

				if (preg_match($Regex, $CurrentToken, $Matches)) {
				// If there was other text in the input string, then add this as a
				// literal item.
					$Matches[1] = trim($Matches[1]);
					if ($Matches[1] != "") {
						$arrTokens[] = array(self::TOKEN_UnquotedLiteral,
											 $Matches[1]);
					}

				// If there is a mapping defined for this item, then update the value
				// to the alternative representation.
					$Value = strtoupper($Matches[2]);
					if (isset($arrTokenMappings[$Value])) {
						$Value = $arrTokenMappings[$Value];
					}

				// Then add the matched token.
					$arrTokens[] = array($TokenType, $Value);

				// If there was a third match, then this expression included some
				// forward-capturing, which we need to add back onto the input
				// string.
					if (isset($Matches[3])) {
						$CriteriaString = $Matches[3] . $CriteriaString;
					}

				// Reset the current token string and break out of this inner loop.
					$CurrentToken = "";
					break;
				}
			}
		}

	// If there is anything left in the input string, then we have a final token.
	// If we are in a quote, then we terminate the quote (we treat this as valid -
	// ending quotes may be omitted at the end of a string).
		if ($QuoteType !== null) {
			$arrTokens[] = array($QuoteType, $CurrentToken);
		}
	// If we are not in a quote, this is an unquoted literal.
		else {
			$CurrentToken = trim($CurrentToken);
			if ($CurrentToken != "")
				$arrTokens[] = array(self::TOKEN_UnquotedLiteral, $CurrentToken);
		}

		return $arrTokens;
	}

// pExpandImplicitTokens()
// Returns a modified version of the supplied token array, having populated/tidied
// some implicitly-defined tokens that we allow in our syntax.  Some of these are
// provided for user-convenience, some for backwards-compatibility with older
// WikiDB versions (some, both).
// The following changes will be made:
// * Any TOKEN_UnquotedLiteral tokens will be converted to either TOKEN_String or
//   TOKEN_Identifier.  For convenience and backwards-compatibility, we allow
//   unquoted values to be used for simple Field=Value comparisons, and so we
//	 therefore treat values before an operand as identifiers and values after an
//	 operand as strings.
// * Before we allowed quoted strings, the only way to specify the empty string was
//   just to omit it.  This meant that constructs such as "Name=" were valid, even
//   in more sophisticated contexts (e.g. "FirstName= AND Surname=").  In any
//	 situation where a TOKEN_Operator is not followed by a string, identifier or
//	 unquoted literal, we therefore insert an empty TOKEN_String token.
	function pExpandImplicitTokens($arrTokens) {
		$arrResults = array();

	// Array defining tokens that may be valid operands.
		$arrValidOperands = array(self::TOKEN_UnquotedLiteral, self::TOKEN_String,
								  self::TOKEN_Identifier);

		foreach ($arrTokens as $Index => $Token) {
		// Convert UnquotedLiteral tokens into either strings or identifiers,
		// depending on context.
			if ($Token[0] == self::TOKEN_UnquotedLiteral) {
			// If this is not preceded by an operator, it is an identifier.
				if ($Index == 0 || $arrTokens[$Index - 1][0] != self::TOKEN_Operator)
					$Token[0] = self::TOKEN_Identifier;
			// Otherwise, it is a string.
				else
					$Token[0] = self::TOKEN_String;
			}

		// Add token to results array.
			$arrResults[] = $Token;

		// If an operator is not followed by a string, identifier or unquoted
		// literal, then add an additional token for the implied empty string.
			if ($Token[0] == self::TOKEN_Operator) {
				if (!isset($arrTokens[$Index + 1])
					|| !in_array($arrTokens[$Index + 1][0], $arrValidOperands))
				{
					$arrResults[] = array(self::TOKEN_String, "");
				}
			}
		}

		return $arrResults;
	}

// pTidyOperands()
// Takes the array of tokens, which has passed validation, and tidies/prepares the
// identifiers and strings ready for use in the query.
	function pTidyOperands($arrTokens) {
		$arrResult = array();

		$LastIdentifier = null;

	// Update all identifiers to contain the table/field names, properly tidied and
	// with aliases resolved.
		foreach ($arrTokens as $Value) {
			if ($Value[0] == self::TOKEN_Identifier) {
			// Parse the field to get the proper table/field names.
			// All aliases are resolved.
				$FieldData = $this->pParseFieldName($Value[1]);

			// If an error occurred, then return without doing anything more.
				if ($FieldData == false) {
					$this->pErrorMsg = WikiDB_Msg('wikidb-queryerror-badfieldname',
												  $Value[1]);
					return false;
				}

			// Otherwise, update the field to contain the unaliased information.
				$Value[1] = array(
								'table' => $FieldData['tablename'],
								'field' => $FieldData['fieldname'],
							);
			}

			$arrResult[] = $Value;
		}

	// Update all strings so that the value to be correctly formatted according to
	// the field it is being compared to.  We need to do this in a separate pass to
	// the above, as the field may come after the string.
		foreach ($arrResult as $Index => $Value) {
			if ($Value[0] == self::TOKEN_String) {
				$FieldTokenIndex = null;

			// Check if this is in the form Field OP Value.
				if ($Index > 0 && $arrResult[$Index - 1][0] == self::TOKEN_Operator)
				{
					if ($Index > 1
						&& $arrResult[$Index - 2][0] == self::TOKEN_Identifier)
					{
						$FieldTokenIndex = $Index - 2;
					}
				}
			// Otherwise, check if this is in the form Value OP Field.
				elseif (isset($arrResult[$Index + 1])
						&& $arrResult[$Index + 1][0] == self::TOKEN_Operator)
				{
					if (isset($arrResult[$Index + 2])
						&& $arrResult[$Index + 2][0] == self::TOKEN_Identifier)
					{
						$FieldTokenIndex = $Index + 2;
					}
				}

			// If a field was found, format the value according to that field's type.
			// Otherwise, we leave it in its original form.
			// TODO: Or should we guess its type, and format according to that?  This
			//		 would normalise values that might otherwise be treated
			//		 differently, e.g. "5" and "5.0".
				if ($FieldTokenIndex !== null) {
					$TableName = $arrResult[$FieldTokenIndex][1]['table'];
					$FieldName = $arrResult[$FieldTokenIndex][1]['field'];
					$objTable = $this->pTables[$TableName]['table'];

					$Value[1] = $objTable->FormatFieldForSorting($FieldName,
																 $Value[1]);
				}
			}

			$arrResult[$Index] = $Value;
		}

		return $arrResult;
	}

// pSetSortFromString()
// Parses the sort string, and sets up an array of sort data indicating the fields
// to sort on and the direction of sort.
// TODO: Allow for multiple tables.
//
// The sort string may contain the following:
//	* Multiple sort criteria are allowed, separated by commas.  The resulting data
//	  will be sorted by each of these fields in order, in the same way as a standard
//	  ORDER BY clause.
//	* Each criterion is of the form "FIELD DIRECTION", where DIRECTION is optional.
//	* Whitespace is allowed and ignored.
//
//	* FIELD follows the same rules as for the WHERE criteria, above.  If invalid,
//	  then that sort directive is skipped.
//	  TODO: Not true - currently the whole query fails!
//	* DIRECTION is either "ASC" or "DESC" (case-insensitive).  If omitted, "ASC"
//    is used.
//
	function pSetSortFromString($SortString) {
		$SortFields = array();

		foreach (explode(",", $SortString) as $SortField) {
			if (preg_match('/^(.*)(?:\s(ASC|DESC))?\s*$/iU', $SortField, $Matches)) {
				$Field = trim($Matches[1]);
				if ($Field == "")
					continue;

			// Normalise the $Direction property, setting it if omitted.
			// We don't need to check the value is valid, as the regex has already
			// done that for us.
				if (!isset($Matches[2]))
					$Direction = "ASC";
				else
					$Direction = strtoupper($Matches[2]);

			// Parse the field to get the proper table/field names.
			// All aliases are resolved.
				$FieldData = $this->pParseFieldName($Field);

			// If an error occurred, then return without doing anything more.
				if ($FieldData == false)
					return false;

				$SortFields[] = array(
									'table' => $FieldData['tablename'],
									'field' => $FieldData['fieldname'],
									'dir' => $Direction,
								);
			}
		}

		$this->pSortFields = $SortFields;

	// Return true to indicate success.
		return true;
	}

// pParseFieldName()
// Parses a string to get the field name.  If the field string contains a period
// then the portion prior to the period is the name of a table, otherwise the table
// defined for the query is assumed (and if more than one is defined, an error is
// raised).
// If the string contains a table component that wasn't in the query's table
// string then an error is raised.  Note that table aliases are resolved before this
// check.
// If the fieldname is not syntactically valid, then false is returned.
// If valid, an array containing the 'tablename' and 'fieldname' is returned.  The
// array also contains the WikiDB_Table object for the table, to save calling
// functions having to recreate it, if they need access to it for any reason.
// Otherwise pErrorMsg is set and false is returned.
// Table aliases are resolved, and so are field aliases, if the field exists in
// the table definition.
	function pParseFieldName($FieldString) {
	// Get position of period in field name
		$DotPos = strpos($FieldString, ".");

	// If there is no period in the field string, then use the table defined in the
	// query's table string.
	// If the string starts with a dot, then this considered part of the field name,
	// not an empty table name.
		if ($DotPos === false || $DotPos === 0) {
		// If there is more than one table defined for this query, then the field
		// string is ambiguous, so raise an error.
			if (count($this->pTables) > 1) {
				$this->pErrorMsg
							= WikiDB_Msg('wikidb-queryerror-ambiguousfieldname');
				return false;
			}

		// Otherwise, use the table defined in the query's table string.
		// This has already had aliases resolved.
			$FullTableName = key($this->pTables);
			$Table = $this->pTables[$FullTableName]['table'];
		}

	// If the field string contains a period then it contains a table name
	// component, which needs separating and checking.
		else {
		// Extract table name from Field
			$TableName = trim(substr($FieldString, 0, $DotPos - 1 ));
		// Set $FieldString to hold just the field component.
			$FieldString = trim(substr($FieldString, $DotPos + 1));

		// Resolve table aliases, and resolve table name to it's canonical version
		// (i.e. including namespace).
			$Table = new WikiDB_Table($TableName);
			if (!$Table->IsValidTable()) {
				$this->pErrorMsg = WikiDB_Msg('wikidb-queryerror-badtablename',
											  $TableName);
				return false;
			}

			$Table = $Table->GetDataDestination();
			$FullTableName = $Table->GetFullName();

		// If the table is not defined for use in the query, raise an error.
			if (!isset($this->pTables[$FullTableName])) {
				$this->pErrorMsg = WikiDB_Msg('wikidb-queryerror-undefinedtable',
											  $TableName);
				return false;
			}
		}

	// Normalise the field name.  If it is not a valid field name, return false.
	// TODO: Should we just skip invalid field names, i.e. return something else
	//		 to indicate that this item is invalid, but that the rest of the query
	//		 is allowed to continue?
		$TidyFieldString = WikiDB_Table::NormaliseFieldName($FieldString);
		if (IsBlank($TidyFieldString)) {
			$this->pErrorMsg = WikiDB_Msg('wikidb-queryerror-badfieldsyntax',
										  $FieldString);
			return false;
		}

	// Resolve field aliases if the field is defined.
		if ($Table->FieldIsDefined($TidyFieldString))
			$TidyFieldString = $Table->GetAlias($TidyFieldString);

		return array(
					'tablename' => $FullTableName,
					'fieldname' => $TidyFieldString,
					'table'		=> $Table,
				);
	}

/////////////// GENERATING INTERMEDIATE DATA ///////////////

// pGenerateFieldLists()
// Fills in the 'fields' array for each table.  This will become an array of fields
// in that table which are referenced in the query for filtering or sorting purposes
// (i.e. the fields that we need to include in the SQL).
// The array is indexed by the actual field name, with the value being the name
// we will use for the table when creating the join statement required for that
// field.
// All table and field aliases have been resolved prior to calling this function.
	function pGenerateFieldLists() {
	// Create an array of all fields used in the query, by combining the sort fields
	// and the fields used in the criteria.  It doesn't matter that the array
	// structures are slightly different - the fields we are interested in are the
	// same.
	// We assign by reference, so we can update the table names with an appropriate
	// alias.
		$arrAllFields = array();
		foreach ($this->pSortFields as $Key => $Value) {
			$arrAllFields[] =& $this->pSortFields[$Key];
		}
		foreach ($this->pCriteria as $Key => $Value) {
			if ($Value[0] == self::TOKEN_Identifier)
				$arrAllFields[] =& $this->pCriteria[$Key][1];
		}

	// Set up the aliases.
		$FieldCount = 1;
		foreach ($arrAllFields as $Index => $Field) {
		// Create some short-cuts to make code more readable.
			$TableName = $Field['table'];
			$FieldName = $Field['field'];

		// Create the table alias that will be used for this field.  Each field
		// requires a separate join to the WikiDB fields table, and this is the name
		// that will be used.
			$Alias = $this->pTables[$TableName]['alias'] . "_field"
				   . $FieldCount;
			$Alias = $this->pQuoteUserSuppliedTableName($Alias);

		// Store the $FieldName => $Alias mapping into the list of fields used by
		// this table, so that they are created as part of the JOIN.
			$this->pTables[$TableName]['fields'][] = array($FieldName, $Alias);

		// Increment the field counter.
			$FieldCount++;

		// Add the SQL that will select this field's value to the original field
		// definition, so it can be used in the WHERE/ORDER BY clauses.  We do it
		// here, rather than when building the SQL, so that it only needs to be done
		// once per field.
			$FieldValueSQL = "(CASE WHEN "
							  . $Alias . ".field_value IS NULL "
							  . "THEN '' "
							  . "ELSE "
							  . $Alias . ".field_value "
							  . "END)";
			$arrAllFields[$Index]['field_value_sql'] = $FieldValueSQL;
		}
	}

/////////////// PERFORMING THE QUERY ///////////////

// pPerformQuery()
// Generates and runs the query, returning the full set of matched data rows
// in the standard format.
// TODO: Allow multi-table queries.
	function pPerformQuery() {
		$fname = __CLASS__ . "::" . __FUNCTION__;

	// Prepare the query.
		$this->pGenerateFieldLists();

	// Block multiple tables for now.
		if (count($this->pTables) != 1) {
			$this->pErrorMsg = WikiDB_Msg('wikidb-queryerror-multitablequery');
			return;
		}

	// Get the SQL and run the query.
		reset($this->pTables);
		$TableData = $this->pTables[key($this->pTables)];
		$this->pSQL = $this->pGetQuerySQL($TableData, $this->pCriteria,
										  $this->pSortFields, $this->pSourceArticle);
		$this->pResult = $this->pDB->query($this->pSQL, $fname);
	}

// pGetQuerySQL()
// Generate the SQL statement used by pPerformQuery().
// TODO: Check this uses correct portable methods for building SQL.  It should use
//		 $pDB->selectSQLText() with appropriate structured arguments.
	function pGetQuerySQL($TableData, $arrCriteria, $SortFields, $SourceArticle) {
	// Create some short-cuts, for tidier code.
		$objTable = $TableData['table'];
		$DB = $this->pDB;

	// Get the correct table names for the real tables (properly escaped).
		$RowTable = $DB->tableName(pWIKIDB_tblRows);
		$FieldTable = $DB->tableName(pWIKIDB_tblFields);

	// Escape row table's alias.  We do this as we intend to allow for multiple
	// WikiDB tables to be used in one query (even though at the moment it is only
	// possible to have one).
		$RowTableAlias = $this->pQuoteUserSuppliedTableName($TableData['alias']);

	// Create field list.
	// We only need the namespace title and data for the row, which comes directly
	// from the row table.  The data for individual fields, which is in the fields
	// table, are only used in the WHERE and ORDER BY clauses and so don't need
	// to be part of the field list.
		$FieldList = $RowTableAlias . ".page_namespace, "
				   . $RowTableAlias . ".page_title, "
				   . $RowTableAlias . ".parsed_data";

	// Create JOIN expression.
	// We join the row table to multiple instances of the field table, one
	// for each reference to a field in either the WHERE or ORDER BY clause.
	// Note that previous implementations would re-use the same table instance for
	// all references to a specific field.  However, now that multi-value fields are
	// supported, if a field is referenced multiple times in the expression then we
	// will need to have multiple instances of the field.  Without this, we could not
	// do things like 'X = "Foo" AND X = "Bar"' to select records where the
	// multi-value field X contains both "Foo" and "Bar".
	// Note that when building the field lists, the $FieldName is not yet escaped,
	// and so always needs quoting, whereas the $FieldTableName has already been
	// escaped properly.  The quoting for $FieldName is not pre-escaped as in some
	// places it is used as a column name, where in others it is a string to be
	// matched.
		$JoinExpression = $RowTable . " AS " . $RowTableAlias;
		foreach ($TableData['fields'] as $arrFieldInfo) {
			$FieldName = $arrFieldInfo[0];
			$FieldTableAlias = $arrFieldInfo[1];

			$FieldString = "";
			$Aliases = $objTable->GetAliasRev($FieldName);

			foreach ($Aliases as $Alias) {
				if (!IsBlank($FieldString))
					$FieldString .= " OR ";
				$FieldString .= $FieldTableAlias . ".field_name = "
							  . $DB->addQuotes($Alias);
			}

			$JoinExpression .= " LEFT JOIN " . $FieldTable
							 . " AS " . $FieldTableAlias
							 . " ON " . $FieldTableAlias . ".row_id = "
							 . $RowTableAlias . ".row_id"
							 . " AND (" . $FieldString . ")";
		}

	// Create WHERE clause to restrict to the required table.
	// We need to compare the table name against all valid aliases.
	// Note that the list of table aliases will always have at least one entry, so
	// the criteria will never end up blank.
		$TableSelectionCriteria = "";
		foreach ($objTable->GetTableAliases() as $Index => $TableAlias) {
			if ($Index > 0)
				$TableSelectionCriteria .= " OR ";

			$TableSelectionCriteria .= "(" . $RowTableAlias . ".table_namespace = "
									 . $DB->addQuotes($TableAlias->GetNamespace())
									 . " AND " . $RowTableAlias . ".table_title = "
									 . $DB->addQuotes($TableAlias->GetDBKey())
									 . ")";
		}

	// Add criteria specified for query, if there are any.
		$UserCriteria = "";
		if (count($arrCriteria) > 0) {
			$UserCriteria .= "(";
			foreach ($arrCriteria as $i => $Criteria) {
				switch ($Criteria[0]) {
					case self::TOKEN_Identifier:
						$UserCriteria .= $Criteria[1]['field_value_sql'];
						break;

					case self::TOKEN_String:
						$UserCriteria .= $DB->addQuotes($Criteria[1]);
						break;

					case self::TOKEN_LeftParenthesis:
					case self::TOKEN_RightParenthesis:
						$UserCriteria .= $Criteria[1];
						 break;

					default:
						$UserCriteria .= " " . $Criteria[1] . " ";
						break;
				}
			}
			$UserCriteria .= ")";
		}

	// Restrict to the specified source article, if non-blank.
		if (!IsBlank($SourceArticle)) {
			$Title = Title::newFromText($SourceArticle);

			if (!IsBlank($UserCriteria))
				$UserCriteria .= " AND ";

			$UserCriteria .= "("
						  . $RowTableAlias . ".page_namespace = "
								. $DB->addQuotes($Title->getNamespace())
						  . " AND "
						  . $RowTableAlias . ".page_title = "
								. $DB->addQuotes($Title->getDBKey())
						  . ")";
		}

	// Build the ORDER BY clause.
	// This also requires adding fields to the field list, so that the necessary data
	// is available for sorting after the GROUP BY clause has been applied (and the
	// GROUP BY is needed in order to avoid duplicate rows).
	// TODO: In situations where we are sorting on a field that contains any
	//		 multi-value entries, sorting only considers the lowest (or highest, in
	//		 the case of DESC sorts) value in the set.  This is often not an issue,
	//		 but it means that the ordering of rows containing {a, b} and {a, c} is
	//		 undetermined and may vary over time.  At the moment I can't think of
	//		 a way round this, except by applying an additional sort in PHP to handle
	//		 this situation.  However, as it is probably a bit of an edge-case to be
	//		 sorting on multi-value fields in the first place, I will leave this
	//		 until I encounter (or someone reports) a real-world use-case where this
	//		 actually matters.
		$OrderBy = "";
		$SortFieldList = "";
		$SortCount = 0;
		foreach ($SortFields as $i => $Sort) {
			$SortCount++;
			$SortAlias = "sort" . $SortCount;

			if ($i > 0)
				$OrderBy .= ", ";

			$OrderBy .= $SortAlias;

			if ($Sort['dir'] == "DESC") {
				$OrderBy .= " DESC";
				$AggregateFunction = "MAX";
			}
			else {
				$AggregateFunction = "MIN";
			}

			$SortFieldList .= ", " . $AggregateFunction . "("
							. $Sort['field_value_sql']
							. ") AS " . $SortAlias;
		}

	// Build SQL statement.
		$SQL = "SELECT " . $FieldList . $SortFieldList
			 . " FROM " . $JoinExpression
			 . " WHERE (" . $TableSelectionCriteria . ")";

		if (!IsBlank($UserCriteria))
			$SQL .= " AND " . $UserCriteria;

		$SQL .= " GROUP BY " . $FieldList;

		if (!IsBlank($OrderBy))
			$SQL .= " ORDER BY " . $OrderBy;

		$SQL .= ";";

	// Return the full SQL.
		return $SQL;
	}

// pQuoteUserSuppliedTableName()
// Internal function to quote table names that have come from user input.
// In practice, these aren't directly from user input but are instead of the form
// 'tableN' or 'tableN_fieldN' where N is an integer counter, therefore there is not
// really any user input that makes it through to this function.
// We use addIdentifierQuotes() on MW versions that support it, or tableName() on
// older versions.  Note that tableName() will also add the table prefix, if one is
// defined.  This is undesirable at a cosmetic level, but won't cause any issues.
// This does not happen on more recent MW versions, which use addIdentifierQuotes().
// This function can be removed and replaced with direct calls to
// addIdentifierQuotes() once our minimum version is raised to MW 1.33 or above.
// @back-compat MW < 1.33
	function pQuoteUserSuppliedTableName($TableName) {
		if (method_exists($this->pDB, "addIdentifierQuotes")) {
			$TableName = $this->pDB->addIdentifierQuotes($TableName);
		}
		else {
			$TableName = $this->pDB->tableName($TableName);

		// The SQLite backend had a serious bug in that it failed to quote table
		// names properly!  This was fixed in MW 1.39 but for earlier versions, we
		// need to manually quote them ourselves, here.
		// See bug 72510 for more info (https://phabricator.wikimedia.org/T72510).
		// As this code-path is only accessed on MW < 1.33, we don't need an
		// explicit version check to bypass our workaround on fixed MW versions.
			if ($this->pDB->getType() == "sqlite") {
				$TableName = '"' . str_replace('"', '""', $TableName) . '"';
			}
		}

		return $TableName;
	}

} // END: class WikiDB_Query

/////////////////////////////////////////////////////////////////////////////////////
// Originally the code to retrieve all data for a table (in the WikiDB_Table class)
// provided an optional argument that allowed you to ignore any table aliases.  This
// provided a mechanism to GetRows() or CountRows() in a way that only reported on
// data that was explicitly added to the table directly.
// In practice, this was never used for anything, but it feels like it could be
// useful in certain situations (e.g. wiki-tidying).
// The functionality was removed from that class, but I have included my notes about
// how it worked, below.
// TODO: Should we re-implement this as an option on the WikiDB_Query class?
// TODO: Should we also consider adding an option to ignore field aliases?
/////////////////////////////////////////////////////////////////////////////////////
// In the [query] functions, if $ResolveAliases is false then the results will
// be based on data which is explicitly pointed to the current table.  If
// $ResolveAliases is set to true, then it will resolve all table aliases (defined
// by standard MW page redirects) and use the combined set of tables to base the
// results on.  More specifically:
// * If current table is an alias of another table:
//	 * If $ResolveAliases = true, no data exists for this table, as it will instead
//	   be in the table that the alias resolves to.
//   * If $ResolveAliases = false, any data explicitly put into this table will be
//     used to generate the result (even though it is actually in a different table,
//     due to the redirect).
// * If current table is not an alias of another table:
//	 * If $ResolveAliases = true, all data for this table, and for any other tables
//     which redirect (directly or indirectly) to this table is used to generate
//     the result.
//   * If $ResolveAliases = false, only data explicitly put into this table will be
//     used to generate the result (data from tables which are aliases of this one
//	   will be ignored).
/////////////////////////////////////////////////////////////////////////////////////
