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
 * Class to represent the results data of a WikiDB query.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2439 $
 */

class WikiDB_QueryResult {

// WikiDB_Table object of the table that this data comes from.
// In the future, when join expressions are enabled, this will need to be more
// sophisticated, but for now a single Table object is sufficient.
	var $pTable;

// Internal array containing the data rows for the result.
// For each entry, $Row['Data'] is an array of data rows (containing
// FieldName => $Value pairs) and $Row['MetaData'] is a similar
// array containing meta-data for the row.  All fields in 'MetaData'
// begin with an underscore.
	var $parrRows;

// Other information relating to the result if created from a query.
// If this was created from a <data> tag then they are set to basic default values
// that don't necessarily have much meaning in that context.
	var $pOffset;
	var $pLimit;
	var $pobjQuery;

// Variables to store derived data, so they don't need to be calculated multiple
// times.
	var $parrUndefinedFields = array();
	var $parrMigratedFields = array();

/////////////////////////////
// CONSTRUCTOR
// Never instantiate this class directly.  It should only be instantiated from one
// of the two factory functions, below.

	function __construct($Table, $arrRows, $Offset = 0, $Limit = null,
						 $objQuery = null)
	{
		$this->pTable = $Table;
		$this->parrRows = $arrRows;
		$this->pOffset = $Offset;
		$this->pLimit = $Limit;
		$this->pobjQuery = $objQuery;

	// Perform an analysis of the supplied data, to populate our internal arrays.
	// There doesn't seem any point deferring this, as it will always need to happen
	// at some point.
		$this->pAnalyseData();
	}

	static function NewFromDataArray($Table, $arrData, $SourceArticle) {
	// Add meta-data fields.
		foreach ($arrData as $Key => $Row) {
			$arrData[$Key] = array('Data' => $Row);
			$arrData[$Key]['MetaData']['_SourceArticle']
										= $SourceArticle->getFullText();
			$arrData[$Key]['MetaData']['_Row'] = $Key + 1;
		}

		return new self($Table, $arrData);
	}

	static function NewFromQuery($arrRows, $Offset, $Limit, $objQuery) {
		$Table = $objQuery->GetTable();
		return new self($Table, $arrRows, $Offset, $Limit, $objQuery);
	}

/////////////////////////////
// INITIALISATION
// We perform a initialisation on object creation, rather than doing it lazily,
// as there are unlikely to be situations where this information isn't required.

	function pAnalyseData() {
		foreach ($this->parrRows as $Key => $Row) {
			foreach ($Row['Data'] as $FieldName => $FieldValue) {
				if ($this->pTable->FieldIsAlias($FieldName)) {
					$Alias = $this->pTable->GetAlias($FieldName);
					$this->parrMigratedFields[$Key][$FieldName] = $Alias;
				}
				elseif (!$this->pTable->FieldIsDefined($FieldName)
						&& !in_array($FieldName, $this->parrUndefinedFields))
				{
					$this->parrUndefinedFields[] = $FieldName;
				}
			}
		}
	}

/////////////////////////////
// PROPERTY ACCESSORS

	function GetTable() {
		return $this->pTable;
	}

	function GetRows() {
		return $this->parrRows;
	}

	function GetOffset() {
		return $this->pOffset;
	}

	function GetLimit() {
		return $this->pLimit;
	}

// GetQueryObject()
// Returns the WikiDB_Query object from which this was run, if this result was
// created from a query.
// Returns null if this was created from the contents of a <data> tag.
	function GetQueryObject() {
		return $this->pobjQuery;
	}

/////////////////////////////
// DERIVED PROPERTIES

	function CountRows() {
		return count($this->parrRows);
	}

// GetMetaDataFields()
// Returns an array containing the names of all MetaData fields in the data set.
// The names include the underscore prefix.
	function GetMetaDataFields() {
	// If there are rows defined, return the meta-data fields from the first row.
	// We assume all rows are equal, which at the moment they are.
		if (isset($this->parrRows[0])) {
			return array_keys($this->parrRows[0]['MetaData']);
		}
	// Otherwise, return an empty array.
	// TODO: Should we just hard-code the list of meta-data fields?  Currently they
	//		 are all known, and I suspect this will remain the case if more are
	//		 added in the future.
		else {
			return array();
		}
	}

// GetDefinedFields()
// Returns an array containing the names of all fields defined for the table.
	function GetDefinedFields() {
		$arrFieldDefs = $this->pTable->GetFields(false);

		$arrDefinedFields = array();
		foreach ($arrFieldDefs as $Field) {
			$arrDefinedFields[] = $Field['FieldName'];
		}

		return $arrDefinedFields;
	}

	function GetUndefinedFields() {
		return $this->parrUndefinedFields;
	}

	function HasUndefinedFields() {
		if (count($this->parrUndefinedFields) > 0)
			return true;
		else
			return false;
	}

	function GetMigratedFields($RowID = null) {
		if ($RowID === null)
			return $this->parrMigratedFields;
		elseif (isset($this->parrMigratedFields[$RowID]))
			return $this->parrMigratedFields[$RowID];
		else
			return array();
	}

	function HasMigratedFields($RowID = null) {
		if (count($this->GetMigratedFields($RowID)) > 0)
			return true;
		else
			return false;
	}

/////////////////////////////
// ROW/FIELD ACCESSING

// GetFieldsInRow()
// Returns an array containing the names of all fields that are defined in the
// specified row.
// You can set $IgnoreMetaData to true to omit the meta-data fields from the result.
	function GetFieldsInRow($RowID, $IgnoreMetaData = false) {
	// If the row doesn't exist, raise a notice-level error (so it is logged, but
	// doesn't intrude on the user) and return an empty array.
		if (!isset($this->parrRows[$RowID])) {
			trigger_error("Non-existent row in call to " . __CLASS__ . "::"
						  . __FUNCTION__ . "(): " . $RowID, E_USER_NOTICE);
			return array();
		}

	// Otherwise, get the field names from the defined data rows.
		$arrFields = array_keys($this->parrRows[$RowID]['Data']);

	// Unless we've been asked to ignore them, merge in the meta-data field names,
	// such that they are listed first.
		if (!$IgnoreMetaData) {
			$arrFields = array_merge(
								array_keys($this->parrRows[$RowID]['MetaData']),
								$arrFields
							);
		}

	// Return the result.
		return $arrFields;
	}

// GetRawFieldValue()
// Returns the raw field value for the specified field in the specified row.
// This is the value that was entered into the article, without any type-based
// tidying.
// For multi-value fields, the function will return an array of values.  For
// single-value fields, the result will be the string that was entered.
// This value shouldn't be shown to users, but we provide this as a public function
// as there may be places in the code that need to access the raw value directly.
// Field aliases will be resolved.  If there are multiple fields in the data that
// are aliases of one-another (or aliases of a common third field) then the alias
// will be resolved as follows: If the requested field has data defined, then
// this is returned.  Otherwise, if the real field name (the one the aliases resolve
// to) has data, this is returned.  If neither of those is the case, then other
// synonyms are checked in an arbitrary order until we find a match.
// If no match is find, an empty string is returned (as, conceptually, all possible
// fields exist whether or not they are defined in the schema and whether or not they
// have had any data added to them).
	function GetRawFieldValue($RowID, $FieldName) {
	// If the row doesn't exist, raise a notice-level error (so it is logged, but
	// doesn't intrude on the user) and return an empty string.
		if (!isset($this->parrRows[$RowID])) {
			trigger_error("Non-existent row in call to " . __CLASS__ . "::"
						  . __FUNCTION__ . "(): " . $RowID, E_USER_NOTICE);
			return "";
		}

	// If the field starts with an underscore, it is a meta-data field.
	// Otherwise, it is in the table's data array.
		if (substr($FieldName, 0, 1) == "_") {
			$SubArray = 'MetaData';
			$arrFieldNames = array($FieldName);
		}
		else {
			$SubArray = 'Data';
			$arrFieldNames = $this->pTable->GetAllAliases($FieldName);
		}

	// If the field exists, then return the value.  We need this check rather than
	// just looping over $arrFieldNames, to ensure that if there are multiple aliases
	// that point to the same field that we always return the requested one.
	// If we just checked the array, then the wrong one might be matched.
		if (isset($this->parrRows[$RowID][$SubArray][$FieldName])) {
			return $this->parrRows[$RowID][$SubArray][$FieldName];
		}
	// If the requested field isn't explicitly set, we resolve the aliases to find.
	// an equivalent field.  The real field name is first in the list, then any other
	// synonyms are given in no particular order.  The original field name will
	// be re-checked here, but it is probably simpler/faster to do this than to try
	// and remove it from the array (though, because we know there will always be at
	// least one field in the array - the requested field we just checked - we can
	// skip the re-checking in situations where there are no aliases defined).
		elseif (count($arrFieldNames) > 1) {
			foreach ($arrFieldNames as $FieldName) {
				if (isset($this->parrRows[$RowID][$SubArray][$FieldName])) {
					return $this->parrRows[$RowID][$SubArray][$FieldName];
				}
			}
		}

	// If the field is not defined, nor any of its aliases, return an empty string
	// as the default value for a field with no data.
		return "";
	}

// GetFieldValue()
// Returns the field value for the specified field in the specified row.
// This is value has been tidied according to the rules for the field's type (or
// if no field definition is set, the guessed type) and is the value that should
// be displayed to users.
// This value is currently always returned as wikitext.
// TODO: Extend our type-handling code so that we can provide a separate plain-text
//		 representation.
// TODO: Currently we collapse multi-value fields into a single string value.
//		 Is this appropriate?  The answer will depend on whether more flexibility
//		 is required by the code which calls this function.
	function GetFieldValue($RowID, $FieldName) {
		$Value = $this->GetRawFieldValue($RowID, $FieldName);

	// MetaData fields are handled individually, as they are not data fields.
		if (substr($FieldName, 0, 1) == "_") {
			switch ($FieldName) {
			// SourceArticle is turned into a link to the specified article.
				case "_SourceArticle":
					$Value = Title::newFromText($Value);
					$Value = '[[' . $Value->getFullText() . ']]';
					break;

			// Other fields don't need any special treatment, currently.
				default:
					break;
			}
		}
	// Data fields are formatted according to their field definition (or if the field
	// is not defined, the type is guessed from the data).
		else {
			$Value = $this->pTable->FormatFieldForDisplay($FieldName, $Value);
		}

	// Collapse multi-value fields into a single string value before returning.
	// TODO: Not sure if this is the right place for this - see header comment.
		return WikiDB_HTMLRenderer::CollapseFieldValue($Value);
	}

// GetFieldValueHTML()
// Returns the field value for the specified field in the specified row, in HTML
// format.  This function takes the tidied value and expands any wiki-text it
// contains, to give a value suitable for outputting as HTML.
	function GetFieldValueHTML($RowID, $FieldName) {
		return WikiDB_Parse($this->GetFieldValue($RowID, $FieldName));
	}

// GetFieldValues()
// Returns an array containing FieldName => FieldValue pairs representing the data
// in the specified row.  $arrFields may be set to an array of field names, in which
// case the array will contain an entry for each item in that array, regardless of
// whether the field exists or not (either in the table definition or in the data).
// If $arrFields is omitted, then the function returns all fields explicitly defined
// in the data for the row.
// The field values are the same as are returned individually from GetFieldValue().
	function GetFieldValues($RowID, $arrFields = null) {
	// If the row doesn't exist, raise a notice-level error (so it is logged, but
	// doesn't intrude on the user).  We still drop-through and process the row,
	// to save on duplication (as we want to ensure the result is populated for
	// all specified fields).
		if (!isset($this->parrRows[$RowID])) {
			trigger_error("Non-existent row in call to " . __CLASS__ . "::"
						  . __FUNCTION__ . "(): " . $RowID, E_USER_NOTICE);
		}
	// Otherwise, if $arrFields is blank, populate it from the specified row
	// data, so it contains all defined fields.
		elseif ($arrFields === null) {
			$arrFields = $this->GetFieldsInRow($RowID);
		}

	// Build the results array.
		$arrResults = array();
		foreach ($arrFields as $FieldName) {
		// We handle fields specially if the row doesn't exist, so we don't also log
		// a notice-level error for each field (as we have already logged the error
		// for the whole row, above).
			if (!isset($this->parrRows[$RowID]))
				$FieldValue = "";
			else
				$FieldValue = $this->GetFieldValue($RowID, $FieldName);

			$arrResults[$FieldName] = $FieldValue;
		}

		return $arrResults;
	}

	function GetMetaDataFieldValues($RowID) {
		return $this->GetFieldValues($RowID, $this->GetMetaDataFields());
	}

	function GetDefinedFieldValues($RowID) {
		return $this->GetFieldValues($RowID, $this->GetDefinedFields());
	}

	function GetUndefinedFieldValues($RowID) {
		return $this->GetFieldValues($RowID, $this->GetUndefinedFields());
	}

// GetNormalisedRow()
// Returns the row data normalised into a flat structure, with a certain amount
// of alias resolution.
// Same as GetFieldValues(), except that aliases are resolved, so the returned array
// will return entries for each synonym of a defined field.
// TODO: Should probably be combined with GetFieldValues(), to remove the
//		 duplication.
// TODO: Currently we collapse multi-value fields into a single string value.
//		 Is this appropriate?  The answer will depend on whether more flexibility
//		 is required by the code which calls this function.
	function GetNormalisedRow($RowID) {
	// If the row doesn't exist, raise a notice-level error (so it is logged, but
	// doesn't intrude on the user) and return an empty array.
		if (!isset($this->parrRows[$RowID])) {
			trigger_error("Non-existent row in call to " . __CLASS__ . "::"
						  . __FUNCTION__ . "(): " . $RowID, E_USER_NOTICE);
			return array();
		}

	// Flatten the array for this row, so it contains all MetaData and Data fields in
	// a single array.  Data fields have their aliases resolved, so they are stored
	// under the actual field name that the data is really in.
		$FormattedData = array();
		$Data = $this->parrRows[$RowID];

		foreach ($Data['MetaData'] as $Field => $Value)
			$FormattedData[$Field] = $Value;
		foreach ($Data['Data'] as $Field => $Value)
			$FormattedData[$this->pTable->GetAlias($Field)] = $Value;

	// If the table contains aliases, we also add all alternative names for populated
	// fields, so that these can be used interchangeably.
		$Fields = $this->pTable->GetFields(true);
		foreach ($Fields as $FieldData) {
			if (!isset($FormattedData[$FieldData['FieldName']])) {
				if (isset($FieldData['Alias'])) {
					if (isset($FormattedData[$FieldData['Alias']])) {
						$FormattedData[$FieldData['FieldName']]
										= $FormattedData[$FieldData['Alias']];
					}
					else {
						$FormattedData[$FieldData['FieldName']] = "";
					}
				}
				else {
					$FormattedData[$FieldData['FieldName']] = "";
				}
			}
		}

	// Ensure the data is formatted appropriately.
	// TODO: This is not the way to handle this.  It was copied from the old
	//		 method and has yet to be updated.
		$FormattedData = $this->pTable->FormatFieldArrayForDisplay($FormattedData);

	// Collapse multi-value fields into a single string value.
	// TODO: Not sure if this is the right place for this - see header comment.
		foreach ($FormattedData as $FieldName => $Value) {
			$FormattedData[$FieldName]
							= WikiDB_HTMLRenderer::CollapseFieldValue($Value);
		}

		return $FormattedData;
	}

} // END: class WikiDB_QueryResult
