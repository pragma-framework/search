<?php

use Phinx\Migration\AbstractMigration;

class AllowNullOnIndexesContextId extends AbstractMigration {
	public function change() {
		if(defined('ORM_ID_AS_UID') && ORM_ID_AS_UID){
			$strategy = ! defined('ORM_UID_STRATEGY') ? 'php' : ORM_UID_STRATEGY;
			$t = $this->table('indexes');
			switch($strategy){
				case 'mysql':
				case 'laravel-uuid':
						$t->changeColumn('context_id', 'char', ['limit' => 36, "null" => true])->update();
					break;
				default:
				case 'php':
					$t->changeColumn('context_id', 'char', ['limit' => 23, "null" => true])->update();
					break;
			}
		}
		else{
			$t = $this->table('indexes');
			$t->changeColumn('context_id', 'integer', ["null" => true])->update();
		}
	}
}
