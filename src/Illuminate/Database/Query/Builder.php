<?php namespace Illuminate\Database\Query;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;

class Builder {

	/**
	 * The database connection instance.
	 *
	 * @var \Illuminate\Database\Connection
	 */
	protected $connection;

	/**
	 * The database query grammar instance.
	 *
	 * @var \Illuminate\Database\Query\Grammars\Grammar
	 */
	protected $grammar;

	/**
	 * The database query post processor instance.
	 *
	 * @var \Illuminate\Database\Query\Processors\Processor
	 */
	protected $processor;

	/**
	 * The current query value bindings.
	 *
	 * @var array
	 */
	protected $bindings = array();

	/**
	 * An aggregate function and column to be run.
	 *
	 * @var array
	 */
	public $aggregate;

	/**
	 * The columns that should be returned.
	 *
	 * @var array
	 */
	public $columns;

	/**
	 * Indicates if the query returns distinct results.
	 *
	 * @var bool
	 */
	public $distinct = false;

	/**
	 * The table which the query is targeting.
	 *
	 * @var string
	 */
	public $from;

	/**
	 * The table joins for the query.
	 *
	 * @var array
	 */
	public $joins;

	/**
	 * The where constraints for the query.
	 *
	 * @var array
	 */
	public $wheres;

	/**
	 * The groupings for the query.
	 *
	 * @var array
	 */
	public $groups;

	/**
	 * The having constraints for the query.
	 *
	 * @var array
	 */
	public $havings;

	/**
	 * The orderings for the query.
	 *
	 * @var array
	 */
	public $orders;

	/**
	 * The maximum number of records to return.
	 *
	 * @var int
	 */
	public $limit;

	/**
	 * The number of records to skip.
	 *
	 * @var int
	 */
	public $offset;

	/**
	 * The query union statements.
	 *
	 * @var array
	 */
	public $unions;

	/**
	 * The key that should be used when caching the query.
	 *
	 * @var string
	 */
	protected $cacheKey;

	/**
	 * The number of minutes to cache the query.
	 *
	 * @var int
	 */
	protected $cacheMinutes;

	/**
	 * All of the available clause operators.
	 *
	 * @var array
	 */
	protected $operators = array(
		'=', '<', '>', '<=', '>=', '<>', '!=',
		'like', 'not like', 'between', 'ilike',
	);

	/**
	 * Create a new query builder instance.
	 *
	 * @param  \Illuminate\Database\ConnectionInterface  $connection
	 * @param  \Illuminate\Database\Query\Grammars\Grammar  $grammar
	 * @param  \Illuminate\Database\Query\Processors\Processor  $processor
	 * @return void
	 */
	public function __construct(ConnectionInterface $connection,
                                Grammar $grammar,
                                Processor $processor)
	{
		$this->grammar = $grammar;
		$this->processor = $processor;
		$this->connection = $connection;
	}

	/**
	 * Set the columns to be selected.
	 *
	 * @param  array  $columns
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function select($columns = array('*'))
	{
		$this->columns = is_array($columns) ? $columns : func_get_args();

		return $this;
	}

	/**
	 * Add a new select column to the query.
	 *
	 * @param  mixed  $column
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function addSelect($column)
	{
		$column = is_array($column) ? $column : func_get_args();

		$this->columns = array_merge((array) $this->columns, $column);

		return $this;
	}

	/**
	 * Force the query to only return distinct results.
	 *
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function distinct()
	{
		$this->distinct = true;

		return $this;
	}

	/**
	 * Set the table which the query is targeting.
	 *
	 * @param  string  $table
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function from($table)
	{
		$this->from = $table;

		return $this;
	}

	/**
	 * Add a join clause to the query.
	 *
	 * @param  string  $table
	 * @param  string  $first
	 * @param  string  $operator
	 * @param  string  $second
	 * @param  string  $type
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function join($table, $first, $operator = null, $second = null, $type = 'inner')
	{
		// If the first "column" of the join is really a Closure instance the developer
		// is trying to build a join with a complex "on" clause containing more than
		// one condition, so we'll add the join and call a Closure with the query.
		if ($first instanceof Closure)
		{
			$this->joins[] = new JoinClause($type, $table);

			call_user_func($first, end($this->joins));
		}

		// If the column is simply a string, we can assume the join simply has a basic
		// "on" clause with a single condition. So we will just build the join with
		// this simple join clauses attached to it. There is not a join callback.
		else
		{
			$join = new JoinClause($type, $table);

			$join->on($first, $operator, $second);

			$this->joins[] = $join;
		}

		return $this;
	}

	/**
	 * Add a left join to the query.
	 *
	 * @param  string  $table
	 * @param  string  $first
	 * @param  string  $operator
	 * @param  string  $second
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function leftJoin($table, $first, $operator = null, $second = null)
	{
		return $this->join($table, $first, $operator, $second, 'left');
	}

	/**
	 * Add a basic where clause to the query.
	 *
	 * @param  string  $column
	 * @param  string  $operator
	 * @param  mixed   $value
	 * @param  string  $boolean
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function where($column, $operator = null, $value = null, $boolean = 'and')
	{
		// If the columns is actually a Closure instance, we will assume the developer
		// wants to begin a nested where statement which is wrapped in parenthesis.
		// We'll add that Closure to the query then return back out immediately.
		if ($column instanceof Closure)
		{
			return $this->whereNested($column, $boolean);
		}

		// If the given operator is not found in the list of valid operators we will
		// assume that the developer is just short-cutting the '=' operators and
		// we will set the operators to '=' and set the values appropriately.
		if ( ! in_array(strtolower($operator), $this->operators, true))
		{
			list($value, $operator) = array($operator, '=');
		}

		// If the value is a Closure, it means the developer is performing an entire
		// sub-select within the query and we will need to compile the sub-select
		// within the where clause to get the appropriate query record results.
		if ($value instanceof Closure)
		{
			return $this->whereSub($column, $operator, $value, $boolean);
		}

		// If the value is "null", we will just assume the developer wants to add a
		// where null clause to the query. So, we will allow a short-cut here to
		// that method for convenience so the developer doesn't have to check.
		if (is_null($value))
		{
			return $this->whereNull($column, $boolean, $operator != '=');
		}

		// Now that we are working with just a simple query we can put the elements
		// in our array and add the query binding to our array of bindings that
		// will be bound to each SQL statements when it is finally executed.
		$type = 'Basic';

		$this->wheres[] = compact('type', 'column', 'operator', 'value', 'boolean');

		if ( ! $value instanceof Expression)
		{
			$this->bindings[] = $value;
		}

		return $this;
	}

	/**
	 * Add an "or where" clause to the query.
	 *
	 * @param  string  $column
	 * @param  string  $operator
	 * @param  mixed   $value
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function orWhere($column, $operator = null, $value = null)
	{
		return $this->where($column, $operator, $value, 'or');
	}

	/**
	 * Add a raw where clause to the query.
	 *
	 * @param  string  $sql
	 * @param  array   $bindings
	 * @param  string  $boolean
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function whereRaw($sql, array $bindings = array(), $boolean = 'and')
	{
		$type = 'raw';

		$this->wheres[] = compact('type', 'sql', 'boolean');

		$this->bindings = array_merge($this->bindings, $bindings);

		return $this;
	}

	/**
	 * Add a raw or where clause to the query.
	 *
	 * @param  string  $sql
	 * @param  array   $bindings
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function orWhereRaw($sql, array $bindings = array())
	{
		return $this->whereRaw($sql, $bindings, 'or');
	}

	/**
	 * Add a where between statement to the query.
	 *
	 * @param  string  $column
	 * @param  array   $values
	 * @param  string  $boolean
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function whereBetween($column, array $values, $boolean = 'and')
	{
		$type = 'between';

		$this->wheres[] = compact('column', 'type', 'boolean');

		$this->bindings = array_merge($this->bindings, $values);

		return $this;
	}

	/**
	 * Add an or where between statement to the query.
	 *
	 * @param  string  $column
	 * @param  array   $values
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function orWhereBetween($column, array $values)
	{
		return $this->whereBetween($column, $values, 'or');
	}

	/**
	 * Add a nested where statement to the query.
	 *
	 * @param  Closure  $callback
	 * @param  string   $boolean
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function whereNested(Closure $callback, $boolean = 'and')
	{
		// To handle nested queries we'll actually create a brand new query instance
		// and pass it off to the Closure that we have. The Closure can simply do
		// do whatever it wants to a query then we will store it for compiling.
		$type = 'Nested';

		$query = $this->newQuery();

		$query->from($this->from);

		call_user_func($callback, $query);

		// Once we have let the Closure do its things, we can gather the bindings on
		// the nested query builder and merge them into these bindings since they
		// need to get extracted out of the children and assigned to the array.
		if (count($query->wheres))
		{
			$this->wheres[] = compact('type', 'query', 'boolean');

			$this->mergeBindings($query);
		}

		return $this;
	}

	/**
	 * Add a full sub-select to the query.
	 *
	 * @param  string   $column
	 * @param  string   $operator
	 * @param  Closure  $callback
	 * @param  string   $boolean
	 * @return \Illuminate\Database\Query\Builder
	 */
	protected function whereSub($column, $operator, Closure $callback, $boolean)
	{
		$type = 'Sub';

		$query = $this->newQuery();

		// Once we have the query instance we can simply execute it so it can add all
		// of the sub-select's conditions to itself, and then we can cache it off
		// in the array of where clauses for the "main" parent query instance.
		call_user_func($callback, $query);

		$this->wheres[] = compact('type', 'column', 'operator', 'query', 'boolean');

		$this->mergeBindings($query);

		return $this;
	}

	/**
	 * Add an exists clause to the query.
	 *
	 * @param  Closure  $callback
	 * @param  string   $boolean
	 * @param  bool     $not
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function whereExists(Closure $callback, $boolean = 'and', $not = false)
	{
		$type = $not ? 'NotExists' : 'Exists';

		$query = $this->newQuery();

		// Similar to the sub-select clause, we will create a new query instance so
		// the developer may cleanly specify the entire exists query and we will
		// compile the whole thing in the grammar and insert it into the SQL.
		call_user_func($callback, $query);

		$this->wheres[] = compact('type', 'operator', 'query', 'boolean');

		$this->mergeBindings($query);

		return $this;
	}

	/**
	 * Add an or exists clause to the query.
	 *
	 * @param  Closure  $callback
	 * @param  bool     $not
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function orWhereExists(Closure $callback, $not = false)
	{
		return $this->whereExists($callback, 'or', $not);
	}

	/**
	 * Add a where not exists clause to the query.
	 *
	 * @param  Closure  $calback
	 * @param  string   $boolean
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function whereNotExists(Closure $callback, $boolean = 'and')
	{
		return $this->whereExists($callback, $boolean, true);
	}

	/**
	 * Add a where not exists clause to the query.
	 *
	 * @param  Closure  $calback
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function orWhereNotExists(Closure $callback)
	{
		return $this->orWhereExists($callback, true);
	}

	/**
	 * Add a "where in" clause to the query.
	 *
	 * @param  string  $column
	 * @param  mixed   $values
	 * @param  string  $boolean
	 * @param  bool    $not
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function whereIn($column, $values, $boolean = 'and', $not = false)
	{
		$type = $not ? 'NotIn' : 'In';

		// If the value of the where in clause is actually a Closure, we will assume that
		// the developer is using a full sub-select for this "in" statement, and will
		// execute those Closures, then we can re-construct the entire sub-selects.
		if ($values instanceof Closure)
		{
			return $this->whereInSub($column, $values, $boolean, $not);
		}

		$this->wheres[] = compact('type', 'column', 'values', 'boolean');

		$this->bindings = array_merge($this->bindings, $values);

		return $this;
	}

	/**
	 * Add an "or where in" clause to the query.
	 *
	 * @param  string  $column
	 * @param  mixed   $values
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function orWhereIn($column, $values)
	{
		return $this->whereIn($column, $values, 'or');
	}

	/**
	 * Add a "where not in" clause to the query.
	 *
	 * @param  string  $column
	 * @param  mixed   $values
	 * @param  string  $boolean
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function whereNotIn($column, $values, $boolean = 'and')
	{
		return $this->whereIn($column, $values, $boolean, true);
	}

	/**
	 * Add an "or where not in" clause to the query.
	 *
	 * @param  string  $column
	 * @param  mixed   $values
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function orWhereNotIn($column, $values)
	{
		return $this->whereNotIn($column, $values, 'or');
	}

	/**
	 * Add a where in with a sub-select to the query.
	 *
	 * @param  string   $column
	 * @param  Closure  $callback
	 * @param  string   $boolean
	 * @param  bool     $not
	 * @return \Illuminate\Database\Query\Builder
	 */
	protected function whereInSub($column, Closure $callback, $boolean, $not)
	{
		$type = $not ? 'NotInSub' : 'InSub';

		// To create the exists sub-select, we will actually create a query and call the
		// provided callback with the query so the developer may set any of the query
		// conditions they want for the in clause, then we'll put it in this array.
		call_user_func($callback, $query = $this->newQuery());

		$this->wheres[] = compact('type', 'column', 'query', 'boolean');

		$this->mergeBindings($query);

		return $this;
	}

	/**
	 * Add a "where null" clause to the query.
	 *
	 * @param  string  $column
	 * @param  string  $boolean
	 * @param  bool    $not
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function whereNull($column, $boolean = 'and', $not = false)
	{
		$type = $not ? 'NotNull' : 'Null';

		$this->wheres[] = compact('type', 'column', 'boolean');

		return $this;
	}

	/**
	 * Add an "or where null" clause to the query.
	 *
	 * @param  string  $column
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function orWhereNull($column)
	{
		return $this->whereNull($column, 'or');
	}

	/**
	 * Add a "where not null" clause to the query.
	 *
	 * @param  string  $column
	 * @param  string  $boolean
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function whereNotNull($column, $boolean = 'and')
	{
		return $this->whereNull($column, $boolean, true);
	}

	/**
	 * Add an "or where not null" clause to the query.
	 *
	 * @param  string  $column
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function orWhereNotNull($column)
	{
		return $this->whereNotNull($column, 'or');
	}

	/**
	 * Handles dynamic "where" clauses to the query.
	 *
	 * @param  string  $method
	 * @param  string  $parameters
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function dynamicWhere($method, $parameters)
	{
		$finder = substr($method, 5);

		$segments = preg_split('/(And|Or)(?=[A-Z])/', $finder, -1, PREG_SPLIT_DELIM_CAPTURE);

		// The connector variable will determine which connector will be used for the
		// query condition. We will change it as we come across new boolean values
		// in the dynamic method strings, which could contain a number of these.
		$connector = 'and';

		$index = 0;

		foreach ($segments as $segment)
		{
			// If the segment is not a boolean connector, we can assume it is a column's name
			// and we will add it to the query as a new constraint as a where clause, then
			// we can keep iterating through the dynamic method string's segments again.
			if ($segment != 'And' and $segment != 'Or')
			{
				$this->addDynamic($segment, $connector, $parameters, $index);

				$index++;
			}

			// Otherwise, we will store the connector so we know how the next where clause we
			// find in the query should be connected to the previous ones, meaning we will
			// have the proper boolean connector to connect the next where clause found.
			else
			{
				$connector = $segment;
			}
		}

		return $this;
	}

	/**
	 * Add a single dynamic where clause statement to the query.
	 *
	 * @param  string  $segment
	 * @param  string  $connector
	 * @param  array   $parameters
	 * @param  int     $index
	 * @return void
	 */
	protected function addDynamic($segment, $connector, $parameters, $index)
	{
		// Once we have parsed out the columns and formatted the boolean operators we
		// are ready to add it to this query as a where clause just like any other
		// clause on the query. Then we'll increment the parameter index values.
		$bool = strtolower($connector);

		$this->where(snake_case($segment), '=', $parameters[$index], $bool);
	}

	/**
	 * Add a "group by" clause to the query.
	 *
	 * @param  dynamic  $columns
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function groupBy()
	{
		$this->groups = array_merge((array) $this->groups, func_get_args());

		return $this;
	}

	/**
	 * Add a "having" clause to the query.
	 *
	 * @param  string  $column
	 * @param  string  $operator
	 * @param  string  $value
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function having($column, $operator = null, $value = null)
	{
		$type = 'basic';

		$this->havings[] = compact('type', 'column', 'operator', 'value');

		$this->bindings[] = $value;

		return $this;
	}

	/**
	 * Add a raw having clause to the query.
	 *
	 * @param  string  $sql
	 * @param  array   $bindings
	 * @param  string  $boolean
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function havingRaw($sql, array $bindings = array(), $boolean = 'and')
	{
		$type = 'raw';

		$this->havings[] = compact('type', 'sql', 'boolean');

		$this->bindings = array_merge($this->bindings, $bindings);

		return $this;
	}

	/**
	 * Add a raw or having clause to the query.
	 *
	 * @param  string  $sql
	 * @param  array   $bindings
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function orHavingRaw($sql, array $bindings = array())
	{
		return $this->havingRaw($sql, $bindings, 'or');
	}

	/**
	 * Add an "order by" clause to the query.
	 *
	 * @param  string  $column
	 * @param  string  $direction
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function orderBy($column, $direction = 'asc')
	{
		$this->orders[] = compact('column', 'direction');

		return $this;
	}

	/**
	 * Set the "offset" value of the query.
	 *
	 * @param  int  $value
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function skip($value)
	{
		$this->offset = $value;

		return $this;
	}

	/**
	 * Set the "limit" value of the query.
	 *
	 * @param  int  $value
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function take($value)
	{
		if ($value > 0) $this->limit = $value;

		return $this;
	}

	/**
	 * Set the limit and offset for a given page.
	 *
	 * @param  int  $page
	 * @param  int  $perPage
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function forPage($page, $perPage = 15)
	{
		return $this->skip(($page - 1) * $perPage)->take($perPage);
	}

	/**
	 * Add a union statement to the query.
	 *
	 * @param  \Illuminate\Database\Query\Builder|\Closure  $query
	 * @param  bool $all
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function union($query, $all = false)
	{
		if ($query instanceof Closure)
		{
			call_user_func($query, $query = $this->newQuery());
		}
		
		$this->unions[] = compact('query', 'all');

		return $this->mergeBindings($query);
	}

	/**
	 * Add a union all statement to the query.
	 *
	 * @param  \Illuminate\Database\Query\Builder|\Closure  $query
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function unionAll($query)
	{
		return $this->union($query, true);
	}

	/**
	 * Get the SQL representation of the query.
	 *
	 * @return string
	 */
	public function toSql()
	{
		return $this->grammar->compileSelect($this);
	}

	/**
	 * Indicate that the query results should be cached.
	 *
	 * @param  int  $minutes
	 * @param  string  $key
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function remember($minutes, $key = null)
	{
		list($this->cacheMinutes, $this->cacheKey) = array($minutes, $key);

		return $this;
	}

	/**
	 * Execute a query for a single record by ID.
	 *
	 * @param  int    $id
	 * @param  array  $columns
	 * @return mixed
	 */
	public function find($id, $columns = array('*'))
	{
		return $this->where('id', '=', $id)->first($columns);
	}

	/**
	 * Pluck a single column from the database.
	 *
	 * @param  string  $column
	 * @return mixed
	 */
	public function pluck($column)
	{
		$result = (array) $this->first(array($column));

		return count($result) > 0 ? reset($result) : null;
	}

	/**
	 * Execute the query and get the first result.
	 *
	 * @param  array   $columns
	 * @return mixed
	 */
	public function first($columns = array('*'))
	{
		$results = $this->take(1)->get($columns);

		return count($results) > 0 ? reset($results) : null;
	}

	/**
	 * Execute the query as a "select" statement.
	 *
	 * @param  array  $columns
	 * @return array
	 */
	public function get($columns = array('*'))
	{
		if ( ! is_null($this->cacheMinutes)) return $this->getCached($columns);

		return $this->getFresh($columns);
	}

	/**
	 * Execute the query as a fresh "select" statement.
	 *
	 * @param  array  $columns
	 * @return array
	 */
	public function getFresh($columns = array('*'))
	{
		if (is_null($this->columns)) $this->columns = $columns;

		return $this->processor->processSelect($this, $this->runSelect());
	}

	/**
	 * Run the query as a "select" statement against the connection.
	 *
	 * @return array
	 */
	protected function runSelect()
	{
		return $this->connection->select($this->toSql(), $this->bindings);
	}

	/**
	 * Execute the query as a cached "select" statement.
	 *
	 * @param  array  $columns
	 * @return array
	 */
	public function getCached($columns = array('*'))
	{
		list($key, $minutes) = $this->getCacheInfo();

		// If the query is requested ot be cached, we will cache it using a unique key
		// for this database connection and query statement, including the bindings
		// that are used on this query, providing great convenience when caching.
		$cache = $this->connection->getCacheManager();

		$callback = $this->getCacheCallback($columns);

		return $cache->remember($key, $minutes, $callback);
	}

	/**
	 * Get the cache key and cache minutes as an array.
	 *
	 * @return array
	 */
	protected function getCacheInfo()
	{
		return array($this->getCacheKey(), $this->cacheMinutes);
	}

	/**
	 * Get a unique cache key for the complete query.
	 *
	 * @return string
	 */
	public function getCacheKey()
	{
		return $this->cacheKey ?: $this->generateCacheKey();
	}

	/**
	 * Generate the unique cache key for the query.
	 *
	 * @return string
	 */
	public function generateCacheKey()
	{
		$name = $this->connection->getName();

		return md5($name.$this->toSql().serialize($this->bindings));
	}

	/**
	 * Get the Closure callback used when caching queries.
	 *
	 * @param  array  $columns
	 * @return \Closure
	 */
	protected function getCacheCallback($columns)
	{
		$me = $this;

		return function() use ($me, $columns) { return $me->getFresh($columns); };
	}

	/**
	 * Get an array with the values of a given column.
	 *
	 * @param  string  $column
	 * @param  string  $key
	 * @return array
	 */
	public function lists($column, $key = null)
	{
		$columns = $this->getListSelect($column, $key);

		// First we will just get all of the column values for the record result set
		// then we can associate those values with the column if it was specified
		// otherwise we can just give these values back without a specific key.
		$results = new Collection($this->get($columns));

		$values = $results->fetch($columns[0])->all();

		// If a key was specified and we have results, we will go ahead and combine
		// the values with the keys of all of the records so that the values can
		// be accessed by the key of the rows instead of simply being numeric.
		if ( ! is_null($key) and count($results) > 0)
		{
			$keys = $results->fetch($key)->all();

			return array_combine($keys, $values);
		}

		return $values;
	}

	/**
	 * Get the columns that should be used in a list array.
	 *
	 * @param  string  $column
	 * @param  string  $key
	 * @return array
	 */
	protected function getListSelect($column, $key)
	{
		$select = is_null($key) ? array($column) : array($column, $key);

		// If the selected column contains a "dot", we will remove it so that the list
		// operation can run normally. Specifying the table is not needed, since we
		// really want the names of the columns as it is in this resulting array.
		if (($dot = strpos($select[0], '.')) !== false)
		{
			$select[0] = substr($select[0], $dot + 1);
		}

		return $select;
	}

	/**
	 * Get a paginator for the "select" statement.
	 *
	 * @param  int    $perPage
	 * @param  array  $columns
	 * @return \Illuminate\Pagination\Paginator
	 */
	public function paginate($perPage = 15, $columns = array('*'))
	{
		$paginator = $this->connection->getPaginator();

		if (isset($this->groups))
		{
			return $this->groupedPaginate($paginator, $perPage, $columns);
		}
		else
		{
			return $this->ungroupedPaginate($paginator, $perPage, $columns);
		}
	}

	/**
	 * Create a paginator for a grouped pagination statement.
	 *
	 * @param  \Illuminate\Pagination\Environment  $paginator
	 * @param  int    $perPage
	 * @param  array  $columns
	 * @return \Illuminate\Pagination\Paginator
	 */
	protected function groupedPaginate($paginator, $perPage, $columns)
	{
		$results = $this->get($columns);

		return $this->buildRawPaginator($paginator, $results, $perPage);
	}

	/**
	 * Build a paginator instance from a raw result array.
	 *
	 * @param  \Illuminate\Pagination\Environment  $paginator
	 * @param  array  $results
	 * @param  int    $perPage
	 * @return \Illuminate\Pagination\Paginator
	 */
	public function buildRawPaginator($paginator, $results, $perPage)
	{
		// For queries which have a group by, we will actually retrieve the entire set
		// of rows from the table and "slice" them via PHP. This is inefficient and
		// the developer must be aware of this behavior; however, it's an option.
		$start = ($paginator->getCurrentPage() - 1) * $perPage;

		$sliced = array_slice($results, $start, $perPage);

		return $paginator->make($sliced, count($results), $perPage);
	}

	/**
	 * Create a paginator for an un-grouped pagination statement.
	 *
	 * @param  \Illuminate\Pagination\Environment  $paginator
	 * @param  int    $perPage
	 * @param  array  $columns
	 * @return \Illuminate\Pagination\Paginator
	 */
	protected function ungroupedPaginate($paginator, $perPage, $columns)
	{
		$total = $this->getPaginationCount();

		// Once we have the total number of records to be paginated, we can grab the
		// current page and the result array. Then we are ready to create a brand
		// new Paginator instances for the results which will create the links.
		$page = $paginator->getCurrentPage();

		$results = $this->forPage($page, $perPage)->get($columns);

		return $paginator->make($results, $total, $perPage);
	}

	/**
	 * Get the count of the total records for pagination.
	 *
	 * @return int
	 */
	public function getPaginationCount()
	{
		list($orders, $this->orders) = array($this->orders, null);

		// Because some database engines may throw errors if we leave the ordering
		// statements on the query, we will "back them up" and remove them from
		// the query. Once we have the count we will put them back onto this.
		$total = $this->count();

		$this->orders = $orders;

		return $total;
	}

	/**
	 * Determine if any rows exist for the current query.
	 *
	 * @return bool
	 */
	public function exists()
	{
		return $this->count() > 0;
	}

	/**
	 * Retrieve the "count" result of the query.
	 *
	 * @param  string  $column
	 * @return int
	 */
	public function count($column = '*')
	{
		return $this->aggregate(__FUNCTION__, array($column));
	}

	/**
	 * Retrieve the minimum value of a given column.
	 *
	 * @param  string  $column
	 * @return mixed
	 */
	public function min($column)
	{
		return $this->aggregate(__FUNCTION__, array($column));
	}

	/**
	 * Retrieve the maximum value of a given column.
	 *
	 * @param  string  $column
	 * @return mixed
	 */
	public function max($column)
	{
		return $this->aggregate(__FUNCTION__, array($column));
	}

	/**
	 * Retrieve the sum of the values of a given column.
	 *
	 * @param  string  $column
	 * @return mixed
	 */
	public function sum($column)
	{
		return $this->aggregate(__FUNCTION__, array($column));
	}

	/**
	 * Retrieve the average of the values of a given column.
	 *
	 * @param  string  $column
	 * @return mixed
	 */
	public function avg($column)
	{
		return $this->aggregate(__FUNCTION__, array($column));
	}

	/**
	 * Execute an aggregate function on the database.
	 *
	 * @param  string  $function
	 * @param  array   $columns
	 * @return mixed
	 */
	public function aggregate($function, $columns = array('*'))
	{
		$this->aggregate = compact('function', 'columns');

		$results = $this->get($columns);

		// Once we have executed the query, we will reset the aggregate property so
		// that more select queries can be executed against the database without
		// the aggregate value getting in the way when the grammar builds it.
		$this->aggregate = null;

		if (isset($results[0]))
		{
			$result = (array) $results[0];

			return $result['aggregate'];
		}
	}

	/**
	 * Insert a new record into the database.
	 *
	 * @param  array  $values
	 * @return bool
	 */
	public function insert(array $values)
	{
		// Since every insert gets treated like a batch insert, we will make sure the
		// bindings are structured in a way that is convenient for building these
		// inserts statements by verifying the elements are actually an array.
		if ( ! is_array(reset($values)))
		{
			$values = array($values);
		}

		$bindings = array();

		// We'll treat every insert like a batch insert so we can easily insert each
		// of the records into the database consistently. This will make it much
		// easier on the grammars to just handle one type of record insertion.
		foreach ($values as $record)
		{
			$bindings = array_merge($bindings, array_values($record));
		}

		$sql = $this->grammar->compileInsert($this, $values);

		// Once we have compiled the insert statement's SQL we can execute it on the
		// connection and return a result as a boolean success indicator as that
		// is the same type of result returned by the raw connection instance.
		$bindings = $this->cleanBindings($bindings);

		return $this->connection->insert($sql, $bindings);
	}

	/**
	 * Insert a new record and get the value of the primary key.
	 *
	 * @param  array   $values
	 * @param  string  $sequence
	 * @return int
	 */
	public function insertGetId(array $values, $sequence = null)
	{
		$sql = $this->grammar->compileInsertGetId($this, $values, $sequence);

		$values = $this->cleanBindings($values);

		return $this->processor->processInsertGetId($this, $sql, $values, $sequence);
	}

	/**
	 * Insert a new record into the database, ignore duplicate entries
	 *
	 * @param  array  $values
	 * @return bool
	 */
	public function insertIgnore(array $values)
	{
		// Since every insert gets treated like a batch insert, we will make sure the
		// bindings are structured in a way that is convenient for building these
		// inserts statements by verifying the elements are actually an array.
		if ( ! is_array(reset($values)))
		{
			$values = array($values);
		}

		$bindings = array();

		// We'll treat every insert like a batch insert so we can easily insert each
		// of the records into the database consistently. This will make it much
		// easier on the grammars to just handle one type of record insertion.
		foreach ($values as $record)
		{
			$bindings = array_merge($bindings, array_values($record));
		}

		$sql = $this->grammar->compileInsertIgnore($this, $values);

		// Once we have compiled the insert statement's SQL we can execute it on the
		// connection and return a result as a boolean success indicator as that
		// is the same type of result returned by the raw connection instance.
		$bindings = $this->cleanBindings($bindings);

		return $this->connection->insert($sql, $bindings);
	}

	/**
	 * Insert a new record and get the value of the primary key.
	 *
	 * @param  array   $values
	 * @param  string  $sequence
	 * @return int
	 */
	public function insertIgnoreGetId(array $values, $sequence = null)
	{
		$sql = $this->grammar->compileInsertIgnoreGetId($this, $values, $sequence);

		$values = $this->cleanBindings($values);

		return $this->processor->processInsertGetId($this, $sql, $values, $sequence);
	}

	/**
	 * Update a record in the database.
	 *
	 * @param  array  $values
	 * @return int
	 */
	public function update(array $values)
	{
		$bindings = array_values(array_merge($values, $this->bindings));

		$sql = $this->grammar->compileUpdate($this, $values);

		return $this->connection->update($sql, $this->cleanBindings($bindings));
	}

	/**
	 * Increment a column's value by a given amount.
	 *
	 * @param  string  $column
	 * @param  int     $amount
	 * @param  array   $extra
	 * @return int
	 */
	public function increment($column, $amount = 1, array $extra = array())
	{
		$wrapped = $this->grammar->wrap($column);

		$columns = array_merge(array($column => $this->raw("$wrapped + $amount")), $extra);

		return $this->update($columns);
	}

	/**
	 * Decrement a column's value by a given amount.
	 *
	 * @param  string  $column
	 * @param  int     $amount
	 * @param  array   $extra
	 * @return int
	 */
	public function decrement($column, $amount = 1, array $extra = array())
	{
		$wrapped = $this->grammar->wrap($column);

		$columns = array_merge(array($column => $this->raw("$wrapped - $amount")), $extra);

		return $this->update($columns);
	}

	/**
	 * Delete a record from the database.
	 *
	 * @param  mixed  $id
	 * @return int
	 */
	public function delete($id = null)
	{
		// If an ID is passed to the method, we will set the where clause to check
		// the ID to allow developers to simply and quickly remove a single row
		// from their database without manually specifying the where clauses.
		if ( ! is_null($id)) $this->where('id', '=', $id);

		$sql = $this->grammar->compileDelete($this);

		return $this->connection->delete($sql, $this->bindings);
	}

	/**
	 * Run a truncate statement on the table.
	 *
	 * @return void
	 */
	public function truncate()
	{
		foreach ($this->grammar->compileTruncate($this) as $sql => $bindings)
		{
			$this->connection->statement($sql, $bindings);
		}
	}

	/**
	 * Get a new instance of the query builder.
	 *
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function newQuery()
	{
		return new Builder($this->connection, $this->grammar, $this->processor);
	}

	/**
	 * Merge an array of where clauses and bindings.
	 *
	 * @param  array  $wheres
	 * @param  array  $bindings
	 * @return void
	 */
	public function mergeWheres($wheres, $bindings)
	{
		$this->wheres = array_merge($this->wheres, (array) $wheres);

		$this->bindings = array_values(array_merge($this->bindings, (array) $bindings));
	}

	/**
	 * Get a copy of the where clauses and bindings in an array.
	 *
	 * @return array
	 */
	public function getAndResetWheres()
	{
		$values = array($this->wheres, $this->bindings);

		list($this->wheres, $this->bindings) = array(null, array());

		return $values;
	}

	/**
	 * Remove all of the expressions from a list of bindings.
	 *
	 * @param  array  $bindings
	 * @return array
	 */
	protected function cleanBindings(array $bindings)
	{
		return array_values(array_filter($bindings, function($binding)
		{
			return ! $binding instanceof Expression;
		}));
	}

	/**
	 * Create a raw database expression.
	 *
	 * @param  mixed  $value
	 * @return \Illuminate\Database\Query\Expression
	 */
	public function raw($value)
	{
		return $this->connection->raw($value);
	}

	/**
	 * Get the current query value bindings.
	 *
	 * @return array
	 */
	public function getBindings()
	{
		return $this->bindings;
	}

	/**
	 * Set the bindings on the query builder.
	 *
	 * @param  array  $bindings
	 * @return void
	 */
	public function setBindings(array $bindings)
	{
		$this->bindings = $bindings;
	}

	/**
	 * Merge an array of bindings into our bindings.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function mergeBindings(Builder $query)
	{
		$this->bindings = array_values(array_merge($this->bindings, $query->bindings));

		return $this;
	}

	/**
	 * Get the database connection instance.
	 *
	 * @return \Illuminate\Database\ConnectionInterface
	 */
	public function getConnection()
	{
		return $this->connection;
	}

	/**
	 * Get the database query processor instance.
	 *
	 * @return \Illuminate\Database\Query\Processors\Processor
	 */
	public function getProcessor()
	{
		return $this->processor;
	}

	/**
	 * Get the query grammar instance.
	 *
	 * @return \Illuminate\Database\Grammar
	 */
	public function getGrammar()
	{
		return $this->grammar;
	}

	/**
	 * Handle dynamic method calls into the method.
	 *
	 * @param  string  $method
	 * @param  array   $parameters
	 * @return mixed
	 */
	public function __call($method, $parameters)
	{
		if (starts_with($method, 'where'))
		{
			return $this->dynamicWhere($method, $parameters);
		}

		$className = get_class($this);
		throw new \BadMethodCallException("Call to undefined method {$className}::{$method}()");
	}

}
