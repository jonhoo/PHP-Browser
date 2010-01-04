<?php

require 'browser.class.php';

/**
 * The long way to the PHP Reference Manual...
 */

/**
 * New browser object
 */
$b = new Browser ( );

/**
 * Navigate to the first url
 */
$b -> navigate ( 'http://en.wikipedia.org/wiki/Main_Page' );
/**
 * Search for php
 */
$b -> submitForm (
      $b  -> getForm ( "//form[@id='searchform']" ) 
	      -> setAttributeByName ( 'search', 'php' ),
      'fulltext'
  )
  -> click ( "//a[@title='PHP']" ) // Click the PHP search result
  -> click ( "PHP Reference Manual" ); // Click the link to the ref
echo $b -> getSource(); // Output the source
