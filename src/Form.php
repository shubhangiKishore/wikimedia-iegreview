<?php
/**
 * @section LICENSE
 * This file is part of Wikimedia Grants Review application.
 *
 * Wikimedia Grants Review application is free software: you can
 * redistribute it and/or modify it under the terms of the GNU General Public
 * License as published by the Free Software Foundation, either version 3 of
 * the License, or (at your option) any later version.
 *
 * Wikimedia Grants Review application is distributed in the hope that it
 * will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty
 * of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with Wikimedia Grants Review application.  If not, see
 * <http://www.gnu.org/licenses/>.
 *
 * @file
 * @copyright © 2014 Bryan Davis, Wikimedia Foundation and contributors.
 */

namespace Wikimedia\IEGReview;

use Psr\Log\LoggerInterface;

/**
 * Collect and validate user input.
 *
 * @author Bryan Davis <bd808@wikimedia.org>
 * @copyright © 2014 Bryan Davis, Wikimedia Foundation and contributors.
 */
class Form {

	/**
	 * @var LoggerInterface $logger
	 */
	protected $logger;

	/**
	 * Input parameters to expect.
	 * @var array $params
	 */
	protected $params = array();

	/**
	 * Values recieved after filtering.
	 * @var array $values
	 */
	protected $values = array();

	/**
	 * Fields with errors.
	 * @var array $errors
	 */
	protected $errors = array();

	/**
	 * @param LoggerInterface $logger Log channel
	 */
	public function __construct( $logger = null ) {
		$this->logger = $logger ?: new \Psr\Log\NullLogger();
	}

	/**
	 * Add an input expectation.
	 *
	 * @var string $name Parameter to expect
	 * @var int $filter Validation filter(s) to apply
	 * @var array $options Validation options
	 * @return Form Self, for message chaining
	 */
	public function expect( $name, $filter, $options = null ) {
		$options = ( is_array( $options ) ) ? $options : array();
		$flags = null;
		$required = false;
		$validate = null;

		if ( isset( $options['flags'] ) ) {
			$flags = $options['flags'];
			unset( $options['flags'] );
		}

		if ( isset( $options['required'] ) ) {
			$required = $options['required'];
			unset( $options['required'] );
		}

		if ( isset( $options['validate'] ) ) {
			$validate = $options['validate'];
			unset( $options['validate'] );
		}

		$this->params[$name] = array(
			'filter'   => $filter,
			'flags'    => $flags,
			'options'  => $options,
			'required' => $required,
			'validate' => $validate,
		);

		return $this;
	}

	public function expectBool( $name, $options = null ) {
		$options = ( is_array( $options ) ) ? $options : array();
		if ( !isset( $options['default'] ) ) {
			$options['default'] = false;
		}
		return $this->expect( $name, \FILTER_VALIDATE_BOOLEAN, $options );
	}

	public function expectTrue( $name, $options = null ) {
		$options = ( is_array( $options ) ) ? $options : array();
		$options['validate'] = function ($v) {
			return (bool)$v;
		};
		return $this->expectBool( $name, $options );
	}

	public function expectEmail( $name, $options = null ) {
		return $this->expect( $name, \FILTER_VALIDATE_EMAIL, $options );
	}

	public function expectFloat( $name, $options = null ) {
		return $this->expect( $name, \FILTER_VALIDATE_FLOAT, $options );
	}

	public function expectInt( $name, $options = null ) {
		return $this->expect( $name, \FILTER_VALIDATE_INT, $options );
	}

	public function expectIp( $name, $options = null ) {
		return $this->expect( $name, \FILTER_VALIDATE_IP, $options );
	}

	public function expectRegex( $name, $re, $options = null ) {
		$options = ( is_array( $options ) ) ? $options : array();
		$options['regexp'] = $re;
		return $this->expect( $name, \FILTER_VALIDATE_REGEXP, $options );
	}

	public function expectUrl( $name, $options = null ) {
		return $this->expect( $name, \FILTER_VALIDATE_URL, $options );
	}

	public function expectString( $name, $options = null ) {
		return $this->expectRegex( $name, '/^.+$/s', $options );
	}

	public function expectAnything( $name, $options = null ) {
		return $this->expect( $name, \FILTER_UNSAFE_RAW, $options );
	}

	public function expectInArray( $name, $valids, $options = null ) {
		$options = ( is_array( $options ) ) ? $options : array();
		$required = isset( $options['required'] ) ? $options['required'] : false;
		$options['validate'] = function ($val) use ($valids, $required) {
			return ( !$required && empty( $val ) ) || in_array( $val, $valids );
		};
		return $this->expectAnything( $name, $options );
	}

	/**
	 * Validate the provided input data using this form's expectations.
	 *
	 * @param array $vars Input to validate (default $_POST)
	 * @return bool True if input is valid, false otherwise
	 */
	public function validate( $vars = null ) {
		$vars = $vars ?: $_POST;
		$this->values = array();
		$this->errors = array();

		foreach ( $this->params as $name => $opt ) {
			$var = isset( $vars[$name] ) ? $vars[$name] : null;
			// Bypass filter_var if the input is null. This keeps filter_var
			// from "helping" by converting nulls to empty strings.
			$clean = ( $var === null ) ? $var : filter_var( $var, $opt['filter'], $opt );

			if ( $clean === false &&
				$opt['filter'] !== \FILTER_VALIDATE_BOOLEAN
			) {
				$this->values[$name] = null;

			} else {
				$this->values[$name] = $clean;
			}

			if ( $opt['required'] && $this->values[$name] === null ) {
				$this->errors[] = $name;

			} elseif ( is_callable( $opt['validate'] ) &&
				call_user_func( $opt['validate'], $this->values[$name] ) === false
			) {
				$this->errors[] = $name;
				$this->values[$name] = null;
			}
		}

		$this->customValidationHook();

		return count( $this->errors ) === 0;
	}

	/**
	 * Stub method that can be extended by subclasses to add additional
	 * validation logic.
	 */
	protected function customValidationHook () {
	}

	public function get( $name ) {
		if ( isset( $this->values[$name] ) ) {
			return $this->values[$name];

		} elseif ( isset( $this->params[$name]['options']['default'] ) ) {
			return $this->params[$name]['options']['default'];

		} else {
			return null;
		}
	}

	public function getValues() {
		return $this->values;
	}

	public function getErrors() {
		return $this->errors;
	}

	public function hasErrors() {
		return count( $this->errors ) !== 0;
	}

	/**
	 * Make a URL-encoded string from a key=>value array
	 * @param array $parms Parameter array
	 * @return string URL-encoded message body
	 */
	public static function urlEncode( $parms ) {
		$payload = array();

		foreach ( $parms as $key => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					$payload[] = urlencode( $key ) . '=' . urlencode( $item );
				}
			} else {
				$payload[] = urlencode( $key ) . '=' . urlencode( $value );
			}
		}

		return implode( '&', $payload );
	} //end urlEncode

	/**
	 * Merge parameters into current query string.
	 * @param array $parms Parameter array
	 * @return string URL-encoded message body
	 */
	public static function qsMerge( $params = array() ) {
		return self::urlEncode( array_merge( $_GET, $params ) );
	}

	/**
	 * Remove parameters from current query string.
	 * @param array $parms Parameters to remove
	 * @return string URL-encoded message body
	 */
	public static function qsRemove( $params = array() ) {
		return self::urlEncode( array_diff_key( $_GET, array_flip( $params ) ) );
	}

}
