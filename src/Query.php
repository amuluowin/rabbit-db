<?php

declare(strict_types=1);
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace Rabbit\DB;

use DI\DependencyException;
use DI\NotFoundException;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Rabbit\Base\Core\BaseObject;
use Rabbit\Base\Exception\NotSupportedException;
use ReflectionException;
use Throwable;

/**
 * Query represents a SELECT SQL statement in a way that is independent of DBMS.
 *
 * Query provides a set of methods to facilitate the specification of different clauses
 * in a SELECT statement. These methods can be chained together.
 *
 * By calling [[createCommand()]], we can get a [[Command]] instance which can be further
 * used to perform/execute the DB query against a database.
 *
 * For example,
 *
 * ```php
 * $query = new Query;
 * // compose the query
 * $query->select('id, name')
 *     ->from('user')
 *     ->limit(10);
 * // build and execute the query
 * $rows = $query->all();
 * // alternatively, you can create DB command and execute it
 * $command = $query->createCommand();
 * // $command->sql returns the actual SQL
 * $rows = $command->queryAll();
 * ```
 *
 * Query internally uses the [[QueryBuilder]] class to generate the SQL statement.
 *
 * A more detailed usage guide on how to work with Query can be found in the [guide article on Query Builder](guide:db-query-builder).
 *
 * @property string[] $tablesUsedInFrom Table names indexed by aliases. This property is read-only.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @author Carsten Brandt <mail@cebe.cc>
 * @since 2.0
 */
class Query extends BaseObject implements QueryInterface, ExpressionInterface
{
    use QueryTrait;
    use QueryTraitExt;

    /**
     * @var array the columns being selected. For example, `['id', 'name']`.
     * This is used to construct the SELECT clause in a SQL statement. If not set, it means selecting all columns.
     * @see select()
     */
    public array $select = [];
    /**
     * @var string additional option that should be appended to the 'SELECT' keyword. For example,
     * in MySQL, the option 'SQL_CALC_FOUND_ROWS' can be used.
     */
    public ?string $selectOption = null;
    /**
     * @var bool whether to select distinct rows of data only. If this is set true,
     * the SELECT clause would be changed to SELECT DISTINCT.
     */
    public bool $distinct = false;
    /**
     * @var array the table(s) to be selected from. For example, `['user', 'post']`.
     * This is used to construct the FROM clause in a SQL statement.
     * @see from()
     */
    public ?array $from = null;
    /**
     * @var array how to group the query results. For example, `['company', 'department']`.
     * This is used to construct the GROUP BY clause in a SQL statement.
     */
    public ?array $groupBy = null;
    /**
     * @var array how to join with other tables. Each array element represents the specification
     * of one join which has the following structure:
     *
     * ```php
     * [$joinType, $tableName, $joinCondition]
     * ```
     *
     * For example,
     *
     * ```php
     * [
     *     ['INNER JOIN', 'user', 'user.id = author_id'],
     *     ['LEFT JOIN', 'team', 'team.id = team_id'],
     * ]
     * ```
     */
    public ?array $join = null;
    /**
     * @var string|array|ExpressionInterface the condition to be applied in the GROUP BY clause.
     * It can be either a string or an array. Please refer to [[where()]] on how to specify the condition.
     */
    public $having;
    /**
     * @var array this is used to construct the UNION clause(s) in a SQL statement.
     * Each array element is an array of the following structure:
     *
     * - `query`: either a string or a [[Query]] object representing a query
     * - `all`: boolean, whether it should be `UNION ALL` or `UNION`
     */
    public ?array $union = null;
    /**
     * @var array list of query parameter values indexed by parameter placeholders.
     * For example, `[':name' => 'Dan', ':age' => 31]`.
     */
    public array $params = [];
    /**
     * @var int|true the default number of seconds that query results can remain valid in cache.
     * Use 0 to indicate that the cached data will never expire.
     * Use a negative number to indicate that query cache should not be used.
     * Use boolean `true` to indicate that [[Connection::queryCacheDuration]] should be used.
     * @see cache()
     * @since 2.0.14
     */
    public $queryCacheDuration;
    /** @var CacheInterface|null */
    protected ?CacheInterface $cache = null;

    protected ?\Rabbit\Pool\ConnectionInterface $db = null;

    protected ?int $share = null;

    /**
     * Query constructor.
     * @param \Rabbit\Pool\ConnectionInterface|null $db
     * @param array $config
     * @throws ReflectionException
     */
    public function __construct(?\Rabbit\Pool\ConnectionInterface $db = null, array $config = [])
    {
        $this->db = $db ?? getDI('db')->get();
        $config !== [] && configure($this, $config);
    }

    /**
     * Creates a new Query object and copies its property values from an existing one.
     * The properties being copies are the ones to be used by query builders.
     * @param Query $from the source query object
     * @return Query the new Query object
     * @throws ReflectionException
     */
    public static function create(Query $from = null): self
    {
        if ($from === null) {
            return new self();
        }
        return new self($from->db, [
            'where' => $from->where,
            'limit' => $from->limit,
            'offset' => $from->offset,
            'orderBy' => $from->orderBy,
            'indexBy' => $from->indexBy,
            'select' => $from->select,
            'selectOption' => $from->selectOption,
            'distinct' => $from->distinct,
            'from' => $from->from,
            'groupBy' => $from->groupBy,
            'join' => $from->join,
            'having' => $from->having,
            'union' => $from->union,
            'params' => $from->params
        ]);
    }

    /**
     * Prepares for building SQL.
     * This method is called by [[QueryBuilder]] when it starts to build SQL from a query object.
     * You may override this method to do some final preparation work when converting a query into a SQL statement.
     * @param QueryBuilder $builder
     * @return $this a prepared query instance which will be used by [[QueryBuilder]] to build the SQL
     */
    public function prepare(QueryBuilder $builder): self
    {
        return $this;
    }

    /**
     * @param int $batchSize
     * @return BatchQueryResult
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function batch(int $batchSize = 100): BatchQueryResult
    {
        return create([
            'class' => BatchQueryResult::class,
            'query' => $this,
            'batchSize' => $batchSize,
            'db' => $this->db,
            'each' => false,
        ], [], false);
    }

    /**
     * @param int $batchSize
     * @return BatchQueryResult
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function each(int $batchSize = 100): BatchQueryResult
    {
        return create([
            'class' => BatchQueryResult::class,
            'query' => method_exists($this, 'asArray') ? $this->asArray() : $this,
            'batchSize' => $batchSize,
            'db' => $this->db,
            'each' => true,
        ], [], false);
    }

    /**
     * @return array
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    public function all(): array
    {
        if ($this->emulateExecution) {
            return [];
        }
        $rows = $this->createCommand()->queryAll();
        $rows = $this->populate($rows);
        return $rows;
    }

    /**
     * @return Command
     */
    public function createCommand(): Command
    {
        [$sql, $params] = $this->db->getQueryBuilder()->build($this);

        $command = $this->db->createCommand($sql, $params);
        $command->share($this->share);
        $this->setCommandCache($command);

        return $command;
    }

    /**
     * Sets $command cache, if this query has enabled caching.
     *
     * @param Command $command
     * @return Command
     * @since 2.0.14
     */
    protected function setCommandCache(Command $command): Command
    {
        if ($this->queryCacheDuration !== null) {
            $duration = $this->queryCacheDuration === true ? null : $this->queryCacheDuration;
            $command->cache($duration, $this->cache);
        }

        return $command;
    }

    /**
     * @return string|null
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    public function scalar()
    {
        if ($this->emulateExecution) {
            return null;
        }

        return $this->createCommand()->queryScalar();
    }

    /**
     * @return array|null
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    public function column()
    {
        if ($this->emulateExecution) {
            return [];
        }

        if ($this->indexBy === null) {
            return $this->createCommand()->queryColumn();
        }

        if (is_string($this->indexBy) && is_array($this->select) && count($this->select) === 1) {
            if (strpos($this->indexBy, '.') === false && count($tables = $this->getTablesUsedInFrom()) > 0) {
                $this->select[] = key($tables) . '.' . $this->indexBy;
            } else {
                $this->select[] = $this->indexBy;
            }
        }
        $rows = $this->createCommand()->queryAll();
        $results = [];
        foreach ($rows as $row) {
            $value = reset($row);

            if ($this->indexBy instanceof \Closure) {
                $results[call_user_func($this->indexBy, $row)] = $value;
            } else {
                $results[$row[$this->indexBy]] = $value;
            }
        }

        return $results;
    }

    /**
     * Returns table names used in [[from]] indexed by aliases.
     * Both aliases and names are enclosed into {{ and }}.
     * @return string[] table names indexed by aliases
     * @since 2.0.12
     */
    public function getTablesUsedInFrom(): array
    {
        if (empty($this->from)) {
            return [];
        }

        if (is_array($this->from)) {
            $tableNames = $this->from;
        } elseif (is_string($this->from)) {
            $tableNames = preg_split('/\s*,\s*/', trim($this->from), -1, PREG_SPLIT_NO_EMPTY);
        } elseif ($this->from instanceof Expression) {
            $tableNames = [$this->from];
        } else {
            throw new \InvalidArgumentException(gettype($this->from) . ' in $from is not supported.');
        }

        return $this->cleanUpTableNames($tableNames);
    }

    /**
     * Clean up table names and aliases
     * Both aliases and names are enclosed into {{ and }}.
     * @param array $tableNames non-empty array
     * @return string[] table names indexed by aliases
     * @since 2.0.14
     */
    protected function cleanUpTableNames(array $tableNames): array
    {
        $cleanedUpTableNames = [];
        foreach ($tableNames as $alias => $tableName) {
            if (is_string($tableName) && !is_string($alias)) {
                $pattern = <<<PATTERN
~
^
\s*
(
(?:['"`\[]|{{)
.*?
(?:['"`\]]|}})
|
\(.*?\)
|
.*?
)
(?:
(?:
    \s+
    (?:as)?
    \s*
)
(
   (?:['"`\[]|{{)
    .*?
    (?:['"`\]]|}})
    |
    .*?
)
)?
\s*
$
~iux
PATTERN;
                if (preg_match($pattern, $tableName, $matches)) {
                    if (isset($matches[2])) {
                        [, $tableName, $alias] = $matches;
                    } else {
                        $tableName = $alias = $matches[1];
                    }
                }
            }


            if ($tableName instanceof Expression) {
                if (!is_string($alias)) {
                    throw new \InvalidArgumentException('To use Expression in from() method, pass it in array format with alias.');
                }
                $cleanedUpTableNames[$this->ensureNameQuoted($alias)] = $tableName;
            } elseif ($tableName instanceof self) {
                $cleanedUpTableNames[$this->ensureNameQuoted($alias)] = $tableName;
            } else {
                $cleanedUpTableNames[$this->ensureNameQuoted($alias)] = $this->ensureNameQuoted($tableName);
            }
        }

        return $cleanedUpTableNames;
    }

    /**
     * Ensures name is wrapped with {{ and }}
     * @param string $name
     * @return string
     */
    private function ensureNameQuoted(string $name): string
    {
        $name = str_replace(["'", '"', '`', '[', ']'], '', $name);
        if ($name && !preg_match('/^{{.*}}$/', $name)) {
            return '{{' . $name . '}}';
        }

        return $name;
    }

    /**
     * @param string $q
     * @return int
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    public function count(string $q = '*'): int
    {
        if ($this->emulateExecution) {
            return 0;
        }

        return (int)$this->queryScalar("COUNT($q)");
    }

    /**
     * @param $selectExpression
     * @return string|null
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    protected function queryScalar($selectExpression): ?string
    {
        if ($this->emulateExecution) {
            return null;
        }

        if (
            !$this->distinct
            && empty($this->groupBy)
            && empty($this->having)
            && empty($this->union)
        ) {
            $select = $this->select;
            $order = $this->orderBy;
            $limit = $this->limit;
            $offset = $this->offset;

            $this->select = [$selectExpression];
            $this->orderBy = null;
            $this->limit = null;
            $this->offset = null;
            $command = $this->createCommand();

            $this->select = $select;
            $this->orderBy = $order;
            $this->limit = $limit;
            $this->offset = $offset;

            return $command->queryScalar();
        }

        $command = (new self($this->db))
            ->select([$selectExpression])
            ->from(['c' => $this])
            ->createCommand();
        $command->share($this->share);
        $this->setCommandCache($command);

        return $command->queryScalar();
    }

    /**
     * Sets the FROM part of the query.
     * @param string|array|ExpressionInterface $tables the table(s) to be selected from. This can be either a string (e.g. `'user'`)
     * or an array (e.g. `['user', 'profile']`) specifying one or several table names.
     * Table names can contain schema prefixes (e.g. `'public.user'`) and/or table aliases (e.g. `'user u'`).
     * The method will automatically quote the table names unless it contains some parenthesis
     * (which means the table is given as a sub-query or DB expression).
     *
     * When the tables are specified as an array, you may also use the array keys as the table aliases
     * (if a table does not need alias, do not use a string key).
     *
     * Use a Query object to represent a sub-query. In this case, the corresponding array key will be used
     * as the alias for the sub-query.
     *
     * To specify the `FROM` part in plain SQL, you may pass an instance of [[ExpressionInterface]].
     *
     * Here are some examples:
     *
     * ```php
     * // SELECT * FROM  `user` `u`, `profile`;
     * $query = (new \rabbit\db\Query)->from(['u' => 'user', 'profile']);
     *
     * // SELECT * FROM (SELECT * FROM `user` WHERE `active` = 1) `activeusers`;
     * $subquery = (new \rabbit\db\Query)->from('user')->where(['active' => true])
     * $query = (new \rabbit\db\Query)->from(['activeusers' => $subquery]);
     *
     * // subquery can also be a string with plain SQL wrapped in parenthesis
     * // SELECT * FROM (SELECT * FROM `user` WHERE `active` = 1) `activeusers`;
     * $subquery = "(SELECT * FROM `user` WHERE `active` = 1)";
     * $query = (new \rabbit\db\Query)->from(['activeusers' => $subquery]);
     * ```
     *
     * @return $this the query object itself
     */
    public function from(array $tables): self
    {
        $this->from = $tables;
        return $this;
    }

    /**
     * Sets the SELECT part of the query.
     * @param string|array|ExpressionInterface $columns the columns to be selected.
     * Columns can be specified in either a string (e.g. "id, name") or an array (e.g. ['id', 'name']).
     * Columns can be prefixed with table names (e.g. "user.id") and/or contain column aliases (e.g. "user.id AS user_id").
     * The method will automatically quote the column names unless a column contains some parenthesis
     * (which means the column contains a DB expression). A DB expression may also be passed in form of
     * an [[ExpressionInterface]] object.
     *
     * Note that if you are selecting an expression like `CONCAT(first_name, ' ', last_name)`, you should
     * use an array to specify the columns. Otherwise, the expression may be incorrectly split into several parts.
     *
     * When the columns are specified as an array, you may also use array keys as the column aliases (if a column
     * does not need alias, do not use a string key).
     *
     * Starting from version 2.0.1, you may also select sub-queries as columns by specifying each such column
     * as a `Query` instance representing the sub-query.
     *
     * @param string $option additional option that should be appended to the 'SELECT' keyword. For example,
     * in MySQL, the option 'SQL_CALC_FOUND_ROWS' can be used.
     * @return $this the query object itself
     */
    public function select(array $columns, string $option = null): self
    {
        // this sequantial assignment is needed in order to make sure select is being reset
        // before using getUniqueColumns() that checks it
        $this->select = [];
        $this->select = $this->getUniqueColumns($columns);
        $this->selectOption = $option;
        return $this;
    }

    /**
     * Returns unique column names excluding duplicates.
     * Columns to be removed:
     * - if column definition already present in SELECT part with same alias
     * - if column definition without alias already present in SELECT part without alias too
     * @param array $columns the columns to be merged to the select.
     * @return array
     * @since 2.0.14
     */
    protected function getUniqueColumns(array $columns): array
    {
        $unaliasedColumns = $this->getUnaliasedColumnsFromSelect();

        $result = [];
        foreach ($columns as $columnAlias => $columnDefinition) {
            if (!$columnDefinition instanceof Query) {
                if (is_string($columnAlias)) {
                    $existsInSelect = isset($this->select[$columnAlias]) && $this->select[$columnAlias] === $columnDefinition;
                    if ($existsInSelect) {
                        continue;
                    }
                } elseif (is_int($columnAlias)) {
                    $existsInSelect = in_array($columnDefinition, $unaliasedColumns, true);
                    $existsInResultSet = in_array($columnDefinition, $result, true);
                    if ($existsInSelect || $existsInResultSet) {
                        continue;
                    }
                }
            }

            $result[$columnAlias] = $columnDefinition;
        }
        return $result;
    }

    /**
     * @return array List of columns without aliases from SELECT statement.
     * @since 2.0.14
     */
    protected function getUnaliasedColumnsFromSelect(): array
    {
        $result = [];
        if (is_array($this->select)) {
            foreach ($this->select as $name => $value) {
                if (is_int($name)) {
                    $result[] = $value;
                }
            }
        }
        return array_unique($result);
    }

    /**
     * @param string $q
     * @return float
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    public function sum(string $q): float
    {
        if ($this->emulateExecution) {
            return 0;
        }

        return (float)$this->queryScalar("SUM($q)");
    }

    /**
     * @param string $q
     * @return float
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    public function average(string $q): float
    {
        if ($this->emulateExecution) {
            return 0;
        }

        return (float)$this->queryScalar("AVG($q)");
    }

    /**
     * @param string $q
     * @return string|null
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    public function min(string $q): ?string
    {
        return $this->queryScalar("MIN($q)");
    }

    /**
     * @param string $q
     * @return string|null
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    public function max(string $q): ?string
    {
        return $this->queryScalar("MAX($q)");
    }

    /**
     * @return bool
     * @throws InvalidArgumentException
     * @throws NotSupportedException
     * @throws Throwable
     */
    public function exists(): bool
    {
        if ($this->emulateExecution) {
            return false;
        }
        $command = $this->createCommand();
        $params = $command->params;
        $command->setSql($command->db->getQueryBuilder()->selectExists($command->getSql()));
        $command->bindValues($params);
        return (bool)$command->queryScalar();
    }

    /**
     * Add more columns to the SELECT part of the query.
     *
     * Note, that if [[select]] has not been specified before, you should include `*` explicitly
     * if you want to select all remaining columns too:
     *
     * ```php
     * $query->addSelect(["*", "CONCAT(first_name, ' ', last_name) AS full_name"])->one();
     * ```
     *
     * @param string|array|ExpressionInterface $columns the columns to add to the select. See [[select()]] for more
     * details about the format of this parameter.
     * @return $this the query object itself
     * @see select()
     */
    public function addSelect(array $columns): self
    {
        $columns = $this->getUniqueColumns($columns);
        if ($this->select === null) {
            $this->select = $columns;
        } else {
            $this->select = array_merge($this->select, $columns);
        }

        return $this;
    }

    /**
     * Sets the value indicating whether to SELECT DISTINCT or not.
     * @param bool $value whether to SELECT DISTINCT or not.
     * @return $this the query object itself
     */
    public function distinct(bool $value = true): self
    {
        $this->distinct = $value;
        return $this;
    }

    /**
     * Sets the WHERE part of the query.
     *
     * The method requires a `$condition` parameter, and optionally a `$params` parameter
     * specifying the values to be bound to the query.
     *
     * The `$condition` parameter should be either a string (e.g. `'id=1'`) or an array.
     *
     * {@inheritdoc}
     *
     * @param string|array|ExpressionInterface $condition the conditions that should be put in the WHERE part.
     * @param array $params the parameters (name => value) to be bound to the query.
     * @return $this the query object itself
     * @see andWhere()
     * @see orWhere()
     * @see QueryInterface::where()
     */
    public function where($condition, array $params = []): self
    {
        $this->where = $condition;
        $this->addParams($params);
        return $this;
    }

    /**
     * Adds additional parameters to be bound to the query.
     * @param array $params list of query parameter values indexed by parameter placeholders.
     * For example, `[':name' => 'Dan', ':age' => 31]`.
     * @return $this the query object itself
     * @see params()
     */
    public function addParams(array $params): self
    {
        if (!empty($params)) {
            if (empty($this->params)) {
                $this->params = $params;
            } else {
                foreach ($params as $name => $value) {
                    if (is_int($name)) {
                        $this->params[] = $value;
                    } else {
                        $this->params[$name] = $value;
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Adds an additional WHERE condition to the existing one.
     * The new condition and the existing one will be joined using the `AND` operator.
     * @param string|array|ExpressionInterface $condition the new WHERE condition. Please refer to [[where()]]
     * on how to specify this parameter.
     * @param array $params the parameters (name => value) to be bound to the query.
     * @return $this the query object itself
     * @see where()
     * @see orWhere()
     */
    public function andWhere($condition, array $params = []): self
    {
        if ($this->where === null) {
            $this->where = $condition;
        } elseif (is_array($this->where) && isset($this->where[0]) && strcasecmp($this->where[0], 'and') === 0) {
            $this->where[] = $condition;
        } else {
            $this->where = ['and', $this->where, $condition];
        }
        $this->addParams($params);
        return $this;
    }

    /**
     * Adds an additional WHERE condition to the existing one.
     * The new condition and the existing one will be joined using the `OR` operator.
     * @param string|array|ExpressionInterface $condition the new WHERE condition. Please refer to [[where()]]
     * on how to specify this parameter.
     * @param array $params the parameters (name => value) to be bound to the query.
     * @return $this the query object itself
     * @see where()
     * @see andWhere()
     */
    public function orWhere($condition, array $params = []): self
    {
        if ($this->where === null) {
            $this->where = $condition;
        } else {
            $this->where = ['or', $this->where, $condition];
        }
        $this->addParams($params);
        return $this;
    }

    /**
     * Adds a filtering condition for a specific column and allow the user to choose a filter operator.
     *
     * It adds an additional WHERE condition for the given field and determines the comparison operator
     * based on the first few characters of the given value.
     * The condition is added in the same way as in [[andFilterWhere]] so [[isEmpty()|empty values]] are ignored.
     * The new condition and the existing one will be joined using the `AND` operator.
     *
     * The comparison operator is intelligently determined based on the first few characters in the given value.
     * In particular, it recognizes the following operators if they appear as the leading characters in the given value:
     *
     * - `<`: the column must be less than the given value.
     * - `>`: the column must be greater than the given value.
     * - `<=`: the column must be less than or equal to the given value.
     * - `>=`: the column must be greater than or equal to the given value.
     * - `<>`: the column must not be the same as the given value.
     * - `=`: the column must be equal to the given value.
     * - If none of the above operators is detected, the `$defaultOperator` will be used.
     *
     * @param string $name the column name.
     * @param string $value the column value optionally prepended with the comparison operator.
     * @param string $defaultOperator The operator to use, when no operator is given in `$value`.
     * Defaults to `=`, performing an exact match.
     * @return $this The query object itself
     * @since 2.0.8
     */
    public function andFilterCompare(string $name, string $value, string $defaultOperator = '='): self
    {
        if (preg_match('/^(<>|>=|>|<=|<|=)/', $value, $matches)) {
            $operator = $matches[1];
            $value = substr($value, strlen($operator));
        } else {
            $operator = $defaultOperator;
        }

        return $this->andFilterWhere([$operator, $name, $value]);
    }

    /**
     * Appends a JOIN part to the query.
     * The first parameter specifies what type of join it is.
     * @param string $type the type of join, such as INNER JOIN, LEFT JOIN.
     * @param string $table the table to be joined.
     *
     * Use a string to represent the name of the table to be joined.
     * The table name can contain a schema prefix (e.g. 'public.user') and/or table alias (e.g. 'user u').
     * The method will automatically quote the table name unless it contains some parenthesis
     * (which means the table is given as a sub-query or DB expression).
     *
     * Use an array to represent joining with a sub-query. The array must contain only one element.
     * The value must be a [[Query]] object representing the sub-query while the corresponding key
     * represents the alias for the sub-query.
     *
     * @param string $on the join condition that should appear in the ON part.
     * Please refer to [[where()]] on how to specify this parameter.
     *
     * Note that the array format of [[where()]] is designed to match columns to values instead of columns to columns, so
     * the following would **not** work as expected: `['post.author_id' => 'user.id']`, it would
     * match the `post.author_id` column value against the string `'user.id'`.
     * It is recommended to use the string syntax here which is more suited for a join:
     *
     * ```php
     * 'post.author_id = user.id'
     * ```
     *
     * @param array $params the parameters (name => value) to be bound to the query.
     * @return $this the query object itself
     */
    public function join(string $type, $table, string $on = '', array $params = []): self
    {
        $this->join[] = [$type, $table, $on];
        return $this->addParams($params);
    }

    /**
     * Appends an INNER JOIN part to the query.
     * @param string $table the table to be joined.
     *
     * Use a string to represent the name of the table to be joined.
     * The table name can contain a schema prefix (e.g. 'public.user') and/or table alias (e.g. 'user u').
     * The method will automatically quote the table name unless it contains some parenthesis
     * (which means the table is given as a sub-query or DB expression).
     *
     * Use an array to represent joining with a sub-query. The array must contain only one element.
     * The value must be a [[Query]] object representing the sub-query while the corresponding key
     * represents the alias for the sub-query.
     *
     * @param string $on the join condition that should appear in the ON part.
     * Please refer to [[join()]] on how to specify this parameter.
     * @param array $params the parameters (name => value) to be bound to the query.
     * @return $this the query object itself
     */
    public function innerJoin($table, string $on = '', array $params = []): self
    {
        $this->join[] = ['INNER JOIN', $table, $on];
        return $this->addParams($params);
    }

    /**
     * Appends a LEFT OUTER JOIN part to the query.
     * @param string $table the table to be joined.
     *
     * Use a string to represent the name of the table to be joined.
     * The table name can contain a schema prefix (e.g. 'public.user') and/or table alias (e.g. 'user u').
     * The method will automatically quote the table name unless it contains some parenthesis
     * (which means the table is given as a sub-query or DB expression).
     *
     * Use an array to represent joining with a sub-query. The array must contain only one element.
     * The value must be a [[Query]] object representing the sub-query while the corresponding key
     * represents the alias for the sub-query.
     *
     * @param string $on the join condition that should appear in the ON part.
     * Please refer to [[join()]] on how to specify this parameter.
     * @param array $params the parameters (name => value) to be bound to the query
     * @return $this the query object itself
     */
    public function leftJoin($table, string $on = '', array $params = []): self
    {
        $this->join[] = ['LEFT JOIN', $table, $on];
        return $this->addParams($params);
    }

    /**
     * Appends a RIGHT OUTER JOIN part to the query.
     * @param string $table the table to be joined.
     *
     * Use a string to represent the name of the table to be joined.
     * The table name can contain a schema prefix (e.g. 'public.user') and/or table alias (e.g. 'user u').
     * The method will automatically quote the table name unless it contains some parenthesis
     * (which means the table is given as a sub-query or DB expression).
     *
     * Use an array to represent joining with a sub-query. The array must contain only one element.
     * The value must be a [[Query]] object representing the sub-query while the corresponding key
     * represents the alias for the sub-query.
     *
     * @param string $on the join condition that should appear in the ON part.
     * Please refer to [[join()]] on how to specify this parameter.
     * @param array $params the parameters (name => value) to be bound to the query
     * @return $this the query object itself
     */
    public function rightJoin($table, string $on = '', array $params = []): self
    {
        $this->join[] = ['RIGHT JOIN', $table, $on];
        return $this->addParams($params);
    }

    /**
     * Sets the GROUP BY part of the query.
     * @param string|array|ExpressionInterface $columns the columns to be grouped by.
     * Columns can be specified in either a string (e.g. "id, name") or an array (e.g. ['id', 'name']).
     * The method will automatically quote the column names unless a column contains some parenthesis
     * (which means the column contains a DB expression).
     *
     * Note that if your group-by is an expression containing commas, you should always use an array
     * to represent the group-by information. Otherwise, the method will not be able to correctly determine
     * the group-by columns.
     *
     * Since version 2.0.7, an [[ExpressionInterface]] object can be passed to specify the GROUP BY part explicitly in plain SQL.
     * Since version 2.0.14, an [[ExpressionInterface]] object can be passed as well.
     * @return $this the query object itself
     * @see addGroupBy()
     */
    public function groupBy($columns): self
    {
        if ($columns instanceof ExpressionInterface) {
            $columns = [$columns];
        } elseif (!is_array($columns)) {
            $columns = preg_split('/\s*,\s*/', trim($columns), -1, PREG_SPLIT_NO_EMPTY);
        }
        $this->groupBy = $columns;
        return $this;
    }

    /**
     * Adds additional group-by columns to the existing ones.
     * @param string|array $columns additional columns to be grouped by.
     * Columns can be specified in either a string (e.g. "id, name") or an array (e.g. ['id', 'name']).
     * The method will automatically quote the column names unless a column contains some parenthesis
     * (which means the column contains a DB expression).
     *
     * Note that if your group-by is an expression containing commas, you should always use an array
     * to represent the group-by information. Otherwise, the method will not be able to correctly determine
     * the group-by columns.
     *
     * Since version 2.0.7, an [[Expression]] object can be passed to specify the GROUP BY part explicitly in plain SQL.
     * Since version 2.0.14, an [[ExpressionInterface]] object can be passed as well.
     * @return $this the query object itself
     * @see groupBy()
     */
    public function addGroupBy($columns): self
    {
        if ($columns instanceof ExpressionInterface) {
            $columns = [$columns];
        } elseif (!is_array($columns)) {
            $columns = preg_split('/\s*,\s*/', trim($columns), -1, PREG_SPLIT_NO_EMPTY);
        }
        if ($this->groupBy === null) {
            $this->groupBy = $columns;
        } else {
            $this->groupBy = array_merge($this->groupBy, $columns);
        }

        return $this;
    }

    /**
     * Sets the HAVING part of the query but ignores [[isEmpty()|empty operands]].
     *
     * This method is similar to [[having()]]. The main difference is that this method will
     * remove [[isEmpty()|empty query operands]]. As a result, this method is best suited
     * for building query conditions based on filter values entered by users.
     *
     * The following code shows the difference between this method and [[having()]]:
     *
     * ```php
     * // HAVING `age`=:age
     * $query->filterHaving(['name' => null, 'age' => 20]);
     * // HAVING `age`=:age
     * $query->having(['age' => 20]);
     * // HAVING `name` IS NULL AND `age`=:age
     * $query->having(['name' => null, 'age' => 20]);
     * ```
     *
     * Note that unlike [[having()]], you cannot pass binding parameters to this method.
     *
     * @param array $condition the conditions that should be put in the HAVING part.
     * See [[having()]] on how to specify this parameter.
     * @return $this the query object itself
     * @see orFilterHaving()
     * @since 2.0.11
     * @see having()
     * @see andFilterHaving()
     */
    public function filterHaving(array $condition): self
    {
        $condition = $this->filterCondition($condition);
        if ($condition !== []) {
            $this->having($condition);
        }

        return $this;
    }

    /**
     * Sets the HAVING part of the query.
     * @param string|array|ExpressionInterface $condition the conditions to be put after HAVING.
     * Please refer to [[where()]] on how to specify this parameter.
     * @param array $params the parameters (name => value) to be bound to the query.
     * @return $this the query object itself
     * @see andHaving()
     * @see orHaving()
     */
    public function having($condition, array $params = []): self
    {
        $this->having = $condition;
        $this->addParams($params);
        return $this;
    }

    /**
     * Adds an additional HAVING condition to the existing one but ignores [[isEmpty()|empty operands]].
     * The new condition and the existing one will be joined using the `AND` operator.
     *
     * This method is similar to [[andHaving()]]. The main difference is that this method will
     * remove [[isEmpty()|empty query operands]]. As a result, this method is best suited
     * for building query conditions based on filter values entered by users.
     *
     * @param array $condition the new HAVING condition. Please refer to [[having()]]
     * on how to specify this parameter.
     * @return $this the query object itself
     * @throws NotSupportedException
     * @since 2.0.11
     * @see filterHaving()
     * @see orFilterHaving()
     */
    public function andFilterHaving(array $condition): self
    {
        $condition = $this->filterCondition($condition);
        if ($condition !== []) {
            $this->andHaving($condition);
        }

        return $this;
    }

    /**
     * Adds an additional HAVING condition to the existing one.
     * The new condition and the existing one will be joined using the `AND` operator.
     * @param string|array|ExpressionInterface $condition the new HAVING condition. Please refer to [[where()]]
     * on how to specify this parameter.
     * @param array $params the parameters (name => value) to be bound to the query.
     * @return $this the query object itself
     * @see having()
     * @see orHaving()
     */
    public function andHaving($condition, array $params = []): self
    {
        if ($this->having === null) {
            $this->having = $condition;
        } else {
            $this->having = ['and', $this->having, $condition];
        }
        $this->addParams($params);
        return $this;
    }

    /**
     * Adds an additional HAVING condition to the existing one but ignores [[isEmpty()|empty operands]].
     * The new condition and the existing one will be joined using the `OR` operator.
     *
     * This method is similar to [[orHaving()]]. The main difference is that this method will
     * remove [[isEmpty()|empty query operands]]. As a result, this method is best suited
     * for building query conditions based on filter values entered by users.
     *
     * @param array $condition the new HAVING condition. Please refer to [[having()]]
     * on how to specify this parameter.
     * @return $this the query object itself
     * @throws NotSupportedException
     * @see andFilterHaving()
     * @since 2.0.11
     * @see filterHaving()
     */
    public function orFilterHaving(array $condition): self
    {
        $condition = $this->filterCondition($condition);
        if ($condition !== []) {
            $this->orHaving($condition);
        }

        return $this;
    }

    /**
     * Adds an additional HAVING condition to the existing one.
     * The new condition and the existing one will be joined using the `OR` operator.
     * @param string|array|ExpressionInterface $condition the new HAVING condition. Please refer to [[where()]]
     * on how to specify this parameter.
     * @param array $params the parameters (name => value) to be bound to the query.
     * @return $this the query object itself
     * @see having()
     * @see andHaving()
     */
    public function orHaving($condition, array $params = []): self
    {
        if ($this->having === null) {
            $this->having = $condition;
        } else {
            $this->having = ['or', $this->having, $condition];
        }
        $this->addParams($params);
        return $this;
    }

    /**
     * Appends a SQL statement using UNION operator.
     * @param string|Query $sql the SQL statement to be appended using UNION
     * @param bool $all TRUE if using UNION ALL and FALSE if using UNION
     * @return $this the query object itself
     */
    public function union(Query $sql, bool $all = false): self
    {
        $this->union[] = ['query' => $sql, 'all' => $all];
        return $this;
    }

    /**
     * Sets the parameters to be bound to the query.
     * @param array $params list of query parameter values indexed by parameter placeholders.
     * For example, `[':name' => 'Dan', ':age' => 31]`.
     * @return $this the query object itself
     * @see addParams()
     */
    public function params(array $params): self
    {
        $this->params = $params;
        return $this;
    }

    /**
     * @param float $duration
     * @param CacheInterface|null $cache
     * @return $this
     */
    public function cache(float $duration = 0, ?CacheInterface $cache = null): self
    {
        $this->queryCacheDuration = $duration;
        $this->cache = $cache;
        return $this;
    }

    /**
     * Disables query cache for this Query.
     * @return $this the Query object itself
     * @since 2.0.14
     */
    public function noCache(): self
    {
        $this->queryCacheDuration = null;
        return $this;
    }

    public function share(int $timeout = null): self
    {
        $this->share = $timeout;
        return $this;
    }

    /**
     * Returns the SQL representation of Query
     * @return string
     */
    public function __toString()
    {
        return serialize($this);
    }

    /**
     * @param $name
     * @param $arguments
     * @return Query
     */
    public function __call($name, $arguments)
    {
        $arguments = array_shift($arguments);
        if (strpos($name, '@') !== false && substr_count($name, '@') === 1) {
            [$method, $function] = explode('@', $name);
            if (in_array($method, ['select', 'where'])) {
                switch ($method) {
                    case 'select':
                        $this->$method = array_merge(
                            $this->$method ?? [],
                            [sprintf("%s(%s)", $function, implode(',', $arguments))]
                        );
                        break;
                    case 'where':
                        $this->{'and' . $method}(sprintf("%s(%s)", $function, implode(',', $arguments)));
                        break;
                }
            }
        }
        return $this;
    }
}
