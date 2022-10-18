<?php
namespace Pragma\Search;

use Pragma\ORM\Model;
use Pragma\DB\DB;

class PendingIndexCol extends Model{
	const TABLE_NAME = 'pending_indexs';

	public function __construct(){
		parent::__construct(self::getTableName());
	}

	public static function getTableName(){
		defined('DB_PREFIX') OR define('DB_PREFIX','pragma_');
		return DB_PREFIX.self::TABLE_NAME;
	}

	public static function store($obj, $col, $infile = false, $delete = false){
		$db = DB::getDB();
		$db->query('DELETE FROM '.static::getTableName().'
								WHERE indexable_type = ?
								AND indexable_id = ?
								AND col = ?', [get_class($obj), $obj->id, $col]);

		self::build([
			'indexable_type' 	=> get_class($obj),
			'indexable_id' 		=> $obj->id,
			'col' 						=> $col,
			'infile'					=> $infile,
			'value'						=> $obj->$col,
			'deleted'					=> $delete
			])->save();


	}
}
