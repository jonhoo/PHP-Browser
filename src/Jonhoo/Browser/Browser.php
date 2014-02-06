<?php

namespace Jonhoo\Browser;

/**
 * A class that emulates a regular HTTP browser client, and allows scripts to execute
 * common user actions like downloading images, clicking links and submitting forms
 *
 * @author Jon Gjengset
 */
class Browser {
    /**
     * @var Curl_HTTP_Client $_curl cURL instance
     */
    private $_curl = null;
    /**
     * @var DOMDocument $_currentDocument The current document
     */
    private $_currentDocument = null;
    /**
     * @var DOMXpath $_navigator An XPath for the current document
     */
    private $_navigator = null;
    /**
     * @var The raw data returned by the last query
     */
    private $_rawdata = null;

    /**
     * Constructor for the browsers
     * @param String $userAgent The useragent you wish the browser to appear as
     * @param String $url The initial URL
     */
    public function __construct ( $userAgent = '', $url = '' ) {
        /**
         * Create a cURL HTTP instance, first parameter is debug mode
         */
        $this -> _curl = new \Curl_HTTP_Client(true);
        if ( !empty ( $userAgent ) ) {
            $this -> _curl -> set_user_agent ( $userAgent );
        }
        $this -> _curl -> store_cookies ( sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'browser_cookies.cookie' );
        if ( trim ( $url ) != '' )  {
            $this -> navigate ( $url );
        }
    }

    /**
     * Navigates to the given URL
     * @param String $url The url to navigate to, may be relative
     * @return Browser Returns this browser object for chaining
     */
    public function navigate ( $url ) {
        /**
         * Resolve the URL
         */
        $url = $this -> _resolveUrl ( $url );

        /**
         * After resolving, it must be absolute, otherwise we're stuck...
         */
        if ( !strpos ( $url, 'http' ) === 0 ) {
            throw new \Exception ( "Unknown protocol used in navigation url: " . $url );
        }

        /**
         * Finally, fetch the URL, and handle the response
         */
        $this -> _handleResponse ( $this -> _curl -> fetch_url ( $url ), $url );

        /**
         * And make us chainable
         */
        return $this;
    }

    /**
     * Emulates a click on the given link.
     *
     * The link may be given either as an XPath query, or as plain text, in which case
     * this method will first search for any link or submit button with the exact text
     * given, and then attempt to find one that contains it.
     * @param String $link XPath or link/submit-button title
     * @return Browser Returns this browser object for chaining
     */
    public function click ( $link ) {
        // Attempt direct query
        $a = @$this -> _navigator -> query ( $link );
        if ( !$a || $a -> length != 1 ) {
            // Attempt exact title match
            $link_as_xpath = "//a[text() = '" . str_replace ( "'", "\'", $link ) . "'] | //input[@type = 'submit'][@value = '" . str_replace ( "'", "\'", $link ) . "']";
            $a = @$this -> _navigator -> query ( $link_as_xpath );

            if ( !$a ) {
                // This would mean the initial $link was an XPath expression
                // Redo it without error suppression
                $this -> _navigator -> query ( $link );
                throw new \Exception ( "Failed to find matches for selector: " . $link );
            }

            if ( $a -> length != 1 ) {
                // Attempt title contains match
                $link_as_xpath_contains = "//a[contains(.,'" . str_replace ( "'", "\'", $link ) . "')]";
                $a = $this -> _navigator -> query ( $link_as_xpath_contains );

                // Still no match, throw error
                if ( $a -> length != 1 ) {
                    throw new \Exception ( intval ( $a -> length ) . " links found matching: " . $link );
                }

                $link_as_xpath = $link_as_xpath_contains;
            }
            $link = $link_as_xpath;
        }

        // Fetch the element
        $a = $a -> item ( 0 );

        /**
         * If we've found a submit button, we find the parent form and submit it
         */
        if ( strtolower ( $a -> tagName ) === 'input' && strtolower ( $a -> getAttribute ( 'type' ) ) === 'submit' ) {
            $form = $a;
            while ( strtolower ( $form -> tagName !== 'form' ) ) {
                $form = $form -> parentNode;
            }

            if ( strtolower ( $form -> tagName ) !== 'form' ) {
                throw new \Exception ( "Button " . $link . " exists, but does not belong to a form" );
            }

            $this -> submitForm ( $this -> getForm ( $form ), $a -> getAttribute ( 'name' ) );
            // Chain
            return $this;
        }

        /**
         * Otherwise, we simply navigate by the links href
         */
        $this -> navigate ( $this -> _resolveUrl ( $a -> getAttribute ( 'href' ) ) );

        // Chain
        return $this;
    }

    /**
     * Downloads the target of the given link, image or other linking element
     *
     * This method will search for the given matching criteria (XPath), and 
     * download whatever is in the href or src attribute of the given element
     * @param String $match XPath to element
     * @param String $filename Path to download file to
     * @return Browser Returns this browser object for chaining
     */
    public function download ( $match, $filename ) {
        $e = $this -> _navigator -> query ( $match );
        if ( !$e || $e -> length != 1 ) {
            throw new \Exception ( intval ( $e -> length ) . " elements found matching: " . $match );
        }

        $e = $e -> item ( 0 );

        // If we don't have a linking attribute, we fail
        if ( !$e -> hasAttribute ( 'src' ) && !$e -> hasAttribute ( 'href' ) ) {
            throw new \Exception ( "No downloadable attribute found for element matching: " . $match );
        }

        // Resolve the linked resource
        $url = $this -> _resolveUrl ( $e -> hasAttribute ( 'src' ) ? $e -> getAttribute ( 'src' ) : $e -> getAttribute ( 'href' ) );

        // Open a file handle, and download the file
        $fh = fopen ( $filename, 'w' );
        $this -> _curl -> fetch_into_file ( $url, $fh );
        fclose ( $fh );

        // Chain!
        return $this;
    }

    /**
     * Updates the current document handlers based on the given data
     * @param HTML $data The fetched data
     * @param String $url The URL just loaded
     */
    private function _handleResponse ( $data, $url ) {
        // We must have fetched a URL
        if ( !$url ) {
            throw new \Exception ( "Could not load url: " . $url );
        }

        // Attempt to parse the document
        $this -> _currentDocument = new \DOMDocument();
        if ( ! ( @$this -> _currentDocument -> loadHTML ( $data ) ) ) {
            throw new \Exception ( "Malformed HTML server response from url: " . $url );
        }

        $this->_rawdata = $data;

        // Generte a XPath navigator
        $this -> _navigator = new \DOMXpath ( $this -> _currentDocument );
    }

    /**
     * Returns a form mapped through RemoteForm  matching the given XPath or element
     * @param The $formMatch form to utilize (XPath or DOMElement)
     * @return RemoteForm The matched form
     */
    public function getForm ( $formMatch ) {
        if ( $formMatch instanceof \DOMElement ) {
            $form = $formMatch;
        } else if ( is_string ( $formMatch ) ) {
            // Find the element
            $form = $this -> _navigator -> query ( $formMatch );

            // No element found
            if ( $form -> length != 1 ) {
                throw new \Exception ( $form -> length . " forms found matching: " . $formMatch );
            }

            $form = $form -> item ( 0 );
        } else {
            throw new \Exception ( "Illegal expression given to getForm" );
        }

        // New RemoteForm
        return new RemoteForm ( $form );
    }

    /**
     * Resolves the given URL based on the current URL
     * @param String $url URL to resolve
     * $return String The resolved URL
     */
    private function _resolveUrl ( $url ) {
        $url = trim ( $url );

        // Absolute URLs are fine
        if ( strpos ( $url, 'http' ) === 0 ) {
            return $url;
        }

        // Empty URLs represent current URL
        if ( $url === '' ) {
            return $this -> _curl -> get_effective_url();
        }

        /**
         * If the URL begins with a forwards slash, it is absolute based on the current hostname
         */
        if ( $url[0] === '/' ) {
            $port = ':' . parse_url ( $this -> _curl -> get_effective_url(), PHP_URL_PORT );
            return parse_url ( $this -> _curl -> get_effective_url(), PHP_URL_SCHEME ) . '://' . parse_url ( $this -> _curl -> get_effective_url(), PHP_URL_HOST ) . ( $port !== ':' ? $port : '' ) . $url;
        }

        /**
         * We have a relative URL.
         * First, check if we have a BASE HREF= tag, if so, we're
         * setting the URL relative to that, otherwise, it's
         * relative to the current URL
         */
        $base = dirname ( $this -> _curl -> get_effective_url() );
        $baseTag = $this -> _navigator -> query ( "//base[@href][last()]" );
        if ( $baseTag -> length > 0 ) {
            $base = $baseTag -> item ( 0 ) -> getAttribute ( 'href' );
        }
        return $base . '/' . $url;
    }

    /**
     * Submits the given form.
     *
     * If $submitButtonName is given, that name is also submitted as a POST/GET value
     * This is available since some forms act differently based on which submit button
     * you press
     * @param RemoteForm $form The form to submit
     * @param String $submitButtonName The submit button to click
     * @return Browser Returns this browser object for chaining
     */
    public function submitForm ( RemoteForm $form, $submitButtonName = '' ) {
        // Find the button, and set the given attribute if we're pressing a button
        if ( !empty ( $submitButtonName ) ) {
            $button = $this -> _navigator -> query ( "//input[@type='submit'][@name='" . str_replace ( "'", "\'", $submitButtonName ) . "']" );
            if ( $button -> length === 1 ) {
                $form -> setAttributeByName ( $submitButtonName, $button -> item ( 0 ) -> getAttribute ( 'value' ) );
            }
        }

        // Handle get/post
        switch ( strtolower ( $form -> getMethod() ) ) {
            case 'get':
                /**
                 * If we're dealing with GET, we build the query based on the
                 * parameters that RemoteForm finds, and then navigate to
                 * that URL
                 */
                $questionAt = strpos ( $form -> getAction(), '?' );
                if ( $questionAt === false ) {
                    $questionAt = strlen ( $form -> getAction() );
                }
                $url = substr ( $form -> getAction(), 0, $questionAt );
                $url = $this -> _resolveUrl ( $url );
                $url .= '?' . http_build_query ( $form -> getParameters() );
                $this -> navigate ( $url );
                break;
            case 'post':
                /**
                 * If we're posting, we simply build a query string, and
                 * pass that as the post data to the Curl HTTP client's
                 * post handler method. Then we handle the response.
                 */
                $this -> _handleResponse ( $this -> _curl -> send_post_data ( $this -> _resolveUrl ( $form -> getAction() ), $form -> getParameters() ), $form -> getAction() );
                break;
        }

        // Chain
        return $this;
    }

    /**
     * Returns the source of the current page
     * @return String The current HTML
     */
    public function getSource () {
        return $this -> _currentDocument -> saveHTML();
    }

    /**
     * Returns the raw source of the current page untouched
     * @return String The current HTML
     */
    public function getRawResponse () {
        return $this -> _rawdata;
    }
}

/**
 * Probably one of the ugliest hacks in PHP history...
 * Not used, but remains from an earlier version of
 * this script. Felt I could not remove it...
 */
function castToDOMElement ( \DOMNode $node ) {
    if ( $node instanceof DOMElement ) {
        return $node;
    }
    return unserialize ( preg_replace ( '/DOMNode/', 'DOMElement', serialize ( $node ) ) );
}
