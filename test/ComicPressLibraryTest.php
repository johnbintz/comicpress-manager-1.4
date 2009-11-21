<?php

require_once('PHPUnit/Framework.php');
require_once(dirname(__FILE__) . '/../comicpress_manager_library.php');

define('CPM_DATE_FORMAT', 'Y-m-d');

class ComicPressLibraryTest extends PHPUnit_Framework_TestCase {
	function providerTestBreakdownComicFilename() {
		return array(
			array('2009-01-01-1.jpg', array(
				'date' => '2009-01-01',
				'title' => '-1',
				'converted_title' => 'Title: 1'
			))
		);
	}

	/**
	 * @dataProvider providerTestBreakdownComicFilename
	 */
	function testBreakdownComicFilename($input, $expected_output) {
		$this->assertEquals($expected_output, cpm_breakdown_comic_filename($input));
	}
}
