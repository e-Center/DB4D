<?php
/**
 * @copyright Copyright (c) 2019 Thomas Pulzer <t.pulzer@kniel.de>
 *
 * @author Thomas Pulzer <t.pulzer@kniel.de>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace DB4D;
use Exception;

/**
 * Handle runtime errors.
 * This class extends the Exception class for exception handling.
 * @package DB4D
 */
class DB4DException extends Exception {
	public const SOC_CREATION_ERROR = 0x00;
	public const SOC_CONNECTION_ERROR = 0x01;
	public const STMT_RETURN_ERROR = 0x02;
	public const TYPE_NOT_SUPPORTED = 0x03;
	public const STMT_UNMATCHING_ARGS = 0x04;
	
	/**
	 * Construct a new exception.
	 * @param String $message The error message.
	 * @param int $code The error code.
	 */
	public function __construct(string $message = "", int $code = 0) {
		parent::__construct($message, $code);
	}
	
	/**
	 * Create a String representation of this error.
	 * @return String 
	 */
	public function __toString() {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}