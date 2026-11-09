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

use PDO;

/** Forward-only statement. Rows are paged from the existing server cursor, never re-executed. */
class DB4DStatement implements \IteratorAggregate
{
    public string $resultType = '';
    public int $rowCount = 0;
    public readonly string $queryString;
    private ?int $statementID = null;
    private array $metadata = [];
    private array $page = [];
    private int $pageIndex = 0;
    private int $received = 0;
    private bool $executed = false;
    private array $bindings = [];
    private array $bindingTypes = [];
    private ?int $fetchMode = null;
    private array $fetchArgs = [];
    private array $outputBindings = [];
    private array $lastError = ['00000', null, null];

    public function __construct(private DB4DDriver $driver, #[\SensitiveParameter] string $sql) { $this->queryString = $sql; }

    public function execute(#[\SensitiveParameter] ?array $parameters = null): bool
    {
        $this->lastError = ['00000', null, null];
        try {
            $values=$parameters===null?$this->bindings:Parameters::normalize($parameters);
            if($this->driver->getAttribute(PDO::ATTR_EMULATE_PREPARES)){
                $sql=$this->getRawSql($parameters);$wireTypes=[];$body='';
            }else{
                $settings=$this->driver->settings();
                [$sql,$wireTypes,$body]=Parameters::compile($this->queryString,$values,$parameters===null?$this->bindingTypes:[],$settings['max_field_bytes'],$settings['max_page_bytes']);
            }
            $this->closeCursor();
            $this->rowCount = 0; $this->metadata = []; $this->resultType = ''; $this->executed = false;
            $result = $this->driver->executeSql($sql,$wireTypes,$body);
            $this->resultType = $result['type']; $this->rowCount = $result['rowCount'];
            $this->metadata = $result['metadata']; $this->statementID = $result['id'];
            $this->page = $result['rows']; $this->pageIndex = 0; $this->received = count($this->page);
            if ($this->received < $this->rowCount && $this->resultType !== 'Update-Count' && $this->statementID === null) {
                $this->driver->close();
                throw new DB4DException('4D omitted the cursor identifier for a paged result.', '08S01');
            }
            $this->executed = true;
            return true; // Zero-row SELECT and zero-row UPDATE are both successful executions.
        } catch (DB4DException $error) { $this->recordError($error); throw $error; }
    }

    private static function key(int|string $parameter): int|string
    {
        if (is_int($parameter)) {
            if ($parameter < 1) { throw new DB4DException('Positional bindings start at one.', 'HY093'); }
            return $parameter - 1;
        }
        $key = ltrim($parameter, ':');
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key)) { throw new DB4DException('Invalid parameter name.', 'HY093'); }
        return $key;
    }

    public function bindValue(int|string $parameter, #[\SensitiveParameter] mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $key = self::key($parameter);
        if ($type !== PDO::PARAM_LOB || !is_resource($value)) {
            if($this->driver->getAttribute(PDO::ATTR_EMULATE_PREPARES)){Parameters::literal($value,$type);}
            else{WireParameters::encode($value,$type,$this->driver->settings()['max_field_bytes']);}
        } elseif (get_resource_type($value) !== 'stream') {
            throw new DB4DException('A LOB resource must be a readable stream.', 'HY105');
        }
        unset($this->bindings[$key]);
        $this->bindings[$key] = $value; $this->bindingTypes[$key] = $type;
        return true;
    }
    public function bindParam(int|string $parameter, #[\SensitiveParameter] mixed &$value, int $type = PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool
    {
        if ($maxLength !== 0 || $driverOptions !== null || !in_array($type, [PDO::PARAM_STR, PDO::PARAM_INT, PDO::PARAM_BOOL, PDO::PARAM_NULL, PDO::PARAM_LOB], true)) {
            throw new DB4DException('Output parameters and driver-specific bindings are unsupported.', 'IM001');
        }
        $key = self::key($parameter); unset($this->bindings[$key]);
        $this->bindings[$key] =& $value; $this->bindingTypes[$key] = $type;
        return true;
    }
    public function getRawSql(#[\SensitiveParameter] ?array $parameters = null): string
    {
        $values = $parameters === null ? $this->bindings : Parameters::normalize($parameters);
        return Parameters::render($this->queryString, $values, $parameters === null ? $this->bindingTypes : []);
    }
    public static function parseArguments(#[\SensitiveParameter] mixed &$value): void { $value = Parameters::literal($value); }

    public static function validFetchMode(mixed $mode): bool
    {
        if(!is_int($mode)){return false;}
        if(in_array($mode,[DB4DDriver::FETCH_ASSOC,DB4DDriver::FETCH_NUM,DB4DDriver::FETCH_BOTH],true)){return true;}
        $base=$mode & 31;$flags=$mode & ~31;
        $group=$flags & (PDO::FETCH_GROUP|PDO::FETCH_UNIQUE);$late=$flags & PDO::FETCH_PROPS_LATE;
        return in_array($base,[PDO::FETCH_ASSOC,PDO::FETCH_NUM,PDO::FETCH_BOTH,PDO::FETCH_OBJ,PDO::FETCH_COLUMN,PDO::FETCH_NAMED,PDO::FETCH_CLASS,PDO::FETCH_BOUND,PDO::FETCH_KEY_PAIR,PDO::FETCH_FUNC],true)
            && ($flags & ~(PDO::FETCH_GROUP|PDO::FETCH_UNIQUE|PDO::FETCH_PROPS_LATE))===0
            && (!$late || $base===PDO::FETCH_CLASS)
            && (!$group || in_array($base,[PDO::FETCH_ASSOC,PDO::FETCH_NUM,PDO::FETCH_BOTH,PDO::FETCH_OBJ,PDO::FETCH_COLUMN,PDO::FETCH_NAMED,PDO::FETCH_CLASS],true));
    }
    private static function normalizeMode(int $mode): int
    {
        return match($mode){DB4DDriver::FETCH_ASSOC=>PDO::FETCH_ASSOC,DB4DDriver::FETCH_NUM=>PDO::FETCH_NUM,DB4DDriver::FETCH_BOTH=>PDO::FETCH_BOTH,default=>$mode};
    }
    private function validateMode(int $mode,array $args): void
    {
        if(!self::validFetchMode($mode)){throw new DB4DException('Unsupported fetch mode.','IM001');}
        $base=self::normalizeMode($mode)&31;
        $valid=match($base){
            PDO::FETCH_COLUMN=>!$args || (count($args)===1 && is_int($args[0]) && $args[0]>=0),
            PDO::FETCH_CLASS=>!$args || (count($args)<=2 && is_string($args[0]) && class_exists($args[0]) && (!isset($args[1]) || is_array($args[1]))),
            PDO::FETCH_FUNC=>count($args)===1 && is_callable($args[0]),
            default=>!$args,
        };
        if(!$valid){throw new DB4DException('Unsupported fetch argument.','IM001');}
    }
    public function setFetchMode(int $mode,mixed ...$args): bool
    {
        $this->validateMode($mode,$args);$this->fetchMode=$mode;$this->fetchArgs=$args;return true;
    }
    public function bindColumn(int|string $column,mixed &$value,int $type=PDO::PARAM_STR,int $maxLength=0,mixed $driverOptions=null): bool
    {
        if((is_int($column)&&$column<1) || (is_string($column)&&$column==='')){throw new DB4DException('Invalid bound column.','07009');}
        if($maxLength!==0 || $driverOptions!==null || !in_array($type,[PDO::PARAM_STR,PDO::PARAM_INT,PDO::PARAM_BOOL,PDO::PARAM_LOB],true)){throw new DB4DException('Unsupported column binding.','IM001');}
        unset($this->outputBindings[$column]);$this->outputBindings[$column]=['type'=>$type];$this->outputBindings[$column]['value']=&$value;return true;
    }
    private function assignBindings(array $row): void
    {
        $names=$this->readMetadata();
        foreach($this->outputBindings as $column=>&$binding){
            $index=is_int($column)?$column-1:array_search($column,$names,true);
            if($index===false || !array_key_exists($index,$row)){throw new DB4DException('Bound column not found.','07009');}
            $value=$row[$index];
            if($value!==null){$value=match($binding['type']){PDO::PARAM_INT=>(int)$value,PDO::PARAM_BOOL=>(bool)$value,PDO::PARAM_LOB=>self::lobStream((string)$value),default=>(string)$value};}
            $binding['value']=$value;
        }
    }
    private static function lobStream(string $bytes)
    {
        $stream=fopen('php://temp','w+');if(!$stream || fwrite($stream,$bytes)!==strlen($bytes)){throw new DB4DException('Cannot allocate bound LOB.','HY001');}rewind($stream);return $stream;
    }

    private function nextRow(): array|false
    {
        if (!$this->executed) { throw new DB4DException('Execute the statement before fetching.', 'HY010'); }
        if ($this->resultType === 'Update-Count') { return false; }
        if ($this->pageIndex >= count($this->page)) {
            if ($this->received >= $this->rowCount || $this->statementID === null) { return false; }
            try {
                $last = min($this->rowCount - 1, $this->received + $this->driver->settings()['page_size'] - 1);
                $result = $this->driver->fetchPage($this->statementID, $this->received, $last, $this->metadata);
                if ($result['rowCount'] !== $this->rowCount || $result['metadata'] !== $this->metadata || ($result['id'] !== null && $result['id'] !== $this->statementID)) {
                    $this->driver->close();
                    throw new DB4DException('4D changed result metadata during paging.', '08S01');
                }
                $this->page = $result['rows']; $this->pageIndex = 0; $this->received += count($this->page);
            } catch (DB4DException $error) { $this->recordError($error); throw $error; }
        }
        $row = $this->page[$this->pageIndex++];
        if ($this->driver->getAttribute(PDO::ATTR_STRINGIFY_FETCHES)) {
            foreach ($row as &$value) { if ($value !== null && !is_string($value)) { $value = is_bool($value) ? ($value ? '1' : '0') : (string)$value; } }
        }
        $this->assignBindings($row);
        return $row;
    }

    private function formatRow(array $row,int $mode,array $args,int $skip=0): mixed
    {
        $mode=self::normalizeMode($mode);$base=$mode&31;
        if($base===PDO::FETCH_NUM){return $row;}
        if($base===PDO::FETCH_BOUND){return true;}
        if($base===PDO::FETCH_COLUMN){$index=$args[0]??0;if(!array_key_exists($index,$row)){throw new DB4DException('Column index is out of range.','07009');}return $row[$index];}
        $names=array_slice($this->readMetadata(),$skip);$assoc=[];
        foreach($row as $i=>$value){$name=$names[$i];
            if($base===PDO::FETCH_NAMED && array_key_exists($name,$assoc)){$assoc[$name]=is_array($assoc[$name])?array_merge($assoc[$name],[$value]):[$assoc[$name],$value];}
            else{$assoc[$name]=$value;}
        }
        if($base===PDO::FETCH_OBJ){return (object)$assoc;}
        if($base===PDO::FETCH_CLASS){
            $reflection=new \ReflectionClass($args[0]??\stdClass::class);$constructorArgs=$args[1]??[];
            if(!$reflection->isInstantiable()){throw new DB4DException('Fetch class is not instantiable.','IM001');}
            $late=($mode&PDO::FETCH_PROPS_LATE)!==0;
            $object=$late?$reflection->newInstanceArgs($constructorArgs):$reflection->newInstanceWithoutConstructor();
            foreach($assoc as $name=>$value){
                if($reflection->hasProperty($name)){$reflection->getProperty($name)->setValue($object,$value);}
                elseif($object instanceof \stdClass || $reflection->getAttributes(\AllowDynamicProperties::class)){$object->$name=$value;}
                else{throw new DB4DException('Fetch class requires declared properties or AllowDynamicProperties.','IM001');}
            }
            if(!$late && ($constructor=$reflection->getConstructor())){$constructor->invokeArgs($object,$constructorArgs);}
            return $object;
        }
        return $base===PDO::FETCH_BOTH?$row+$assoc:$assoc;
    }
    public function fetchObject(string $class=\stdClass::class,array $constructorArgs=[]): object|false
    {
        $this->validateMode(PDO::FETCH_CLASS,[$class,$constructorArgs]);$row=$this->nextRow();
        return $row===false?false:$this->formatRow($row,PDO::FETCH_CLASS,[$class,$constructorArgs]);
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if ($cursorOrientation !== PDO::FETCH_ORI_NEXT || $cursorOffset !== 0) { throw new DB4DException('Only forward-only fetching is supported.', 'IM001'); }
        $default = $mode === PDO::FETCH_DEFAULT;
        $mode = $default ? ($this->fetchMode ?? $this->driver->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE)) : $mode;
        $args = $default ? $this->fetchArgs : [];
        $this->validateMode($mode, $args);
        if((self::normalizeMode($mode) & (PDO::FETCH_GROUP|PDO::FETCH_UNIQUE))!==0 || in_array($mode & 31,[PDO::FETCH_KEY_PAIR,PDO::FETCH_FUNC],true)){throw new DB4DException('This mode requires fetchAll.','IM001');}
        $row = $this->nextRow();
        return $row === false ? false : $this->formatRow($row, $mode, $args);
    }
    public function fetchRow(int $style = DB4DDriver::FETCH_BOTH): mixed
    {
        $row = $this->fetch($style);
        return $row === false ? null : $row;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $useDefault = $mode === PDO::FETCH_DEFAULT;
        $mode = $useDefault ? ($this->fetchMode ?? $this->driver->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE)) : $mode;
        if ($useDefault && !$args) { $args = $this->fetchArgs; }
        $this->validateMode($mode, $args); $result = [];
        $base=self::normalizeMode($mode)&31;$group=$mode & (PDO::FETCH_GROUP|PDO::FETCH_UNIQUE);
        if($base===PDO::FETCH_BOUND){throw new DB4DException('FETCH_BOUND requires fetch.','IM001');}
        while(($row=$this->nextRow())!==false){
            if($base===PDO::FETCH_KEY_PAIR){if(count($row)!==2){throw new DB4DException('FETCH_KEY_PAIR requires two columns.','HY000');}$result[$row[0]]=$row[1];}
            elseif($base===PDO::FETCH_FUNC){$result[]=($args[0])(...$row);}
            elseif($group){
                if(count($row)<2){throw new DB4DException('Grouped fetch requires two columns.','HY000');}
                $key=$row[0];if(!is_scalar($key)&&$key!==null){throw new DB4DException('Invalid group key.','HY000');}
                $value=$base===PDO::FETCH_COLUMN?$this->formatRow($row,$base,$args?:[1]):$this->formatRow(array_slice($row,1),$mode & ~(PDO::FETCH_GROUP|PDO::FETCH_UNIQUE),$args,1);
                if($group===PDO::FETCH_UNIQUE){$result[$key]=$value;}else{$result[$key][]=$value;}
            }else{$result[]=$this->formatRow($row,$mode,$args);}
        }
        return $result;
    }
    public function fetchColumn(int $index = 0): mixed
    {
        if ($index < 0 || ($this->executed && $index >= $this->columnCount())) { throw new DB4DException('Column index is out of range.', '07009'); }
        $row = $this->nextRow();
        return $row === false ? false : $row[$index];
    }
    public function getIterator(): \Traversable
    {
        // Do not use fetch() as the EOF condition: a valid FETCH_COLUMN value can be false.
        $mode = $this->fetchMode ?? $this->driver->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE);
        $this->validateMode($mode,$this->fetchArgs);
        if((self::normalizeMode($mode) & (PDO::FETCH_GROUP|PDO::FETCH_UNIQUE))!==0 || in_array($mode & 31,[PDO::FETCH_KEY_PAIR,PDO::FETCH_FUNC],true)){throw new DB4DException('This mode requires fetchAll.','IM001');}
        while (($row = $this->nextRow()) !== false) { yield $this->formatRow($row, $mode, $this->fetchArgs); }
    }
    public function closeCursor(): bool
    {
        $id = $this->statementID; $this->statementID = null; $this->page = []; $this->pageIndex = 0;
        $this->received = $this->rowCount;
        if ($id !== null && $this->driver->isOpen()) {
            try { $this->driver->closeStatement($id); }
            catch (DB4DException $error) { $this->recordError($error); throw $error; }
        }
        return true;
    }
    public function getColumnMeta(int $index): array|false
    {
        if($index<0 || $index>=$this->columnCount()){return false;}
        $type=$this->metadata['types'][$index];
        return ['name'=>$this->readMetadata()[$index],'native_type'=>$type,'flags'=>in_array($type,['VK_BLOB','VK_IMAGE'],true)?['blob']:[],
            'pdo_type'=>PDO::PARAM_STR,'len'=>0,'precision'=>0];
    }
    public function rowCount(): int { return $this->rowCount; }
    public function columnCount(): int { return count($this->metadata['types'] ?? []); }
    public function readMetadata(): array
    {
        $names = $this->metadata['names'] ?? [];
        return match ($this->driver->getAttribute(PDO::ATTR_CASE)) {
            PDO::CASE_UPPER => array_map(static fn($n) => mb_strtoupper($n, 'UTF-8'), $names),
            PDO::CASE_LOWER => array_map(static fn($n) => mb_strtolower($n, 'UTF-8'), $names), default => $names,
        };
    }
    public function getStatementID(): ?int { return $this->statementID; }
    public function errorCode(): string { return $this->lastError[0]; }
    public function errorInfo(): array { return $this->lastError; }
    public function errorOccured(): bool { return $this->errorCode() !== '00000'; }
    public function getErrorCode(): int { return $this->lastError[1] ?? 0; }
    public function getErrorDescription(): string { return $this->lastError[2] ?? ''; }
    private function recordError(DB4DException $error): void { $this->lastError = $error->errorInfo; $this->driver->recordError($error); }
    public function __destruct()
    {
        try { $this->closeCursor(); } catch (\Throwable) { /* Destruction must never hide an original exception. */ }
    }
}
