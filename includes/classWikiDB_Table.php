<?php
use MediaWiki\MediaWikiServices;

if (!defined('MEDIAWIKI'))
	die("MediaWiki extensions cannot be run directly.");
if (!defined('WikiDB')) {
	die("The WikiDB extension has not been set up correctly.<br>'"
		. basename(__FILE__) . "' should not be included directly.<br>Instead, "
		. "add the following to LocalSettings.php: "
		. "<tt><b>include_once(\"WikiDB/WikiDB.php\");</b></tt>");
}
/**
 * WikiDB_Table
 * Main class file for interfacing with WikiDB tables/table data.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2439 $
 */

////////////////////////////////////////////////////////////////////////////////////
// IMPORTANT USAGE NOTE
// This class always assumes that any field names that are passed into it have
// already been correctly sanitised, and it performs no sanitisation of its own.
// If the field name comes from user input, you should pass it through
// WikiDB_Table::NormaliseFieldName() before passing it into the class, otherwise
// you may get incorrect results.
// You do not need to sanitise field names that come from the database, as these
// should have been sanitised prior to being stored.
////////////////////////////////////////////////////////////////////////////////////

class WikiDB_Table {

////////////////////////////////////////
// Internal Variables
////////////////////////////////////////

// Title object for the table's article.  Created by the constructor.
	var $pTitle;

// Array of fields defined for this table (on the article page).  If null this
// has not been set yet.  If an empty array then there was no definition.  Otherwise
// it will hold an array where each element is a field definition, as retrieved from
// the database.
	var $pFields;

// Actual table data, as retrieved from the DB.
	var $pData;

////////////////////////////////////////
// STATIC CLASS METHODS
////////////////////////////////////////

// NormaliseFieldName()
// Takes a field name and normalises it to meet our naming rules, returning the
// sanitised version, or - if it can't be normalised - an empty string to indicate
// that it is invalid.
// * White-space at start or end is removed, for usability reasons.
// * Mustn't start with an underscore, as this is reserved for internal use.
//   Underscores are therefore stripped (so FieldName and _FieldName are equivalent).
// * Must be <= 255 characters along, for DB storage reasons.  Longer names are
//   truncated.
// * Can't contain [ - or : characters.  These indicate the end of the field name
//   in a table definition (and the start of some other part of the definition) so
//   they can't be used in other contexts either.  In this situation, the name is
//   considered completely invalid.
// * Mustn't be blank - in this case the name is considered invalid.
// All field names should be sanitised using this function the point they enter the
// system (which will be through user-supplied wikitext) and prior to getting into
// the database, or being used for any other purpose.
// It is up to the calling code to decide how best to handle invalid field names
// i.e. whether to treat it as an error condition or to simply ignore it.
	static function NormaliseFieldName($FieldName) {
	// Check the field does not contain any invalid characters.
	// The limitations are due to our field definition syntax, and are the set of
	// characters that may follow the field name in the definition line.  They
	// cannot therefore be part of the name, as they are always considered part
	// of the next token in the definition.
	// These characters are already blocked by $pRegexDBLine, the regex that parses
	// the field definitions, but we also check for them here in case the name
	// comes from some other source (e.g. data definitions, queries, etc.).
		static $arrInvalidChars = array(":", "[", "-");
		foreach ($arrInvalidChars as $InvalidChar) {
			if (strpos($FieldName, $InvalidChar) !== false)
				return "";
		}

	// Remove leading whitespace or underscores.
		static $WhiteSpaceChars = " \t\n\r\0\x0B";
		$FieldName = ltrim($FieldName, $WhiteSpaceChars . "_");

	// Ensure string is <= 255 chars long.
		$FieldName = substr($FieldName, 0, 255);

	// Remove trailing whitespace.  Must come after limiting the field size, in
	// case there is internal white-space which the truncation has caused to now
	// be at the end of the string.
		$FieldName = rtrim($FieldName);

	// Return the sanitised string.
		return $FieldName;
	}

// GetMetaDataFields()
// Returns an array of known meta-data fields, defined within the WikiDB extension.
// The fields are all prefixed by an underscore, which is not allowed for normal
// field names.
	static function GetMetaDataFields() {
		return array("_Row", "_SourceArticle");
	}

// IsKnownMetaDataField()
// Returns true if the specified field name is a known meta-data field, false
// otherwise.
// Currently field-name matches are case-sensitive.
	static function IsKnownMetaDataField($FieldName) {
		if (in_array($FieldName, self::GetMetaDataFields()))
			return true;
		else
			return false;
	}

////////////////////////////////////////
// Creation & Initialisation
////////////////////////////////////////

// Constructor.
// Takes the title of the table (as text or a title object) which it uses to locate
// the data/definition.  The optional $DefaultNamespace argument is used to fill in
// the namespace if the title is in text format and does not include a namespace
// component.  If this is omitted then it the default table namespace will be used.
// TODO: Should check the title is in a table namespace!
// TODO: How do we handle situations where this is not the case?
// TODO: Any input normalisation? (i.e. are there restrictions on table names, and
// 		 if so where are they enforced?)
	function __construct($Title, $DefaultNamespace = null) {
		$this->pTitle = WikiDB_MakeTitle($Title, $DefaultNamespace);
	}

// pLoadTableDef()
// Loads the table definition from the database the first time it is called.
// All internal object functions that use the table definition in any way should
// call this function as the first thing they do, otherwise internal variables will
// not be set properly.
	function pLoadTableDef() {
	// If already loaded then skip.
		if ($this->pFields !== null)
			return;

	// Retrieve existing data row_ids for this article
		if (class_exists('MediaWiki\MediaWikiServices')) {
            $DB = \MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY);
        } else {
            $DB = wfGetDB(DB_PRIMARY);
        }
		$objResult = $DB->select(pWIKIDB_tblTables, "table_def",
								 array(
										'table_title' 		=> $this->GetDBKey(),
										'table_namespace' 	=> $this->GetNamespace(),
									  )
								 );
		$Row = $objResult->fetchObject();
		if ($Row)
			$this->pFields = unserialize($Row->table_def);
		else
			$this->pFields = array();
		$objResult->free();
	}

////////////////////////////////////////
// MediaWiki Objects
////////////////////////////////////////

	function GetTitle() {
		return $this->pTitle;
	}

	function GetArticle() {
		return new Article($this->pTitle);
	}

////////////////////////////////////////
// Table Information
////////////////////////////////////////
// Most of these are short-cut functions to return info that comes from the private
// Title object.

// GetFullName()
// Returns the full name of the table, including the namespace prefix.
	function GetFullName() {
		if (isset($this->pTitle))
			return $this->pTitle->getFullText();
		else
			return "";
	}

// GetNamespace()
// Returns the numeric namespace ID of the table.
	function GetNamespace() {
		if (isset($this->pTitle))
			return $this->pTitle->getNamespace();
		else
			return null;
	}

// GetName()
// Returns the name of the table, without the namespace prefix.
	function GetName() {
		if (isset($this->pTitle))
			return $this->pTitle->getText();
		else
			return "";
	}

// GetDBKey()
// Returns the DBKey the table, which is a DB-normalised version of the title, which
// is used for retrieving records from the database.
	function GetDBKey() {
		if (isset($this->pTitle))
			return $this->pTitle->getDBKey();
		else
			return "";
	}

// IsValidTable()
// Returns true if the object is a valid table.  Currently, a valid table is defined
// as any title that is within a table namespace, regardless of whether the page name
// is valid or the page exists.
	function IsValidTable() {
		global $wgWikiDBNamespaces;

	// If the object doesn't have a valid Title then this is not a valid table.
		if ($this->pTitle === null)
			return false;

	// Get the namespace, as we use it several times.
		$Namespace = $this->GetNamespace();

	// The MediaWiki namespace cannot contain table definitions, even if you
	// explicitly add it to the namespaces array.
		if ($Namespace == NS_MEDIAWIKI)
			return false;

	// If the namespace exists in the list of table namespaces, return true if
	// it is enabled, otherwise false.
		foreach ($wgWikiDBNamespaces as $NS => $Enabled) {
			if ($NS == $Namespace)
				return (bool) $Enabled;
		}

	// If it wasn't in the list of table namespaces, return false.
		return false;
	}

////////////////////////////////////////
// Resolving Table Aliases
////////////////////////////////////////
// MediaWiki Redirection (via #redirect: [[Page]]) allows a table to become an
// alias of another table.  If a redirect is added to a page in a table namespace
// and that redirect points to another page in a table namespace (not necessarily the
// same namespace) then the table is treated as an alias of the destination table
// and all data that is added to the first table ends up in the second instead.
// This is very useful if you need to merge tables and don't want to update all your
// scattered data, or if there are common misspellings that you want to catch.
// Note that a redirect to a non-table page is not treated as an alias, and data
// added to that table will end up in that table.
// If table redirection forms a loop then data ends up in the original destination
// table, a query on any of the tables in the loop will result in data
// from all of them being shown.  This is slightly inconsistent, but for the time
// being I can't think of a way of avoiding this scenario.  It may not matter, and
// in fact may be a good thing, as it may help to spot this kind of loop more easily.
// For simplicity's sake, all these functions operate on the current object ($this)
// only, however, if it were useful to do so it would not be much work to modify
// the functions to work on a supplied page, as per the functions in the previous
// section.

// IsAlias()
// Returns true if this table is an alias for another table (via redirection).
// This is different from reporting on whether it is a redirect.  A table page may
// redirect to another table page (possibly in a different table namespace) in which
// case it is a table alias.  However it may also re-direct to a non-table page, in
// which case it is simply a redirect, and is still a valid table in terms of where
// its data goes.
// If you need to find out whether a table is a redirect (as opposed to an alias)
// use GetTitle() and call isRedirect() on the returned Title object.
	function IsAlias() {
		$Destination = $this->GetDataDestination();
		if ($Destination->GetFullName() === $this->GetFullName())
			return false;
		else
			return true;
	}

// GetDataDestination()
// Returns a WikiDB_Table object pointing to the table where any data targeted at
// the current table should actually end up.  If the table is an alias of another
// table then it returns the table which that alias eventually resolves to.  If the
// table is not an alias then it returns a reference to the current table ($this).
// A table is not an alias if any of the following are true:
// * The redirect chain gets into a loop
// * The redirect chain contains a redirect that points to a page that is not in
//   a table namespace
// * The current table is not a redirect to start with
// $pCheckedTitles is used during recursion to store titles we have already checked,
// to ensure we don't get into a redirect loop.
	function GetDataDestination($pCheckedTitles = array()) {
		$fname = __CLASS__ . "::" . __FUNCTION__;

	// We cache the destination for all tables, as this function can be called
	// multiple times within the same script, for the same table.
		static $arrDestinationCache = array();

		$Title = $this->GetTitle();
		$NS = $Title->getNamespace();
		$TitleText = $Title->getDBKey();

		if (isset($arrDestinationCache[$NS][$TitleText]))
			return $arrDestinationCache[$NS][$TitleText];

		if (class_exists('MediaWiki\MediaWikiServices')) {
            $DB = \MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY);
        } else {
            $DB = wfGetDB(DB_PRIMARY);
        }
		$Row = $DB->selectRow(pWIKIDB_tblTables, "*",
							  array(
									'table_namespace'	=> $NS,
									'table_title'		=> $TitleText,
							  ),
							  $fname);

	// If row not found then the table doesn't exist, in which case it can't be an
	// alias.
		if ($Row === false) {
			$Result = $this;
		}
		else {
		// Create a table object from the destination title.
			$DestinationTable = new self($Row->redirect_title,
										 $Row->redirect_namespace);

		// If the destination is not a valid table then the source page wasn't a
		// redirect to a table page (i.e. there the redirect fields are both null),
		// therefore it is not an alias.
			if (!$DestinationTable->IsValidTable()) {
				$Result = $this;
			}
			else {
			// Add the current table to the array of checked titles.
				$pCheckedTitles[] = $this->GetFullName();

			// If the destination exists in the array of checked titles then
			// there is a redirect loop, in which case we return $this.
				if (in_array($DestinationTable->GetFullName(), $pCheckedTitles)) {
					$Result = $this;
				}
				else {
				// Otherwise return the redirect destination of the destination
				// table.
					$Result = $DestinationTable->GetDataDestination($pCheckedTitles);
				}
			}
		}

		$arrDestinationCache[$NS][$TitleText] = $Result;
		return $Result;
	}

// GetTableAliases()
// Returns an array of Table objects, with one entry for each table that should be
// considered an alias of the current table (including the current table itself).
// It therefore always contains one element (the current table) plus an extra element
// for each table (i.e. page in a table namespace) which redirects to this table.
// The chain of redirects goes back as far as necessary (so if you have
// TableA -> TableB -> TableC and call GetTableAliases() on TableC then both TableB
// and TableA will be returned.  It does not do any forward-checking, however,
// so calling GetTableAliases() on TableB will not include TableC in the output.
// Pages in non-table namespaces which redirect into a table namespace are not
// included as these are not table aliases, but normal redirects.  Similarly,
// redirects from tables which go via a non-table namespace
// (e.g. TableA -> PageInMainNamespace -> TableB) are also not included.
// Redirects between different table namespaces are fine.
// The function avoids redirect loops, and will return all tables in the loop.
	function GetTableAliases() {
		$fname = __CLASS__ . "::" . __FUNCTION__;
		if (class_exists('MediaWiki\MediaWikiServices')) {
            $DB = \MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY);
        } else {
            $DB = wfGetDB(DB_PRIMARY);
        }

	// We cache the alias list for all tables, as this function can be called
	// multiple times within the same script, for the same table.
		static $arrAliasCache = array();

		$Title = $this->GetTitle();
		$NS = $Title->getNamespace();
		$TitleText = $Title->getDBKey();

		if (isset($arrAliasCache[$NS][$TitleText]))
			return $arrAliasCache[$NS][$TitleText];

	// The list of aliases always has at least one item - the current table.
		$Aliases = array($this);

	// Unchecked aliases holds an array of table objects which have yet to be
	// checked.  Whenever a new redirect is found it is added to the list, and then
	// it too is checked for items that redirect to it.  It acts as a FIFO queue
	// and we keep going until it's empty.
		$UncheckedTables = array($this);

	// This holds an array of Titles as text, and is used to avoid getting into
	// loops.  Any redirect which already exists in this array is skipped over if it
	// is encountered a second time.  This will always be the same as the $Aliases
	// array, except that we store the page titles rather than table objects, so
	// we can use in_array() (quick) as opposed to a foreach loop (slower).
		$CheckedTitles = array($this->GetFullName());

		while (count($UncheckedTables) > 0) {
			$ThisTable = array_shift($UncheckedTables);

			$Title = $ThisTable->GetTitle();
			$objResult
				= $DB->select(pWIKIDB_tblTables, "*",
							  array(
								  'redirect_namespace' => $Title->getNamespace(),
								  'redirect_title'	   => $Title->getDBKey(),
							  ),
							  $fname);

		// Get all tables that point to the current table and add any we
		// have not encountered before to our array of aliases, and to the list
		// of items still to be checked.
			while ($Row = $objResult->fetchObject()) {
				$Alias = new self($Row->table_title, $Row->table_namespace);

				if (!in_array($Alias->GetFullName(), $CheckedTitles)) {
					$Aliases[] = $Alias;
					$UncheckedTables[] = $Alias;
					$CheckedTitles[] = $Alias->GetFullName();
				}
			}

			$objResult->free();
		}

		$arrAliasCache[$NS][$TitleText] = $Aliases;
		return $Aliases;
	}

////////////////////////////////////////
// Basic Data Access
// For more advanced data-access methods, use the WikiDB_Query class.
////////////////////////////////////////

// CountRows()
// Returns a count of the no. of rows of data that the table holds.
// This method should be used in situations where you just need the count but don't
// need the records.  In situations where the records are also needed, it is
// more efficient to get the count from the WikiDB_Query object instead, as it
// won't require an extra query.
	function CountRows() {
		$Query = new WikiDB_Query($this);
		return $Query->CountRows();
	}

// GetRows()
// Returns an array containing all rows of data held in the table.
// A wrapper function to the WikiDB_Query() class.
// If you need to call this function more than once for the same table, or if you
// also need to call CountRows() (e.g. to get the total rows when a limit is being
// used) then it is more efficient to use the WikiDB_Query class directly, as it
// will be able to handle this with just a single query, rather than requiring one
// query per call.
	function GetRows($Offset = 0, $Limit = 0) {
		$Query = new WikiDB_Query($this);
		return $Query->GetRows($Offset, $Limit);
	}

////////////////////////////////////////
// Field Information
////////////////////////////////////////

// FieldIsDefined()
// Returns true if the field is defined in the table definition and false if it is
// not (or if there is no table definition).
	function FieldIsDefined($FieldName) {
		$this->pLoadTableDef();

		if (array_key_exists($FieldName, $this->pFields))
			return true;
		else
			return false;
	}

// FieldIsAlias()
// Returns true if the field is defined in the table definition and is an alias for
// another field, or false if it is not defined or is not an alias (or if there is
// no table definition).
	function FieldIsAlias($FieldName) {
		if ($this->FieldIsDefined($FieldName)) {
			if (isset($this->pFields[$FieldName]['Alias']))
				return true;
			else
				return false;
		}
		else {
			return false;
		}
	}

// GetAlias()
// If the supplied field is defined as an alias then the name of the field it
// aliases is returned.  If the field is not defined, or is not an alias field,
// then the FieldName is returned untouched.  If the field is an alias for a field
// which is not defined, then the name of that undefined field will still be
// returned.
// Aliases are resolved recursively - if a loop is found, then the original
// FieldName is returned untouched.
	function GetAlias($FieldName) {
	// $CheckedFieldNames is an array holding all fields that have already been
	// checked as part of the alias resolution process.  We use this to check that
	// we haven't got into a loop.
		$CheckedFieldNames = array($FieldName);

	// So long as the field is an alias, resolve it to the field it points to.
	// The loop means we will resolve chains of aliases until we get to an actual
	// field that really exists.
		while ($this->FieldIsAlias($FieldName)) {
			$FieldName = $this->pFields[$FieldName]['Alias'];
		// If the field is in the array of checked field names, then we have got
		// ourselves into a loop, in which case we just return the original field
		// name unmodified.
			if (in_array($FieldName, $CheckedFieldNames))
				return $CheckedFieldNames[0];
		}

	// Once all aliases are resolved, return the actual field name.
		return $FieldName;
	}

// GetAliasRev()
// Returns an array containing the field names of all fields that are defined as
// aliases of the given field, including the field itself (therefore it will always
// contain at least one element).  Aliases are resolved recursively, avoiding loops
// (but including all elements in the loop if one is encountered).
// TODO: Better name?
	function GetAliasRev($FieldName) {
		$this->pLoadTableDef();

	// The list of aliases always has at least one item - the current field.
		$Aliases = array($FieldName);

	// Unchecked aliases holds an array of fields which have yet to be
	// checked.  Whenever a new alias is found it is added to the list, and then
	// it too is checked for fields that redirect to it.  It acts as a FIFO queue
	// and we keep going until it's empty.
		$UncheckedFields = array($FieldName);

		while (count($UncheckedFields) > 0) {
			$ThisField = array_shift($UncheckedFields);

		// Get all fields that point to the current field and add any we
		// have not encountered before to our array of aliases, and to the list
		// of items still to be checked.
			foreach ($this->pFields as $Field) {
				if (!in_array($Field['FieldName'], $Aliases)) {
					if (isset($Field['Alias']) && $Field['Alias'] == $ThisField) {
						$Aliases[] = $Field['FieldName'];
						$UncheckedFields[] = $Field['FieldName'];
					}
				}
			}
		}

		return $Aliases;
	}

// GetAllAliases()
// Returns an array containing all fields which are aliases of this field (directly
// or indirectly) and all fields that this is an alias of.  In other words, all the
// fields for which the given field name can be considered a synonym.
// The returned array will always contain the supplied field name, and the first
// entry in the array will be the field that all other entries resolve to.
	function GetAllAliases($FieldName) {
		$FieldName = $this->GetAlias($FieldName);
		return $this->GetAliasRev($FieldName);
	}

// GetFields()
// Returns an array containing all fields defined for this table (or an empty array
// if none are defined).  If $IncludeAliases is true (the default) then all fields
// in the definition are returned.  If not then aliases are removed from the array
// before it is returned.
	function GetFields($IncludeAliases = true) {
		$this->pLoadTableDef();

		if ($IncludeAliases) {
			return $this->pFields;
		}
		else {
			$Fields = array();
			foreach ($this->pFields as $Key => $Data) {
				if (!isset($Data['Alias']))
					$Fields[$Key] = $Data;
			}
			return $Fields;
		}
	}

// Returns true if $FieldValue is a valid value for the specified field.
// Returns false if validation fails.
// If $FieldName doesn't exist, then the value is always valid.
	function IsValidFieldValue($FieldName, $FieldValue) {
		$this->pLoadTableDef();

		if (!isset($this->pFields[$FieldName])
			|| !isset($this->pFields[$FieldName]['Type']))
		{
			return true;
		}

		return WikiDB_TypeHandler::IsOfType($this->pFields[$FieldName]['Type'],
											$FieldValue);
	}

// GetFieldType()
// Returns the type of the specified field, if the field is defined (and has a type).
// If the field is not defined in the table and $GuessFromValue is not null then
// this value is used to try and guess the type of the field.  Otherwise the
// default field type ("wikistring") is returned.
	function GetFieldType($FieldName, $GuessFromValue = null) {
		$this->pLoadTableDef();

		if (!isset($this->pFields[$FieldName])
			|| !isset($this->pFields[$FieldName]['Type']))
		{
			if ($GuessFromValue === null)
				$Type = "wikistring";
			else
				$Type = WikiDB_TypeHandler::GuessType($GuessFromValue);
		}
		else {
			$Type = $this->pFields[$FieldName]['Type'];
		}

		return $Type;
	}

// FormatFieldForSorting()
// Returns the specified field value, in the sort/search-format for the given
// field name.  If the named field has a definition it is formatted appropriately
// for that definition, otherwise it returns the original string.  In both cases
// the string is truncated to 255 characters, in order to fit in a VARCHAR(255).
// Field aliases are fully resolved by this function.
	function FormatFieldForSorting($FieldName, $FieldValue) {
		$this->pLoadTableDef();

		$FieldName = $this->GetAlias($FieldName);

		if (isset($this->pFields[$FieldName])
			&& isset($this->pFields[$FieldName]['Type']))
		{
			$FieldValue = WikiDB_TypeHandler::FormatTypeForSorting(
												$this->pFields[$FieldName]['Type'],
												$FieldValue
											);
		}

	// Ensure string is no more than 255 characters.
	// Note that substr() returns false on an empty string, so we only perform the
	// truncation if the string is non-empty.
		if ($FieldValue != "")
			$FieldValue = substr($FieldValue, 0, 255);

		return $FieldValue;
	}

// FormatFieldForDisplay()
// Returns the specified field value, in the display-format for the given
// field name.  If the named field has a definition it is formatted appropriately
// for that definition, otherwise it returns the original string.
// Field aliases are fully resolved by this function.
// Multi-value fields will have each instance of the value formatted appropriately,
// but the array itself will not be flattened.
	function FormatFieldForDisplay($FieldName, $FieldValue) {
		$this->pLoadTableDef();

		$FieldName = $this->GetAlias($FieldName);

		$Type = "";
		$Options = array();
		if (isset($this->pFields[$FieldName])
			&& isset($this->pFields[$FieldName]['Type']))
		{
			$Type = $this->pFields[$FieldName]['Type'];
			if (isset($this->pFields[$FieldName]['Options']))
				$Options = $this->pFields[$FieldName]['Options'];
		}

	// Multi-value fields need to have each value formatted.
		if (is_array($FieldValue)) {
			foreach ($FieldValue as $Index => $RealValue) {
				$RealValue = $this->pFormatFieldValueForDisplay($RealValue, $Type,
																$Options);
				$FieldValue[$Index] = $RealValue;
			}
		}
	// Single-value fields have just one field to format.
		else {
			$FieldValue = $this->pFormatFieldValueForDisplay($FieldValue, $Type,
															 $Options);
		}

		return $FieldValue;
	}


// pFormatFieldValueForDisplay()
// Helper function to format a single field value (as opposed to
// FormatFieldForDisplay() which also handles multi-value fields).
	function pFormatFieldValueForDisplay($FieldValue, $Type, $Options) {
		if ($Type == "")
			$Type = WikiDB_TypeHandler::GuessType($FieldValue);

		return WikiDB_TypeHandler::FormatTypeForDisplay($Type, $FieldValue,
														$Options);
	}

// FormatFieldArrayForDisplay()
// Takes an array of FieldName => FieldValue pairs and returns the same array with
// all values formatted appropriately for display.  See FormatFieldForDisplay() for
// details.
	function FormatFieldArrayForDisplay($arrFields) {
		foreach ($arrFields as $FieldName => $Value) {
			$Value = $this->FormatFieldForDisplay($FieldName, $Value);
			$arrFields[$FieldName] = $Value;
		}

		return $arrFields;
	}

} // END: class WikiDB_Table
