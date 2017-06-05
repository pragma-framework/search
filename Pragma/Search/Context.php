<?php
namespace Pragma\Search;

use Pragma\ORM\Model;

class Context extends Model{
	const TABLE_NAME = 'contexts';

	public function __construct(){
		parent::__construct(self::TABLE_NAME);
	}
}
