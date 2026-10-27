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

/** Catchable by existing catch (PDOException $e) application code. */
class DB4DException extends \PDOException
{
    public const SOC_CREATION_ERROR = 0;
    public const SOC_CONNECTION_ERROR = 1;
    public const STMT_RETURN_ERROR = 2;
    public const TYPE_NOT_SUPPORTED = 3;
    public const STMT_UNMATCHING_ARGS = 4;

    public function __construct(string $message = '', string|int $state = 'HY000', int $nativeCode = 0)
    {
        if (is_int($state)) {
            $nativeCode = $state;
            $state = 'HY000';
        }
        parent::__construct($message, $nativeCode);
        $this->code = $state;
        $this->errorInfo = [$state, $nativeCode, $message];
    }
}
