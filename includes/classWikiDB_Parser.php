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
 * WikiDB Parser class.
 * A set of static functions used to parse the various forms of input data into
 * a format that we can work with.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2439 $
 */

class WikiDB_Parser {

	function __construct() {
	// Never instantiate this object!
	}

// ParseTagsFromText()
// Parses the supplied wiki text and returns an array containing information about
// any WikiDB tags it contains.
// The Title and Article objects are required for the parsing, and must be included.
	static function ParseTagsFromText($Text, $Title, $Article) {
		$objParser = WikiDB_GetParser();

	// Get a 'neutral' ParserOptions object, optimised to ensure the parsing is
	// consistent for all users and that any unnecessary options are disabled (for
	// performance reasons).
		$objParserOptions = WikiDB_GetNeutralParserOptions();

	// Clear any tags information from any previous parse.
		self::pClearMatchedTags($objParser);

	// Perform the actual parse.  This will trigger any hooks or parser tag functions
	// if appropriate for the page, which in turn will add any matched tags to the
	// Parser object so that we can extract and return these, subsequently.
		$objParser->parse($Text, $Title, $objParserOptions,
						  true, true, $Article->getRevIdFetched());

	// Return an array containing all relevant WikiDB tags that were matched whilst
	// parsing the text.
	// Note that, to reduce memory use, we currently only register tags that are used
	// for something within WikiDB.  Other tags (e.g. 'repeat') can have this
	// functionality added quite easily, were it to be needed for something.
		$Result = array(
						'data' => self::GetMatchedTags($objParser, 'data'),
						'tabledef' => self::GetMatchedTags($objParser, 'tabledef'),
					);

		return $Result;
	}

// RegisterMatchedTag()
// Used within our parser tags to register that a particular tag was found during
// this parse.  Will be called once per matching tag, being passed the tag name,
// normalised arguments and unprocessed content.
// This can then be used by the code which triggered the parse in order to do
// something else with the information, e.g. save it to the database, display a
// summary on the edit form, etc.
	static function RegisterMatchedTag($Parser, $Title, $TagName, $Arguments,
									   $Content)
	{
		if (!isset($Parser->mWikiDB_ExtractedTags))
			$Parser->mWikiDB_ExtractedTags = array();

	// We use a custom property on the Parser object, not on the ParserOutput object.
	// This is because this information is not required for displaying/rendering the
	// page and therefore we don't want it stored in the cache.
	// MW 1.21 introduces methods to store custom extension information on the
	// ParserOutput object, but as far as I know this is still not supported for the
	// Parser directly, so for now at least I think this remains the best method of
	// handling this.
		$Parser->mWikiDB_ExtractedTags[$TagName][] = array(
						'Title' => $Title,
						'Arguments' => $Arguments,
						'Content' => $Content,
					);
	}

// GetMatchedTags()
// Returns an array containing information about each tag of the specified type that
// was found in the last parse.
	static function GetMatchedTags($Parser, $TagName) {
		if (isset($Parser->mWikiDB_ExtractedTags[$TagName]))
			return $Parser->mWikiDB_ExtractedTags[$TagName];
		else
			return array();
	}

// pClearMatchedTags()
// Clears all tags that were registered during any previous parsing operation.
// Private function to reset the array of matched tags prior to parsing.
	static function pClearMatchedTags($Parser) {
		unset($Parser->mWikiDB_ExtractedTags);
	}

// ParseTableDef()
// Takes an input string and returns an array containing any fields it defines.
// Each field is on a single line, and any line that does not match the correct
// format for a field definition is ignored.  See pParseFieldDef() for details about
// the field definition.
// The returned array is formatted as key/value pairs.  For each definition line
// found, key is the name of the field and the value is an array holding the field
// definition, as returned from pParseFieldDef() (see below).  If there was other
// text that occurred between the definition lines then these are indexed as '_textN'
// where N begins with 0.  Multiple text lines are grouped together.
// If $IncludeOtherText is false, then the text lines are not returned.
	static function ParseTableDef($Input, $IncludeOtherText = true) {
		$Lines = explode("\n", $Input);

		$Result = array();
		$TextCounter = 0;
		$Text = "";
		$TableDef = array();
		$InComment = false;

		foreach ($Lines as $Line) {
			$FieldDef = self::pParseFieldDef($Line);

		// If the line was a field definition, then add it to the array.
		// Before doing that, if there was any text found, and
		// $IncludeOtherText = true, then add the text and reset the text variables.
			if ($FieldDef && !$InComment) {
				if ($IncludeOtherText && $Text != "") {
					$TableDef['_text' . $TextCounter] = $Text;
					$Text = "";
					$TextCounter++;
				}
				$TableDef[$FieldDef['FieldName']] = $FieldDef;
			}
		// Otherwise, add the non-definition line to the text, ready to be added to
		// the array as necessary.
			else {
				$Text .= $Line . "\n";
				$InComment = self::pResolveCommentState($Line, $InComment);
			}
		}

	// Add any left-over text from the bottom of the page.
		if ($IncludeOtherText && $Text != "")
			$TableDef['_text' . $TextCounter] = $Text;

		return $TableDef;
	}

	static function pResolveCommentState($Line, $InComment) {
		if ($InComment) {
			$Pos = strpos($Line, "-->");
			if ($Pos === false)
				return $InComment;
			$Pos += 3;
		}
		else {
			$Pos = strpos($Line, "<!--");
			if ($Pos === false)
				return $InComment;
			$Pos += 4;
		}

		$Line = substr($Line, $Pos);
		$InComment = !$InComment;
		return self::pResolveCommentState($Line, $InComment);
	}

// pParseFieldDef()
// Takes a single line of text and checks whether it is a valid field definition.
// If so then an array containing details about the field definition (see below)
// is returned. If not then the function returns null.
// A valid field definition has the following components.  Only the first two are
// required. Any of the others may be omitted, but if present they must be in the
// order specified.  Whitespace is allowed pretty much anywhere.  In practice, this
// means that any line that begins with a greater-than symbol and has one or more
// non-whitespace characters is a valid field definition.
// TODO: Currently lines that start with > and just contain whitespace (or nothing
//		 else) are treated as normal text lines, whereas actually they should
//		 perhaps be treated as invalid field definitions.
// TODO: Ditto lines where the field name is invalid (though this will be uncommon).
//
//	> 		   - (greater than symbol) - must be the first character on the line.
//	FieldName  - must not contain ":", "[" or "-"
//	:Type	   - must not contain spaces, "(", "[" or "-".  Initial colon is not part
//			     of the type name.
//	(Args)	   - Args is a comma-separated list of arguments, which are
//			     type-specific.  Only allowed if a type is specified.
//	[[#Alias]] - The name of another field, which this field is an alias for.
//	-- Comment - Anything else you like, provided it is separated by a double-hyphen.
//
// e.g.
//	> MyField:Integer (1, 10) -- This field holds an integer between 1 and 10.
//	> MyAlias [[#MyField]] -- This field is an alias for MyField.
//
// If successful, the returned array will have whichever of the following elements
// were defined:
//	'FieldName' => The name of the field (tidied, according to
//				   WikiDB_Table::NormaliseFieldName()).
//	'Type'		=> The data type of the field (as entered - it may not be a valid
//				   type).
//	'Options'	=> A numerically-indexed array of type arguments.  These are all
//				   strings (no conversion is performed).
//	'Alias'		=> The field that this is an alias for (tidied, according to
//				   WikiDB_Table::NormaliseFieldName()).
//	'Comment'	=> The comment (not including the double-hyphen).
//
// 'FieldName' is always present.
// 'Options' is only present if 'Type' is present and options were defined.
// 'Alias' or 'Type' will not both be present.  It will be one or other of them
// (or neither).
// 'Comment' is only present if a comment was added.
	static function pParseFieldDef($Line) {
		global $pRegexDBLine;

		$FieldData = array();

		if (preg_match($pRegexDBLine, $Line, $Match)) {
		// $Match contains the following elements:
		// [0] - Whole line
		// [1] - Field name
		// [2] - Field type
		// [3] - Arguments (comma-separated list)
		// [4] - Link (if it starts with # it's an alias) - square brackets not
		//		 included.
		// [5] - Comment (not including starting dashes) - i.e. rest of the line.

		// The field name is always present.
		// Sanitise it prior to use.
			$FieldData['FieldName'] = WikiDB_Table::NormaliseFieldName($Match[1]);

		// Check validity - if it is blank then it was invalid.  We therefore skip
		// this line.
		// TODO: Perhaps we should display it as invalid somehow, rather than just
		//		 skipping it?  In practice this is not very likely so perhaps not
		//		 something to worry about.  As far as I can only see, it is only
		//		 going to be invalid if it is blank or made up wholly of white-space
		//		 and underscores...
			if (IsBlank($FieldData['FieldName']))
				return null;

		// If the 4th argument is present, it is either an alias or a foreign key
		// reference.
			if (!empty($Match[4])) {
			// Remove any white-space at the start of the string.
				$Match[4] = ltrim($Match[4]);

			// If the string begins with # then it is an alias for another field,
			// in which case we trim the # and normalise the name, storing it in the
			// alias field.
				if ($Match[4][0] == "#") {
				// Remove the # character from the string.
					$Alias = substr($Match[4], 1);
					$Alias = WikiDB_Table::NormaliseFieldName($Alias);
				// Normalise the resulting string to give a valid field name, and
				// store this as the alias.
					if (!IsBlank($Alias))
						$FieldData['Alias'] = $Alias;
				}

		/* Foreign keys disabled, as there is currently no functionality associated
		   with them.
			// Otherwise it is a foreign key
				else {
				// Split the field into Table#Field elements, where #Field is
				// optional.  If the string contains more than one #, from the
				// second # onwards is ignored.
				// TODO: Is this correct behaviour?  Perhaps it should be collapsed
				//		 back into the ForeignField?  Tables cannot contain #
				//		 symbols, but currently field names can...
					$ForeignKey = explode("#", $Match[4]);
					$FieldData['ForeignTable'] = $ForeignKey[0];

				// If a foreign field was specified, sanitize and validate it and if
				// it is OK, use it.
				// TODO: If it is present but invalid, should it be treated as an
				//		 error in some way?  Currently it is treated the same as if
				//		 it wasn't specified at all.
					if (isset($ForeignKey[1])) {
						$ForeignField = $ForeignKey[1];
						$ForeignField
								= WikiDB_Table::NormaliseFieldName($ForeignField);

						if (!IsBlank($ForeignField))
							$FieldData['ForeignField'] = $ForeignField;
					}

				// If a foreign table was set, but there was no valid field attached
				// (either because it wasn't specified, or because the specified
				// field was not a valid name) then use the field name of the source
				// field as the foreign field.
					if (!isset($FieldData['ForeignField']))
						$FieldData['ForeignField'] = $FieldData['FieldName'];
				}
		*/
			}

		// Fill in the type info, if available.  This is ignored for aliases.
			if (!isset($FieldData['Alias'])) {
				if (!empty($Match[2])) {
					$FieldData['Type'] = strtolower(trim($Match[2]));

				// Options are only valid if a type was specified.
					if (!empty($Match[3])) {
						$FieldData['Options'] = explode(",", $Match[3]);
						foreach ($FieldData['Options'] as $Key => $Option)
							$FieldData['Options'][$Key] = trim($Option);
					}
				}
			}

		// If there is a comment string, add it to the array
			if (!empty($Match[5]))
				$FieldData['Comment'] = trim($Match[5]);

			return $FieldData;
		}
		else {
			return null;
		}
	}

// ParseDataTag()
// Takes the contents of a <data> tag and returns an array where each
// numerically-indexed element defines a row of data (destined for the same table),
// represented as an array of FieldName => Value pairs.
// All values in the returned array are strings, and all field names and values are
// trim()med before being returned.
// There are two parse methods, depending on whether the $FieldList argument is set
// or not.
// If $FieldList is null then the data is in single-row mode, which means that only
// a single row will be returned.  It parses each row of the $Input string and any
// which contains an = sign are treated as a FieldName=Value pair (if there is more
// than one = sign then the first is the separator between field name and value).
// Blank lines or lines without an equals sign are treated as comments and ignored.
// Alternatively, if $FieldList is not null, then we are in multi-row mode.  In this
// case, $FieldList is a list of field names separated by commas and each line of the
// $Input string defines a separate row of table data (unless it is blank or a
// wikitext header (at any level), e.g. "== Header ==".  These lines are ignored).
// Each non-ignored row in the $Input string consists of a set of field values,
// separated by the specified $Separator string (by default, a comma).  The fields
// are in the order specified by $FieldList, and if there are more items in the row
// than there are in $FieldList, the extra fields are ignored.  If there are fewer
// then the missing fields are set to "".
// In both cases, any invalid FieldNames are ignored, although they retain their
// positioning, e.g. a field string containing "Field1,INVALID:FIELD,Field3"
// and a data row containing "a,b,c" will return Field1 => a, Field3 => c (in other
// words it skips over the data entry corresponding to the invalid field).
	static function ParseDataTag($Input, $FieldList = null, $Separator = ",") {
		$Lines = explode("\n", $Input);

		$Data = array();

	// Single-row mode:
		if ($FieldList === null) {
			foreach ($Lines as $Line) {
			// TODO: Are the /sm modifiers needed?  If so, document them!
				if (preg_match('/^([^=]+)=(.*)$/sm', $Line, $ParsedLine)) {
					$FieldName = WikiDB_Table::NormaliseFieldName($ParsedLine[1]);
					if (!IsBlank($FieldName)) {
						$Value = trim($ParsedLine[2]);

					// If it starts and ends with curly braces, this is a multi-value
					// field.
						if (substr($Value, 0, 1) == "{"
							&& substr($Value, -1, 1) == "}")
						{
							$Value = substr($Value, 1, -1);
							$arrValues = explode($Separator, $Value);

							foreach ($arrValues as $Index => $Value) {
								$arrValues[$Index] = trim($Value);
							}

							if (count($arrValues) == 0)
								$Value = "";
							elseif (count($arrValues) == 1)
								$Value = $arrValues[0];
							else
								$Value = $arrValues;
						}

						$Data[0][$FieldName] = $Value;
					}
				}
			}
		}

	// Multi-row mode:
		else {
			$FieldNames = explode(",", $FieldList);
			foreach ($FieldNames as $Key => $Value)
				$FieldNames[$Key] = WikiDB_Table::NormaliseFieldName($Value);

			$Row = 0;
			foreach ($Lines as $Line) {
			// Skip blank lines
				if (trim($Line) == "")
					continue;

			// Skip 'headers'
			// Regex based on the definition in parser::doHeadings().
				if (preg_match('/^(={1,6})(.+)\\1\\s*$/', $Line))
					continue;

			// Otherwise the row contains data, so we add it to the output
			// array.
				$FieldValues = explode($Separator, $Line);
				foreach ($FieldNames as $FieldIndex => $FieldName) {
				// Initialise the field, but only if the field name was non-blank.
				// Unfortunately, we can't just skip over blank field names as they
				// still occupy a position in the field list and we need to process
				// this position in the field values, in order to skip over it.
					if (!IsBlank($FieldName))
						$Data[$Row][$FieldName] = array();

					$EndOfField = false;
					$InSet = false;
					while (!$EndOfField) {
					// Get the next field value and trim any white-space.
						$FieldValue = trim(array_shift($FieldValues));

					// If we are not already in a set, then this is either the first
					// value of a set (if it starts with an opening brace) or a
					// single-value field.
						if (!$InSet) {
						// If the field value starts with an opening brace, then it
						// is the start of a set, containing multiple values.
						// Set the flag to show we are in a set and remove the
						// opening brace from the string.
							if (substr($FieldValue, 0, 1) == "{") {
								$InSet = true;
								$FieldValue = substr($FieldValue, 1);
							}
						// If it doesn't start with an opening brace, then this is a
						// single-value field, so set $EndOfField to true.
							else {
								$EndOfField = true;
							}
						}

					// If we are in a set and the value ends with a closing brace,
					// then this is the end of the set.  Remove the brace from the
					// value and flag that this is the end of the field.
						if ($InSet && substr($FieldValue, -1) == "}") {
							$FieldValue = substr($FieldValue, 0, -1);
							$EndOfField = true;
						}

					// Trim the field value again, in case there was white-space
					// inside the brace characters.
						$FieldValue = trim($FieldValue);

					// Add the value to the set of values for this field.
					// We skip any fields whose name is blank as these fields
					// cannot exist.  Because the field data needs to positionally
					// match the header row, we still need to process the field value
					// fully in order to skip over it correctly, which is why we
					// can't do the IsBlank check at the start of the loop.
						if (!IsBlank($FieldName))
							$Data[$Row][$FieldName][] = $FieldValue;
					}

				// Convert single-value fields back into a single string
				// representation.  The array representation is only used for
				// multi-value fields.
					if (!IsBlank($FieldName)) {
						if (count($Data[$Row][$FieldName]) == 0)
							$Data[$Row][$FieldName] = "";
						elseif (count($Data[$Row][$FieldName]) == 1)
							$Data[$Row][$FieldName] = $Data[$Row][$FieldName][0];
					}
				}

			// Increment the row counter.
				$Row++;
			}
		}

		return $Data;
	}

// ParseQueryTag()
// Takes the contents of the query tag and returns an array with three elements,
// 'header' (anything inside a <header> tag, if this is the first item in the input)
// 'footer' (anything inside a <footer> tag, if this is the last item in the input)
// 'body' (everything else)
// All three elements are always guaranteed to be present, but may be empty.
	static function ParseQueryTag($Input) {
		$arrResults = array('header' => "", 'body' => "", 'footer' => "");

		$Regex = '/^\s*' . self::pGetSimpleTagMatchRegex("header") . '/si';
		if (preg_match($Regex, $Input, $Matches)) {
			$arrResults['header'] = $Matches[1];
			$Input = substr($Input, strlen($Matches[0]));
		}

		$Regex = '/' . self::pGetSimpleTagMatchRegex("footer") . '\s*$/si';
		if (preg_match($Regex, $Input, $Matches)) {
			$arrResults['footer'] = $Matches[1];
			$Input = substr($Input, 0, 0 - strlen($Matches[0]));
		}

		$arrResults['body'] = $Input;

		return $arrResults;
	}

	static function pGetSimpleTagMatchRegex($TagName) {
		return '<\s*' . $TagName . '\s*>((?U).*)<\\/\s*' . $TagName . '\s*>';
	}

// ParseGuessTypesTag()
// Takes the contents of the guesstypes tag and returns the contents as a structured
// array.
// See efWikiDB_GuessTypes() for details about the input format.
	static function ParseGuessTypesTag($Input) {
	// Explode the input into an array of separate lines.
		$Lines = explode("\n", $Input);

	// Create an array of formatted lines.  This contains the data that we need to
	// output for each line that contained a valid description/input-text pair, i.e.
	// any line containing a colon.
		$FormattedLines = array();
		foreach ($Lines as $Line) {
		// If line starts with = then it is the correct answer for the previous
		// line (useful for unit-testing).  In which case, we add it to the
		// output of the previous line in the $FormattedLines array.
		// If line does not contain a colon then it the whole string is the expected
		// output.
		// If it contains a colon, everything before the first colon is the
		// expected type and everything after the first colon is the expected output.
		// The expected type may be left blank, which is the same as omitting it
		// completely, except it allows you to supply an expected output that
		// contains a semi-colon.
		// TODO: Is /s switch needed?
			if (preg_match('/^=(.*)(?::(.*))?$/Us', $Line, $Matches)) {
				$Count = count($FormattedLines) - 1;
			// Only check the item if there is a previous line that this can apply
			// to - otherwise it is ignored.
				if ($Count >= 0) {
				// If there was no colon, the output is in the first element and the
				// second element is blank.  We therefore bump things so that the
				// output is in the second element and the first element (the type)
				// is blank.
					if (!isset($Matches[2])) {
						$Matches[2] = $Matches[1];
						$Matches[1] = "";
					}

				// Normalise the type name.  This ensures it matches the rules for
				// naming types (currently case-insensitive) and will also trim
				// whitespace (therefore treating it as if nothing was entered).
					$Matches[1] = WikiDB_TypeHandler::NormaliseTypeName($Matches[1]);

				// If a type was filled in, then store it as the expected type.
				// We don't care whether the type is defined, just whether it matches
				// the guessed type or not.
					if ($Matches[1] != "")
						$FormattedLines[$Count]['ExpectedType'] = $Matches[1];

					$FormattedLines[$Count]['ExpectedOutput'] = $Matches[2];
				}
			}
		// Otherwise, line is a new data definition line, which should have its type
		// guessed, in which case tidy it and add it to the $FormattedLines array.
		// TODO: Is /s switch needed?
			elseif (preg_match('/^(.*):(.*)$/Us', $Line, $Matches)) {
				$Description = trim($Matches[1]);
				$Data = trim($Matches[2]);
				$Type = WikiDB_TypeHandler::GuessType($Data);
				$FormattedData
						= WikiDB_TypeHandler::FormatTypeForDisplay($Type, $Data);
				$SortFormat
						= WikiDB_TypeHandler::FormatTypeForSorting($Type, $Data);

				$FormattedLines[] = array(
											'Description' 	=> $Description,
											'Data'			=> $Data,
											'Type'			=> $Type,
											'FormattedData'	=> $FormattedData,
											'SortFormat'	=> $SortFormat,
										);
			}
		}

		return $FormattedLines;
	}

} // END: class WikiDB_Parser
