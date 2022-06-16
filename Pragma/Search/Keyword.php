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
		try {
			return static::build([
				'word' 	=> $word,
				'lemme' => $lemme
				])->save();
		}
		catch(\Pragma\Exceptions\DBException $e) {
			//duplicate Word ?
			$existingWord = static::forge()->where('word', '=', $word)->where('lemme', '=', $lemme)->first();
			return $existingWord ? $existingWord : null;
		}
		catch(\Exception $e1) {
			return null;
		}
	}

	public function store(Context $context = null, $classname, $id, $col){
		Index::build([
			'keyword_id' 			=> $this->id,
			'context_id'			=> ! is_null($context) ? $context->id : null,
			'indexable_type'	=> $classname,
			'indexable_id'		=> $id,
			'col'							=> $col
			])->save();
	}
}
