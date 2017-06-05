<?php
namespace Pragma\Search;

use Pragma\ORM\Model;

class Indexed extends Model{
	const TABLE_NAME = 'indexed';

	public function __construct(){
		parent::__construct(self::TABLE_NAME);
	}
}
