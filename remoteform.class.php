<?php

/**
 * A class for extracting information about HTML Forms
 */
class RemoteForm {
    /**
     * @var String $_action Action (URL)
     */
    private $_action = '';
    /**
     * @var String $_method Method (GET/POST)
     */
    private $_method = 'get';
    /**
     * @var DOMElement $_form This form
     */
    private $_form = null;
    /**
     * @var DOMXpath $_navigator This form as a DOMXpath
     */
    private $_navigator = null;
    /**
     * @var array $_attributes All the attributes that have been loaded from this form or set by the script
     */
    private $_attributes = array();

    /**
     * Constructor
     * @param DOMElement $form The form to extract data from
     */
    public function __construct ( DOMElement $form ) {
        /**
         * Import this form as a root node in a DOMDocument
         * so that we can use DOMXpath later.
         */
        $doc = new DOMDocument();
        $this -> _form = $doc -> importNode ( $form, true );
        $doc -> appendChild ( $this -> _form );
        $this -> _navigator = new DOMXpath ( $doc );

        // If we have a non-empty action attribute, we set the action to the value of the action attribute
        if ( trim ( $this -> _form -> getAttribute ( 'action' ) ) != '' ) {
            $this -> _action = trim ( $this -> _form -> getAttribute ( 'action' ) );
        }

        // If the method attribute contains a legal value, we set that as well
        $method = strtolower ( trim ( $this -> _form -> getAttribute ( 'method' ) ) );
        if ( in_array ( $method, array ( 'get', 'post' ) ) ) {
            $this -> _method = $method;
        }

        // And finally, we try to find all the fields in the current form
        $this -> _discoverParameters();
    }

    /**
     * Discovers form fields in this form
     *
     * Extracts all form fields that are selected/checked by default,
     * or are text-input fields ( hidden|password|text|textarea )
     * Currently supprts:
     *  - Select
     *  - Input [button,submit,text,hidden,checkbox,radio,password]
     *  - Textarea
     */
    private function _discoverParameters () {
        /**
         * Loops through all known form fields
         */
        foreach ( $this -> _navigator -> query ( '//input | //select | //textarea' ) as $element ) {
            // Different behaviour for different field types
            switch ( strtolower ( $element -> tagName ) ) {
                case 'input':
                    // Different behaviour based on the value of the type attribute
                    switch ( strtolower ( $element -> getAttribute ( 'type' ) ) ) {
                        // Submit and button fields should not be set
                        case 'submit':
                            break;
                        case 'button':
                            break;
                        // Text fields should always be set, even if they're empty
                        case 'text':
                        case 'password':
                        case 'hidden':
                            $this -> _setAttributeByString ( $element -> getAttribute ( 'name' ), $element -> getAttribute ( 'value' ) );
                            break;
                        // Checkbox and radio should be set if they're checked by default
                        case 'checkbox':
                        case 'radio':
                            if ( trim ( $element -> getAttribute ( 'checked' ) ) != '' ) {
                                $this -> _setAttributeByString ( $element -> getAttribute ( 'name' ), $element -> getAttribute ( 'value' ) );
                            }
                            break;
                    }
                    break;
                case 'select':
                    // In a select, loops through all options, and sets the attribute named by the select if the option is selected by default
                    foreach ( $this -> _navigator -> query ( '//option[@selected != ""]', $element ) as $option ) {
                        $this -> _setAttributeByString ( $element -> getAttribute ( 'name' ), $option -> hasAttribute ( 'value' ) ? $option -> getAttribute ( 'value' ) : $option -> nodeValue );
                    }
                    break;
                case 'textarea':
                    // Textareas should always be set, even if empty
                    $this -> _setAttributeByString ( $element -> getAttribute ( 'name' ), $element -> nodeValue );
                    break;
            }
        }
    }

    /**
     * Sets the attribute name by $fieldName to $value
     * @param String $fieldName The name of the field, may contain arrays
     * @param String $value The textual value of the field
     * @return RemoteForm
     */
    public function setAttributeByName ( $fieldName, $value ) {
        return $this -> _setAttributeByString ( $fieldName, $value );
    }

    /**
     * Handles the $fieldName even if it contains HTML-style array names, and sets the attribute to $fieldValue
     * @param String $fieldName
     * @param String $fieldValue
     * @return RemoteForm
     */
    private function _setAttributeByString ( $fieldName, $fieldValue ) {
        $fieldName = trim ( $fieldName );

        // Locate the first array index
        $firstIndex = strpos ( $fieldName, '[' );

        // If there is no array index, read the entire string, and treat it as the only index
        if ( $firstIndex === false ) {
            $firstIndex = strlen ( $fieldName );
        }

        // Locate the name of the first index
        $firstName = substr ( $fieldName, 0, $firstIndex );

        // If no first index is set, skip the attribute
        if ( $firstName === '' ) {
            // Chain
            return $this;
        }

        // If the first index is the only index, set the attribute and move on
        if ( $firstName === $fieldName ) {
            $this -> _attributes[$fieldName] = $fieldValue;
            // Chain
            return $this;
        }

        // Find all other indexes
        $matches = array();
        preg_match_all ( "/\[([^\]]+)\]/g", $fieldName, $matches, PREG_PATTERN_ORDER );

        // No other indexes, set value for first index and return
        if ( empty ( $matches[1] ) ) {
            $this -> _attributes[$firstName] = $fieldValue;
            // Chain
            return $this;
        }

        // Fetch only the matches from the first group
        $matches = $matches[1];

        // If the first attribute has not been set before, set it to an array to avoid E_NOTICE
        if ( !array_key_exists ( $firstName, $this -> _attributes ) ) {
            $this -> _attributes[$firstName] = array();
        }

        /**
         * Start at the array defined by the first index, and loop
         * through all the indexes, creating arrays if the index has
         * not been set before. Finally $at will be a reference to
         * the attribute $fieldName is pointing to in our array
         */
        $at = &$this -> _attributes[$firstName];
        for ( $i = 0; $i < count ( $matches ); $i++ ) {
            $name = $matches[$i];
            if ( trim ( $name ) === '' ) {
                $at[] = array();
                $at = &$at[array_search ( array(), $at, true )];
            } else {
                if ( !array_key_exists ( $name, $at ) ) {
                    $at[$name] = array();
                }
                $at = &$at[$name];
            }

            // Last loop step, set the value
            if ( $i + 1 === count ( $matches ) ) {
                $at = $fieldValue;
            }
        }

        // Chain
        return $this;
    }

    /**
     * Gets all attributes
     * @return array All attributes
     */
    public function getParameters() {
        return $this -> _attributes;
    }

    /**
     * Gets this form's action
     * @return String Action
     */
    public function getAction () {
        return $this -> _action;
    }

    /**
     * Gets this form's method
     * @return String Method
     */
    public function getMethod () {
        return $this -> _method;
    }
}
