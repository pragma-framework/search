<?php
namespace Pragma\Search;

use Pragma\ORM\Model;

class Keyword extends Model{
	const TABLE_NAME = 'keywords';

	public function __construct(){
		parent::__construct(self::getTableName());
	}

	public static function getTableName(){
		defined('DB_PREFIX') OR define('DB_PREFIX','pragma_');
		return DB_PREFIX.self::TABLE_NAME;
	}

	public static function find_or_create($word, $lemme){
		$k = static::forge()->where('word', '=', $word)->first();
		if( ! $k ){
			return static::create($word, $lemme);
		}
		else return $k;
	}

	public static function create($word, $lemme){
		return static::build([
			'word' 	=> $word,
			'lemme' => $lemme
			])->save();
	}

	public function store(Context $context, $classname, $id, $col){
		Index::build([
			'keyword_id' 			=> $this->id,
			'context_id'			=> $context->id,
			'indexable_type'	=> $classname,
			'indexable_id'		=> $id,
			'col'							=> $col
			])->save();
	}
}
