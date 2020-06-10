<?php


namespace rabbit\db;

use rabbit\helper\StringHelper;

/**'
 * Class BatchInsert
 * @package rabbit\db
 */
class BatchInsert implements BatchInterface
{
    /** @var string */
    protected $table;
    /** @var array */
    protected $columnSchemas = [];
    /** @var */
    protected $sql;
    /** @var ConnectionInterface */
    protected $db;
    /** @var int */
    protected $hasRows = 0;
    protected $schema;
    /** @var array */
    protected $columns = [];

    /**
     * BatchInsert constructor.
     * @param string $table
     * @param array $columns
     * @param ConnectionInterface $db
     */
    public function __construct(string $table, ConnectionInterface $db)
    {
        $this->table = $table;
        $this->db = $db;
        $this->schema = $this->db->getSchema();
        $this->sql = 'INSERT INTO ' . $this->schema->quoteTableName($table);
    }

    /**
     * @return int
     */
    public function getRows(): int
    {
        return $this->hasRows;
    }

    /**
     * @return array
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * @param array $columns
     * @return bool
     */
    public function addColumns(array $columns): bool
    {
        if (empty($columns)) {
            return false;
        }

        if (($tableSchema = $this->schema->getTableSchema($this->table)) !== null) {
            $this->columnSchemas = $tableSchema->columns;
        }

        foreach ($columns as $i => $name) {
            $columns[$i] = $this->schema->quoteColumnName($name);
        }
        $this->sql .= ' (' . implode(', ', $columns) . ') VALUES ';
        $this->columns = $columns;
        return true;
    }

    /**
     * @param array $rows
     * @param bool $checkFields
     * @return bool
     */
    public function addRow(array $rows, bool $checkFields = true): bool
    {
        if (empty($rows)) {
            return false;
        }
        $this->hasRows++;
        if ($checkFields) {
            foreach ($rows as $i => $value) {
                if (isset($this->columns[$i], $this->columnSchemas[trim($this->columns[$i], '`')])) {
                    $value = $this->columnSchemas[trim($this->columns[$i], '`')]->dbTypecast($value);
                }
                if (is_string($value)) {
                    $value = $this->schema->quoteValue($value);
                } elseif (is_float($value)) {
                    $value = StringHelper::floatToString($value);
                } elseif ($value === false) {
                    $value = 0;
                } elseif ($value === null) {
                    $value = '';
                } elseif (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                    $value = "CAST('$value' as JSON)";
                } elseif ($value instanceof JsonExpression) {
                    $value = json_encode($value->getValue(), JSON_UNESCAPED_UNICODE);
                    $value = "CAST('$value' as JSON)";
                }
                $rows[$i] = $value;
            }
        }
        $this->sql .= '(' . implode(', ', $rows) . '),';
        return true;
    }

    public function clearData()
    {
        $this->sql = 'INSERT INTO ' . $this->schema->quoteTableName($this->table);
        $this->hasRows = 0;
    }

    /**
     * @return int
     */
    public function execute(): int
    {
        if ($this->hasRows) {
            $this->sql = rtrim($this->sql, ',');
            if (!$this->db->createCommand($this->sql)->execute()) {
                throw new Exception("Insert failed with unkonw reason!");
            }
        }
        return $this->hasRows;
    }
}
