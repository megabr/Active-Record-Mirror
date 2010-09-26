<?

class Query implements Iterator
{
	static $db;						// pdo object
	private $class;					// class of object to return from queries
	private $select = array('*');	// select
	private $table; 				// the table that queries will be using
	private $from;					// hmmm
	private $rs;					// internal recordset object
	private $object;				// current object in recordset
	private $position = 0;			// cursor
	private $where = array();		// array of where conditions
	private $order = array();		// order clause
	private $limit;					// limit
	private $offset = 0;			// offset
	private $params = array();		// query parameters
		
	public function __construct($table, $class="stdClass")
	{
		$this->table = $table;
		$this->from = $table;
		$this->class = $class;
		$this->position = 0;
	}
	
	public function select()
	{
		$this->select = func_get_args();
		return $this;	// chaining!		
	}
	
	public function where()
	{
		$this->where[] = func_get_args();		
		return $this;	// chaining!
	}

	public function order()
	{
		$this->order = func_get_args();
		return $this;	// chaining!
	}
	
	public function limit($limit)
	{
		$this->limit = intval($limit);
		return $this;	// chaining baby!
	}
	
	public function offset($offset)
	{
		$this->offset = intval($offset);
		return $this;	// chaining baby!		
	}
	
	private function prepare($args)
	{
		$sql = array_shift($args);
		$this->params = array_merge($this->params, $args);
		return $sql;
	}

	private function execSQL()
	{
		$from = $this->from ? $this->from : $this->table; 
		$sql = "SELECT ".$this->prepare($this->select)." FROM $from";
		if($this->where)
		{
			$conditions = array();
			foreach($this->where as $condition) $conditions[]=$this->prepare($condition);
			$sql.=" WHERE (".implode( ") AND (", $conditions).")";
		}
		if($this->order) $sql.=" ORDER BY ".$this->prepare($this->order);
		if($this->limit) $sql.=" LIMIT {$this->limit}";
		if($this->offset) $sql.=" OFFSET {$this->offset}";			
		$this->rs = self::$db->prepare($sql);
		$this->rs->execute($this->params);
	}
	
	public function count()
	{
		$q = new Query($this->table);
		$q = $q->select('COUNT(*) AS count');
		foreach($this->where as $condition) $q = $q->where($condition);
		return $q->first()->count;
	}
	
	public function columns()
	{
		$columns = array();
		$q = self::$db->query("SHOW COLUMNS FROM `$this->table`");
		while($column=$q->fetchObject()) $columns[] = $column->Field;
		return $columns;
	}
	
	public function insert($record, $primaryKey='id')
	{
		$vals = array();
		$keys = array();
		foreach ($this->columns() as $key)
		{
			if($key!=$primaryKey)
			{
				$vals[]=self::$db->quote($record->$key);
				$keys[]="`".$key."`";
			}
		}
		$sql="INSERT INTO `{$this->table}` (".implode($keys, ", ").") VALUES (".implode($vals, ", ").")";
		if(self::$db->exec($sql)) return self::$db->lastInsertId();
		return false;
	}
	
	public function update($record, $primaryKey='id')
	{	
		$set = array();
		foreach ($this->columns() as $key) if($key!=$primaryKey) $set[] = "`$key` = ".self::$db->quote($record->$key);
		$sql="UPDATE `{$this->table}` SET ".implode($set, ", ")." WHERE `$primaryKey`={$record->$primaryKey}";
		return self::$db->exec($sql);
	}
	
	public function delete($record, $primaryKey='id')
	{
		$sql="DELETE FROM `{$this->table}` WHERE `$primaryKey`={$record->$primaryKey}";
		return self::$db->exec($sql);
	}
	
	// iterator
	
	public function seek($position)
	{
		$this->rewind();
		while ($this->position < $position) $this->next();
		return $this->object;
	}
	
	public function rewind()
	{
		$this->position = 0;
		$this->execSQL();
		$this->object = $this->rs->fetchObject($this->class);
	}
	
	public function current()
	{
		return $this->object;
	}
		
	public function key()
	{
		return $this->position;
	}
	
	public function next()
	{
		$this->position++;
		$this->object = $this->rs->fetchObject($this->class);
	}
	
	public function valid()
	{
		return (boolean) $this->object;
	}
	
	// end iterator
	
	public function first()
	{
		$this->rewind();
		return $this->current();
	}
	
	public function __toString()
	{
		return print_r($this, true);
	}
	
}

class Record
{
	#requires php 5.3
	
	static $has_many = array();
	static $belongs_to = array();
	static $validates_presence_of = array();

	public static function all()
	{
		return new Query(get_called_class(), strtolower(get_called_class()));
	}
		
	public static function find($id)
	{
		return self::all()->where("`id`=?", $id )->first();
	}
	
	public static function table()
	{
		return strtolower(get_called_class());
	}
	
	public function __construct($array = array())
	{
		if ($array) $this->attributes($array);
	}
	
	public function attributes($array)
	{
		foreach($array as $key=>$val) $this->$key=$val;		
	}	
	
	public function save()
	{
		$class = get_class($this);
	#	foreach($class::$validates_presence_of as $attr) if (empty($this->$attr)) @$this->errors[$attr] = "$attr can not be empty";
	#	if($this->errors) return false;
		$query = new Query(get_class($this), get_class($this));
		return $this->id ? $query->update($this, 'id') : $this->id = $query->insert($this, 'id');
	}
	
	public function delete($cascade=true)
	{
		if($cascade)
		{
			$class = get_called_class();
			foreach($class::$has_many as $childClass) foreach($this->children($childClass) as $child) $child->delete();
		}
		$query = new Query(self::table());
		$query->delete($this, 'id');
	}
	
	public function children($class)
	{
		$query = new Query($class, strtolower($class));
		return $query->where("`".get_class($this)."_id` = '{$this->id}'");
	}
	
	public function parent($class)
	{
		$query = new Query($class, strtolower($class));
		$foreignKey = strtolower($class)."_id";
		return $query->where("id=?", $this->$foreignKey)->first();
	}
	
	public function add($class, $array=array())
	{
		$object = new $class($array);
		$foreignKey = get_class($this)."_id";
		$object->foreignKey = $this->id;
		$object->save();
		return $object;
	}
	
	public function __get($attr)
	{
		$class = get_called_class();
		if( isset($class::$has_many[$attr]) ) return $this->children($class::$has_many[$attr]);
		if( isset($class::$belongs_to[$attr]) ) return $this->parent($class::$belongs_to[$attr]);
		return $this->$attr;
	}
	
	public function __toString()
	{
		return get_class($this).' '.$this->id;
		return print_r($this, true);
	}
		
}

?>