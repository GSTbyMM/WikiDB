<?php
/**
 * WikiDB unit tests for handling custom data types.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2439 $
 */

/**
 * WikiDB_TypeHandlerTest class.
 * This class tests the WikiDB_TypeHandler class, to check that the API for custom
 * types is implemented correctly.
 * The separate WikiDB_BuiltInTypesTest class is used to run tests against the
 * built-in types, to check they are implemented correctly and that the expected
 * built-in types are correctly defined.
 *
 * @group WikiDB
 */
class WikiDB_TypeHandlerTest extends MediaWikiIntegrationTestCase {

/**
 * Check that the NormaliseTypeName() function is working as expected, as this is a
 * public function, also used to format undefined type names.
 * All the test data supplied by the data provider should normalise to the same
 * type name, "wikistring", therefore this is not included as a function argument.
 * This means we can share the provider with testBuiltInTypeNamesAreNormalised().
 * @dataProvider provideCaseInsensitiveTypeNameVariants
 */
	public function testNormaliseTypeName($TypeName) {
		$this->assertSame("wikistring",
						  WikiDB_TypeHandler::NormaliseTypeName($TypeName));
	}

/**
 * Check that the retrieval of data types is handled in a case-insensitive manner.
 * This test is different to testNormaliseTypeName() as it checks that type retrieval
 * is correctly applying the normalisation, whereas the former just checks that
 * normalisation is implemented correctly (though there is obviously some overlap).
 * @dataProvider provideCaseInsensitiveTypeNameVariants
 */
	public function testBuiltInTypeNamesAreNormalised($TypeName) {
		$this->assertTrue(WikiDB_TypeHandler::TypeDefined($TypeName));
	}

	public static function provideCaseInsensitiveTypeNameVariants() {
		$arrTypes = array(
					"wikistring",
					"WIKISTRING",
					"Wikistring",
					"wikiString",
					"wIkIsTrInG",
					"wIkIsTrInG   ",
					"   wIkIsTrInG",
					"\twikistring",
					"wikistring\r",
					"wikistring\n",
					" \twikistring \r\n",
				);

	// Tidy the above, simplified, array structure into the format that PHPUnit
	// requires (i.e. each entry as an array of arguments).
		foreach ($arrTypes as $Key => $Value) {
			$arrTypes[$Key] = array($Value);
		}

		return $arrTypes;
	}

/**
 * Ensure that GetDefinedTypes() returns an array.
 */
	public function testGetDefinedTypes() {
		$Msg = "GetDefinedTypes() must return an array";
		$this->assertTrue(is_array(WikiDB_TypeHandler::GetDefinedTypes()), $Msg);
	}

/**
 * Check that defining a new type works.
 * The test adds a new type and then checks that TypeDefined() returns true.
 * The name is generated in a way that ensures it does not already exist, in case
 * there are user-defined types.  The callback is the mock type handler (defined at
 * the end of this file).
 * The test returns the name of the newly-added type, so it may be set as a
 * dependency for other tests which exercise the behaviour of custom types.
 */
	public function testAbleToDefineNewType() {
		$TypeName = self::pGetUndefinedTypeName();

	// Assert that the type is not already defined.
	// Guaranteed, given the above, but important for documenting the test.  Plus it
	// guards against future changes that may mean it is no longer guaranteed.
		$Msg = "'" . $TypeName . "' must not already be defined as a type";
		$this->assertFalse(WikiDB_TypeHandler::TypeDefined($TypeName), $Msg);

	// We also check that the type is not in the list defined by GetDefinedTypes().
	// This is also redundant (as per comment for previous assertion) but helps
	// validate that nothing slips through the cracks in terms of the later
	// assertions relating to this function.
		$arrOriginalTypes = WikiDB_TypeHandler::GetDefinedTypes();
		$Msg = "Error generating unique name - already in the list returned by "
			 . "GetDefinedTypes()";
		$this->assertFalse(in_array($TypeName, $arrOriginalTypes), $Msg);

	// Add the type.
		$Callback = array("WikiDB_TypeHandler_Mock", "StandardTypeHandler");
		WikiDB_TypeHandler::AddType($TypeName, $Callback);

	// Check that the new type is now defined.
		$Msg = "'" . $TypeName . "' must be defined after call to AddType()";
		$this->assertTrue(WikiDB_TypeHandler::TypeDefined($TypeName), $Msg);

	// Check that GetDefinedTypes() reports the new type correctly.
		$arrNewTypes = WikiDB_TypeHandler::GetDefinedTypes();

		$OriginalTypeCount = count($arrOriginalTypes);
		$NewTypeCount = count($arrNewTypes);

		$Msg = "GetDefinedTypes() should return one more type than previously";
		$this->assertEquals($OriginalTypeCount + 1, $NewTypeCount, $Msg);

		$Msg = "The new type should be in the list returned by GetDefinedTypes()";
		$this->assertTrue(in_array($TypeName, $arrNewTypes), $Msg);

	// Return the name of the newly-defined type, for use in other tests.
		return $TypeName;
	}

/**
 * Check that custom data types are also handled in a case-insensitive manner.
 * @depends testAbleToDefineNewType
 *
 * TODO: Can we test type creation with non-normalised type names, e.g. "CuStOmTyPe"?
 *		 Would involve creating multiple types to check the different variants,
 *		 which is a bit messy as there's currently no way to undefine types.
 */
	public function testCustomTypeNamesAreNormalised($TypeName) {
	// Assert the specified type is defined, otherwise our other assertions will be
	// misreporting normalisation errors, which are actually undefined type errors.
		$Msg = "Checking unmodified type name is defined: " . $TypeName;
		$this->assertTrue(WikiDB_TypeHandler::TypeDefined($TypeName), $Msg);

	// Simple case difference.
		$TypeName = ucfirst($TypeName);
		$Msg = "Checking that value is normalised by TypeDefined(): " . $TypeName;
		$this->assertTrue(WikiDB_TypeHandler::TypeDefined($TypeName), $Msg);

	// Full case difference.
		$TypeName = strtoupper($TypeName);
		$Msg = "Checking that value is normalised by TypeDefined(): " . $TypeName;
		$this->assertTrue(WikiDB_TypeHandler::TypeDefined($TypeName), $Msg);

	// White-space differences.
		$TypeName = "  " . $TypeName . "\t";
		$Msg = "Checking that value is normalised by TypeDefined(): " . $TypeName;
		$this->assertTrue(WikiDB_TypeHandler::TypeDefined($TypeName), $Msg);
	}

//////////////////////////////////////////////////////////////////////
// TEST HOW API HANDLES NON-DEFINED TYPES

/**
 * The IsOfType() function should always return true if the specified type is not
 * defined.
 */
	public function testIsOfTypeForUnknownType() {
		$TypeName = self::pGetUndefinedTypeName();
		$this->assertTrue(WikiDB_TypeHandler::IsOfType($TypeName, "foo"));
	}

/**
 * The FormatTypeForDisplay() function should always return the unmodified value
 * if the specified type is not defined.
 */
	public function testFormatTypeForDisplayForUnknownType() {
		$TypeName = self::pGetUndefinedTypeName();

		$Value = "foo";
		$Result = WikiDB_TypeHandler::FormatTypeForDisplay($TypeName, $Value);

		$this->assertEquals($Value, $Result);
	}

/**
 * The FormatTypeForSorting() function should always return the unmodified value
 * if the specified type is not defined.
 */
	public function testFormatTypeForSortingForUnknownType() {
		$TypeName = self::pGetUndefinedTypeName();

		$Value = "foo";
		$Result = WikiDB_TypeHandler::FormatTypeForSorting($TypeName, $Value);

		$this->assertEquals($Value, $Result);
	}

//////////////////////////////////////////////////////////////////////
// TEST HOW API HANDLES USER-DEFINED TYPES

/**
 * Checks that calls to IsOfType() trigger the expected API calls to our custom
 * type.
 * @depends testAbleToDefineNewType
 */
	public function testIsOfType($TypeName) {
		WikiDB_TypeHandler_Mock::ClearCalledActions();

		$Value = "foo";
		WikiDB_TypeHandler::IsOfType($TypeName, $Value);

		$this->pCheckAPICallResult(WIKIDB_Validate, $Value, array());
	}

/**
 * Checks that calls to FormatTypeForDisplay() for invalid values.
 * @depends testAbleToDefineNewType
 */
	public function testFormatTypeForDisplayWithInvalidValue($TypeName) {
		WikiDB_TypeHandler_Mock::ClearCalledActions();

	// Return false from the validation function, so the formatting function is not
	// called.
		WikiDB_TypeHandler_Mock::SetValuesToReturn(false);

		$Value = "foo";
		$Result = WikiDB_TypeHandler::FormatTypeForDisplay($TypeName, $Value);

	// Invalid values are rendered as "??" automatically, and the format function
	// is not called.
		$this->assertSame("??", $Result);

		$this->pCheckAPICallResult(
									WIKIDB_Validate, $Value, array()
								);
	}

/**
 * Checks that calls to FormatTypeForDisplay() trigger the expected API calls to our
 * custom type, with no options specified.
 * @depends testAbleToDefineNewType
 */
	public function testFormatTypeForDisplayWithoutOptions($TypeName) {
		WikiDB_TypeHandler_Mock::ClearCalledActions();

	// We need the validation action to return true, otherwise the display action
	// is not called.
		WikiDB_TypeHandler_Mock::SetValuesToReturn(true);

		$Value = "foo";
		WikiDB_TypeHandler::FormatTypeForDisplay($TypeName, $Value);

		$this->pCheckAPICallResult(
									WIKIDB_Validate, $Value, array(),
									WIKIDB_FormatForDisplay, $Value, array()
								);
	}

/**
 * Checks that calls to FormatTypeForDisplay() trigger the expected API calls to our
 * custom type, with options specified.
 * @depends testAbleToDefineNewType
 */
	public function testFormatTypeForDisplayWithOptions($TypeName) {
		WikiDB_TypeHandler_Mock::ClearCalledActions();

	// We need the validation action to return true, otherwise the display action
	// is not called.
		WikiDB_TypeHandler_Mock::SetValuesToReturn(true);

		$Value = "foo";
		$arrOptions = array("1", "2", "3");
		WikiDB_TypeHandler::FormatTypeForDisplay($TypeName, $Value, $arrOptions);

		$this->pCheckAPICallResult(
									WIKIDB_Validate, $Value, array(),
									WIKIDB_FormatForDisplay, $Value, $arrOptions
								);
	}

/**
 * Checks that calls to FormatTypeForSorting() trigger the expected API calls to our
 * custom type.
 * @depends testAbleToDefineNewType
 */
	public function testFormatTypeForSorting($TypeName) {
		WikiDB_TypeHandler_Mock::ClearCalledActions();

		$Value = "foo";
		WikiDB_TypeHandler::FormatTypeForSorting($TypeName, $Value);

		$this->pCheckAPICallResult(
									WIKIDB_FormatForSorting, $Value, array()
								);
	}

/**
 * Checks that calls to GuessType() trigger the expected API calls to our custom
 * type.
 * @depends testAbleToDefineNewType
 */
	public function testGuessType($TypeName) {
		WikiDB_TypeHandler_Mock::ClearCalledActions();

		$Value = "foo";
		WikiDB_TypeHandler::GuessType($Value);

		$this->pCheckAPICallResult(
									WIKIDB_GetSimilarity, $Value, array()
								);
	}

///////////////////////////////////////////////////////////
// HELPER FUNCTIONS

// pGetUndefinedTypeName()
// Returns an auto-generated name for a type, which is guaranteed not to be
// currently defined.
	private static function pGetUndefinedTypeName() {
	// Set a base type name, which is what will be used in most cases.
	// We use a static variable to track this, so we don't repeat work each time the
	// function is called.
		static $TypeName = 'phpunittest_mocktype';

	// Ensure the type is not already defined, by repeatedly appending an 'x' until
	// we find an instance that is not already defined.
	// This is just a safety net against the very unlikely situation that a
	// user-defined type has a name clash.
		while (WikiDB_TypeHandler::TypeDefined($TypeName)) {
			$TypeName .= "x";
		}

		return $TypeName;
	}

// pCheckAPICallResult()
// Asserts that the set of actions reported by the WikiDB_TypeHandler_Mock class
// matches the set of actions passed into the function.
// The function signature defines the method of specifying a single action, and this
// set of 3 arguments may be repeated as many times as necessary to cover all
// expected actions (as in some cases more than one callback will be triggered).
	private function pCheckAPICallResult($ExpectedAction, $ExpectedValue,
										 $ExpectedOptions)
	{
	// Arguments come in batches of three, allowing us to easily define multiple
	// callbacks that we are expecting to see.
		$arrArgs = func_get_args();

		$arrExpectedCalls = array();
		while (count($arrArgs) > 0) {
			$arrExpectedCalls[] = array(
										array_shift($arrArgs),
										array_shift($arrArgs),
										array_shift($arrArgs),
									);
		}

	// Assert that the expected action(s), as passed into the function, match the
	// actual actions, as reported by the WikiDB_TypeHandler_Mock class.
		$arrActualCalls = WikiDB_TypeHandler_Mock::GetCalledActions();
		$this->assertSame($arrExpectedCalls, $arrActualCalls);
	}

} // END class WikiDB_TypeHandlerTest

///////////////////////////////////////////////////////////////////
// WikiDB_TypeHandler_Mock class

// This is a dummy class for testing our type handler, to ensure the expected
// actions are triggered in the type handler when the applicable external functions
// are called.
// Usage:
// * Ensure the type is registered (e.g. by using @depends testAbleToDefineNewType).
// * Call WikiDB_TypeHandler_Mock::ClearCalledActions() at the start of the
//   test.
// * Call WikiDB_TypeHandler_Mock::GetCalledActions() at the end of the test and
//	 assert that it matches the actions that you expected to be triggered.  The
//	 WikiDB_TypeHandlerTest::pCheckAPICallResult() function provides a helper
//	 function to simplify this check.

class WikiDB_TypeHandler_Mock {

	static $parrCalledActions = array();
	static $parrReturnValues = array();

	static function ClearCalledActions() {
		self::$parrCalledActions = array();
	}

	static function GetCalledActions() {
		return self::$parrCalledActions;
	}

	static function SetValuesToReturn($Value) {
		self::$parrReturnValues = func_get_args();
	}

	static function StandardTypeHandler($Action, $Value, $Options) {
		self::$parrCalledActions[] = func_get_args();

		return array_shift(self::$parrReturnValues);
	}

} // END class WikiDB_TypeHandler_Mock
