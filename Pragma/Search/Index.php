<?php
namespace Pragma\Search;

use Pragma\ORM\Model;

class Index extends Model{
	const TABLE_NAME = 'indexes';

	public function __construct(){
		parent::__construct(self::TABLE_NAME);
	}
}
