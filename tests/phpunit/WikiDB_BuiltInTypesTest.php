<?php
/**
 * WikiDB unit tests for the built-in data types.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2439 $
 */

/**
 * WikiDB_BuiltInTypesTest class.
 * This class tests the built-in type handlers that are provided with WikiDB
 * to ensure they operate as expected and that the expected types are defined.
 * Note that all these tests are run against the WikiDB_TypeHandler class - we do not
 * directly access the individual type handlers as this is an implementation detail
 * that is allowed to change (and general API compatibility is tested for elsewhere).
 *
 * IMPORTANT NOTE: This initial implementation of the unit tests is designed for
 * regression testing, so the tests are descriptive of current behaviour rather than
 * attempting to formalise the desired behaviour.
 * TODO: Once we have sufficient tests, they should be reviewed to work out what the
 *		 correct behaviour should be.  Where this doesn't match existing test data,
 *		 both code and tests should be updated, with appropriate change notices
 *		 included in the release notes.
 *
 * Some of the rendering depends on language, which has two implications:
 * TODO: Some of the tests will fail on non-English installations.  We should modify
 *		 the tests to ensure the wiki language (and any other wiki configuration)
 *		 doesn't affect the outcome.  I think the MediaWikiLangTestCase can probably
 *		 help with this.
 * TODO: Some of the types (e.g. the numeric types) are designed to be fully
 *		 internationalised, therefore we should include tests that allow us to tests
 *		 the output under a selection of other locales.  These tests should work
 *		 consistently on any language wiki, especially English so that I can run the
 *		 tests on my local wiki without requiring config changes.
 *
 * See the WikiDB_TypeHandlerTest class for general tests relating to the
 * type-handling system.
 *
 * @group WikiDB
 */
class WikiDB_BuiltInTypesTest extends MediaWikiIntegrationTestCase {

// Array containing all the built-in types.  We use an array defined in the test
// class, rather than pulling it from the code under test, as part of the purpose
// of the tests is to check that the expected data types are available.
	static $parrBuiltInTypes = array(
									"wikistring",
									"string",
									"number",
									"integer",
									"int",
									"link",
									"image",
								);

////////////////////////////////////////////////////////////////////////////////////
// TESTS FOR TypeDefined()

/**
 * Check that all documented built-in data types have been defined.
 * Although this is a test against a general function of the WikiDB_TypeHandler class
 * (as opposed to the type handler interface used to implement the individual types)
 * the test is covering two things: Is the function implemented correctly and are
 * all the expected built-in types defined.
 * If the test fails, then it could be a code error in that function or a missing
 * type definition, and there is no way for the test function to determine which of
 * these is the case.  Therefore, it would be legitimate to place the test in either
 * that class, or this.
 * As we will be needing the list of built-in types for other tests and also because
 * it feels like it makes a bit more logical sense, I have placed the test in this
 * class.
 * Note that it is not a test failure if there are additional types beyond those
 * that we expect to be present.
 * @dataProvider provideBuiltInTypes
 */
	public function testExpectedBuiltInTypesAreDefined($TypeName) {
		$this->assertTrue(WikiDB_TypeHandler::TypeDefined($TypeName));
	}

	public static function provideBuiltInTypes() {
		$arrTypes = self::$parrBuiltInTypes;

	// Tidy the above, simplified, array structure into the format that PHPUnit
	// requires (i.e. each entry as an array of arguments).
		foreach ($arrTypes as $Key => $Value) {
			$arrTypes[$Key] = array($Value);
		}

		return $arrTypes;
	}

////////////////////////////////////////////////////////////////////////////////////
// TESTS FOR IsOfType() AND FormatTypeForDisplay()
// These two functions use similar test data, so are grouped together.

/**
 * testIsOfType()
 * Tests that the IsOfType() function returns the expected boolean value for all
 * built-in data types.
 * @dataProvider provideIsOfType
 */
	public function testIsOfType($TypeName, $Input, $ExpectedOutput) {
		$ActualOutput = WikiDB_TypeHandler::IsOfType($TypeName, $Input);
		$this->assertSame($ExpectedOutput, $ActualOutput);
	}

/**
 * testFormatTypeForDisplay()
 * Tests that the FormatTypeForDisplay() function returns the expected string for
 * all built-in data types.
 * @dataProvider provideFormatTypeForDisplay
 */
	public function testFormatTypeForDisplay($TypeName, $Input, $ExpectedOutput,
											 $arrOptions)
	{
		$ActualOutput = WikiDB_TypeHandler::FormatTypeForDisplay($TypeName, $Input,
																 $arrOptions);
		$this->assertSame($ExpectedOutput, $ActualOutput);
	}

/**
 * provideIsOfType()
 * Provides test data for testIsOfType().
 * This is a modified version of what is returned from pProvideSharedTestData(),
 * formatted appropriately for this test.
 */
	public static function provideIsOfType() {
		$arrTestData = self::pProvideSharedTestData();

	// For each row of test data, check the expected value.
	// If it is false, then the input is not valid for this type.  If it is not
	// false, it will contain the wikitext string that should be output when
	// displaying this value on the wiki.
	// We therefore convert all string values to true so that the expected output
	// reflects what IsOfType() will return (true for valid, false for invalid).
		foreach ($arrTestData as $Key => $arrTest) {
			if ($arrTestData[$Key][2] !== false)
				$arrTestData[$Key][2] = true;
		}

		return $arrTestData;
	}

/**
 * provideFormatTypeForDisplay()
 * Provides test data for testFormatTypeForDisplay().
 * This is a modified version of what is returned from pProvideSharedTestData(),
 * formatted appropriately for this test.
 */
	public static function provideFormatTypeForDisplay() {
		$arrTestData = self::pProvideSharedTestData();

	// For each row of test data, check the expected value.
	// If it is false, then the input is not valid for this type.  If it is not
	// false, it will contain the wikitext string that should be output when
	// displaying this value on the wiki.
	// We therefore convert all false values to an empty string so that the expected
	// output reflects what FormatTypeForDisplay() will return (as this always
	// returns a string).
	// TODO: Actually, the function returns "??", not an empty string.  It feels like
	//		 we should change this so the function returns null in this situation,
	//		 leaving it to the final rendering functions to decide how null values
	//		 should be represented.
		foreach ($arrTestData as $Key => $arrTest) {
			if ($arrTestData[$Key][2] === false)
				$arrTestData[$Key][2] = "??";
		}

		return $arrTestData;
	}

// pProvideSharedTestData()
// Provides an array of test data, which can be used (with slight modifications) for
// both the testIsOfType() and testFormatForDisplay() unit tests.
// Each element in the returned array contains four entries:
// * The data type that we are testing.
// * The input value, i.e. the value as it was entered on the wiki page.
//   Note that we don't include any tests for leading/trailing white-space, as
//   the WikiDB parser implementation means that this has been removed way before
//   it gets to any type-handling code, and therefore this is not something that
//   the type handlers need to deal with.
// * If the input is valid for this type, this field contains the wikitext that we
//	 expect to be generated by FormatTypeForDisplay() in order to render the text
//	 on the wiki.  If the input is not valid, this field will contain false.
//	 The two wrapper functions (which are the actual data providers) will manipulate
//	 this field appropriately for the function they are designed to test.
// * An array of additional field options, such as may be specified in the table
//	 definition.  Only used by FormatTypeForDisplay().
	private static function pProvideSharedTestData() {
	// The key is the type name and the value is the array of tests for that type.
	// For types that allow for parameters to be defined, these may be specified
	// as part of the type name by separating them with commas.  For example, to
	// specify the two parameters for the integer data type (min and max) you would
	// use "integer,0,10" (for example) as the array key.
	// In the array of tests, the index is the Input string (as if parsed from a
	// <data> tag) and the value is the expected output string (for displaying
	// on-wiki).  If the input string is not valid for this type, the expected output
	// must be set to boolean false (not to an empty string, which is what would
	// actually be output).  This is so that we can test IsOfType() and
	// FormatTypeForDisplay() using a single set of test data.
	// This array will be structured into the format required by the tests before it
	// is returned.  This alternative format is used to specify the data for ease of
	// maintenance and readability.
		$arrTestData = array(
			// Wikistring values.
			// Note that all inputs are valid wikistrings, so there are no invalid
			// values to check.
				'wikistring' => array(
							"" => "",
							"simple string" => "simple string",
							"'''Wikitext''' can contain <b>anything</b>"
								=> "'''Wikitext''' can contain <b>anything</b>",
						),

			// String values.
			// Note that all inputs are valid strings, so there are no invalid
			// values to check.
				'string' => array(
							"" => "",
							"simple string" => "<nowiki>simple string</nowiki>",

						// Markup that would break rendering, if not handled
						// correctly, should be escaped.
							"</nowiki>''Foo''"
									=> "<nowiki>&lt;/nowiki&gt;''Foo''</nowiki>",
						),

			// All number values - these tests apply to both Integer and Number
			// types.  Tests that only apply to one or other type are defined in the
			// relevant section.
				'all_numbers' => array(
							"" => false,
							"simple string" => false,

						// Numbers as words: not supported.
							"zero" => false,
							"twelve" => false,

						// Numbers at start of string.
							"23skidoo" => false,
							"23,skidoo" => false,

						// It's not valid to have multiple minus signs.
							"--123"	=> false,

						// Numeric components, but no digits.
							"-" => false,
							"," => false,
							"." => false,

						// Positive and negative whole numbers and zero.
							"23" => "23",
							"-3" => "-3",
							"0" => "0",
							"-0" => "0",

						// Leading zeros should be removed.
							"01" => "1",
							"000123" => "123",
							"-000123" => "-123",
							"000" => "0",

						// Larger numbers - these include formatting when output.
						// Note that the standard English formatting doesn't include
						// a thousands separator for 4-digit positive numbers,
						// however for some reason it is included for 4-digit
						// negative numbers.  It is possible that this is a hack to
						// ensure 4-digit years are formatted correctly, or perhaps
						// it is an error in the MW rendering code.
						// TODO: Should we 'correct' this (which would mean
						//		 overriding MW numeric formatting) or is this
						//		 inconsistency acceptable (as it is consistent with
						//		 built-in MediaWiki behaviour)?
							"123"			=> "123",
							"1234"			=> "1234",
							"12345"			=> "12,345",
							"123456"		=> "123,456",
							"123456789"		=> "123,456,789",
							"-1234"			=> "-1,234",
							"-12345"		=> "-12,345",
							"-123456789"	=> "-123,456,789",

						// Valid formatting is allowed on the input text.
							"1,234"			=> "1234",
							"12,345"		=> "12,345",
							"123,456,789"	=> "123,456,789",
							"-1,234"		=> "-1,234",
							"-12,345"		=> "-12,345",
							"-123,456,789"	=> "-123,456,789",

						// Invalid formatting is allowed on the input text, because
						// MediaWiki allows it.
						// TODO: Should we reject these?
							"12,34"			=> "1234",
							"1,2345"		=> "12,345",
							"-123,4"		=> "-1,234",

						// Should support very large numbers.
							"123456789123456789"	=> "123,456,789,123,456,789",
							"-123456789123456789"	=> "-123,456,789,123,456,789",
							"123456789123456789123456789123456789"
							   => "123,456,789,123,456,789,123,456,789,123,456,789",
							"-123456789123456789123456789123456789"
							   => "-123,456,789,123,456,789,123,456,789,123,456,789",
						),

			// Integer values.  Also aliased as 'int'.
			// The above 'all_numbers' tests will also be included.
				'integer' => array(
						// Non-integer numbers are invalid, even if the decimal
						// component is zero.
						// TODO: Should we allow decimals where the fractional part
						//		 is zero?
						// TODO: Should these be allowed, and simply rounded?
							"5.0"	=> false,
							"-5.0"	=> false,
							"5.00"	=> false,
							"-5.00"	=> false,
							"5.5"	=> false,

						// TODO: Test min/max options.
						),

			// Number values.
			// The above 'all_numbers' tests will also be included.
				'number' => array(
						// TODO: Test non-integer-specific inputs.

						// Numbers with decimal points always include the decimal
						// point in the output, even if zero.
							"5.0"	=> "5.0",
							"-5.0"	=> "-5.0",

						// Trailing zeros are removed.
							"5.00"	=> "5.0",
							"-5.00"	=> "-5.0",
							"123.4560"	=> "123.456",

						// Negative zero should be converted to positive zero.
						// We already handle the integer form of this test, above.
							"-0.0" => "0.0",

						// TODO: Test min/max options.
						),

				'image' => array(
							"" => "",
							"simple string" => "[[File:Simple_string|75px|]]",
						),
				'link' => array(
							"" => "",
							"simple string" => "[[simple string]]",
						),
			);

	// Merge the 'all_number' tests into both numeric types.
		$arrTestData['integer']
						= $arrTestData['all_numbers'] + $arrTestData['integer'];
		$arrTestData['number']
						= $arrTestData['all_numbers'] + $arrTestData['number'];
		unset($arrTestData['all_numbers']);

	// 'int' is an alias for 'integer', so we duplicate the 'integer' tests to ensure
	// that we have full coverage for both variants.
		$arrTestData['int'] = $arrTestData['integer'];

	// Convert the above array into the correct format for being passed to the tests.
		$arrTidiedTestData = array();
		foreach ($arrTestData as $TypeName => $arrTests) {
			$arrOptions = explode(",", $TypeName);
			$TypeName = array_shift($arrOptions);

			foreach ($arrTests as $Input => $ExpectedOutput) {
				$arrTidiedTestData[] = array($TypeName, $Input, $ExpectedOutput,
											 $arrOptions);
			}
		}

		return $arrTidiedTestData;
	}

//////////////////////////////////////////////////////////////////////
// TESTS FOR FormatTypeForSorting()
// We don't actually care how the fields are formatted internally.  All we care about
// is that the result is that the fields are sorted correctly.  Therefore these
// tests define a set of input values and the order that we expect them to be sorted
// in, and we compare this with the result of formatting the inputs using
// FormatTypeForSorting() and then performing a string sort.

/**
 * testFormatTypeForSorting()
 * Test that the FormatTypeForSorting() function returns values that result in the
 * correct sorting of elements.  We do not care what the actual values are, just how
 * they sort.
 * @dataProvider provideFormatTypeForSorting
 */
	public function testFormatTypeForSorting($TypeName, $arrInputs,
											 $arrExpectedSortOrder)
	{
	// Pass each of the inputs through FormatTypeForSorting() to generate an array
	// of sort keys for these inputs.  These will always be in a format appropriate
	// for a standard string sort.
		$arrActualSortKeys = array();
		foreach ($arrInputs as $Input) {
			$Output = WikiDB_TypeHandler::FormatTypeForSorting($TypeName, $Input);

		// Check the formatted value is not already in our array of outputs.
		// This check is including because the sorting is non-deterministic for
		// identical sort keys (both in the system and in the tests) so any such
		// test data would sometimes pass and sometimes fail, which is unhelpful.
		// It is therefore better that the test fails consistently to ensure we catch
		// the rogue data rather than, potentially, assuming it has passed.
			if (in_array($Output, $arrActualSortKeys)) {
				$this->fail("Invalid test inputs - each must result in a unique "
							. "sort key, otherwise the test is non-deterministic.  "
							. "Multiple instances found for sort key '" . $Output
							. "'.");
			}

			$arrActualSortKeys[] = $Output;
		}

	// Perform an associative sort on the array of sort keys.  An associative sort
	// means that the original Key => Value mapping is not lost.
	// This is a standard string sort, assumed to be the same as would be performed
	// at the database layer, which is where the extension does this.
	// TODO: Is that a safe assumption?  We shouldn't run these tests via the DB, so
	//		 if not, do we need to consider doing the sorting in PHP?
		asort($arrActualSortKeys);

	// Having sorted the array but kept the Key => Value mapping, the array keys now
	// match the format that the expected output was specified in, so get those
	// keys in order to do a comparison.
		$arrActualSortOrder = array_keys($arrActualSortKeys);

	// Check the expected ordering matches what we actually get.
		$this->assertSame($arrExpectedSortOrder, $arrActualSortOrder);
	}

/**
 * provideFormatTypeForSorting()
 * Provides test data for testFormatTypeForSorting().
 * The returned array elements each contain three entries: the data type being
 * tested, an array of inputs to be sorted and an array containing the expected
 * ordering of the inputs within the interface.
 * The inputs must all be valid string inputs for this type and must result in
 * unique sort keys
 */
	public static function provideFormatTypeForSorting() {
	// The key is the type name and the value is the array of tests for that type.
	// The test arrays consists of pairs of values (i.e. the first two entries in
	// the array constitute the first test, the next two the second test, etc.).
	// The first in each pair is an array of inputs to be sorted.  These
	// must be valid inputs for this type and each array must not contain
	// multiple inputs that generate the same sort key, or the test will fail.  This
	// is because the sorting is non-deterministic for identical sort keys (both in
	// the system and in the tests) so any duplicates would result in a test that
	// sometimes passes and sometimes fails, which is not acceptable.
	// The second in each pair is a comma-separated string containing the expected
	// ordering of the items in the input array.  For example if the array contains
	// "a", "c", "b" and the type handles these as normal strings, then the expected
	// ordering would be "0,2,1".
	// This array will be structured into the format required by the tests before it
	// is returned.  This alternative format is used to specify the data for ease of
	// maintenance and readability.
	// Each pair should be preceded by a comment line to indicate the purpose of the
	// test and to keep the data set readable.
		$arrTestData = array(
				'wikistring' => array(
						),
				'string' => array(
						),
				'integer' => array(
							array("5", "12", "0", "-5", "8"),
							"3,2,0,4,1",
						),
				'number' => array(
							array("5", "12", "0", "-5", "8"),
							"3,2,0,4,1",
						),
				'image' => array(
						),
				'link' => array(
						),
			);

	// 'int' is an alias for 'integer', so we duplicate the 'integer' tests to ensure
	// that we have full coverage for both variants.
		$arrTestData['int'] = $arrTestData['integer'];

	// Convert the above array into the correct format for being passed to the tests.
		$arrTidiedTestData = array();
		foreach ($arrTestData as $TypeName => $arrTests) {
		// The test data consists of pairs of entries, so we loop over it, extracting
		// the next pair on each iteration, and stopping when the array is empty.
			while (count($arrTests) > 0) {
			// The first of each pair of values is an array of inputs.
				$arrInputs = array_shift($arrTests);

			// The second of each pair of values is a comma-separated string of
			// the expected sort order (zero-indexed) which we convert to an array,
			// making sure the values in the array are proper integers, and not
			// strings (as they will be compared using a strict comparison).
				$arrExpectedSortOrder = array_shift($arrTests);
				$arrExpectedSortOrder = explode(",", $arrExpectedSortOrder);
				foreach ($arrExpectedSortOrder as $Key => $Value) {
					$arrExpectedSortOrder[$Key] = intval($Value);
				}

			// Add this test to the array.
				$arrTidiedTestData[] = array($TypeName, $arrInputs,
											 $arrExpectedSortOrder);
			}
		}

		return $arrTidiedTestData;
	}

//////////////////////////////////////////////////////////////////////
// TESTS FOR GuessTypes()
// The function has a second argument which allows us to limit the set of types
// that are checked.  This option is provided primarily for unit-testing purposes,
// and we use this argument - set to the list of built-in types - to ensure that
// user-defined types don't cause our tests to fail.

/**
 * @dataProvider provideGuessType
 */
	public function testGuessType($Input, $ExpectedType) {
	// We call the GuessType() function specifying that only the built-in types
	// should be checked.  This ensures that user-defined types don't cause our
	// tests to fail.
		$ActualType = WikiDB_TypeHandler::GuessType($Input, self::$parrBuiltInTypes);
		$this->assertSame($ExpectedType, $ActualType);
	}

	public static function provideGuessType() {
	// In the test data array, the key is a description of the test and the value
	// contains two arguments: the input text and the type that we expect to be
	// selected, from the set of built-in types (user types are ignored during this
	// test).
	// This initial set of tests was copied from the set of on-wiki unit tests at
	// http://kennel17.co.uk/testwiki/WikiDB/Data_type_unit_tests.  The on-wiki tests
	// cover a variety of things with a single input string, and therefore not all
	// of these tests are necessarily required for the GuessTypes() function (they
	// may be repeating themselves).  Also, there are probably some other test cases
	// that need to be added, to cover edge-cases or other potential ambiguities.
	// TODO: Review these tests and update, in the light of the above comment.
		$arrTestData = array(
					// Strings.
						"Empty text" => array("", "wikistring"),
						"Basic text"
							=> array("I want to be a lumberjack!", "wikistring"),
						"UTF-8 text"
							=> array("This Be œæ••±‘‘’Æ∏ØˆﬂıﬂN\"", "wikistring"),

					// Numbers.
						"Zero" => array("0", "integer"),
						"Integer" => array("34", "integer"),
						"Negative integer" => array("-21", "integer"),
						"Real number" => array("5.0", "number"),
						"Longer real number" => array("1245.32452", "number"),
						"Zero as real number" => array("0.0", "number"),
						"Negative real number" => array("-2343.981", "number"),
						"Real number without integer component"
							=> array(".43", "number"),
						"Real number without decimal component"
							=> array("3.", "wikistring"),
						"Number with more than one decimal point"
							=> array("1.2.3", "wikistring"),
						"String followed by number"
							=> array("Catch 22", "wikistring"),
						"Number followed by string"
							=> array("39 Steps", "wikistring"),
						"Number wrapped in text"
							=> array("Measure 4 Measure", "wikistring"),
						"Correctly-formatted number"
							=> array("12,345.67", "number"),
						"Correctly-formatted number in the thousands"
							=> array("1,234.567", "number"),
						"Correctly-formatted number with trailing zero"
							=> array("012,345.67", "number"),
						"Correctly-formatted number with leading zero"
							=> array("12,345.670", "number"),
						"Correctly-formatted integer"
							=> array("12,345", "integer"),
						"Correctly-formatted integer in the thousands"
							=> array("1,234", "integer"),
						"Correctly-formatted integer with leading zero"
							=> array("01,234", "integer"),
						"Incorrectly-formatted integer (short)"
							=> array("12,34", "wikistring"),
						"Incorrectly-formatted integer (long)"
							=> array("12,3456", "wikistring"),
						"Numbers separated by spaces"
							=> array("1 2 3", "wikistring"),
						"Numbers separated by commas"
							=> array("1,2,3", "wikistring"),
						"Leading zeros" => array("0005.4", "number"),
						"Leading zeros (integer)" => array("00035", "integer"),
						"Trailing zeros" => array("34.350000", "number"),
						"Leading & trailing" => array("00009938.43800000", "number"),
						"Example from documentation #1" => array("808", "integer"),
						"Example from documentation #2"
							=> array("808 State", "wikistring"),

					// Links.
						"Wiki link without brackets"
							=> array("Help:About", "wikistring"),
						"Link to main namespace" => array("[[Main Page]]", "link"),
						"Image" => array("[[Image:WikiDB icon.png]]", "image"),
						"Link to image"
							=> array("[[:Image:WikiDB icon.png]]", "link"),
						"Category" => array("[[category:New category]]", "link"),
						"Link to category"
							=> array("[[:category:New category]]", "link"),
						"Link to template namespace"
							=> array("[[Template:New template]]", "link"),
						"Link to MediaWiki namespace"
							=> array("[[MediaWiki:mainpage]]", "link"),
						"Link with pipe"
							=> array("[[Main Page|link to the main page]]", "link"),
						"URL without tags"
							=> array("http://www.kennel17.co.uk", "wikistring"),
						"URL in tags"
							=> array("[http://www.kennel17.co.uk]", "wikistring"),
						"URL with description"
							=> array("[http://www.kennel17.co.uk Kennel 17]",
									 "wikistring"),
					);

		return $arrTestData;
	}

} // END class WikiDB_BuiltInTypesTest
