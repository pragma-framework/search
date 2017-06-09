<?php
namespace Pragma\Search;

use Pragma\ORM\Model;

class Context extends Model{
	const TABLE_NAME = 'contexts';

	public function __construct(){
		parent::__construct(self::getTableName());
	}

	public static function getTableName(){
		defined('DB_PREFIX') OR define('DB_PREFIX','pragma_');
		return DB_PREFIX.self::TABLE_NAME;
	}
}
