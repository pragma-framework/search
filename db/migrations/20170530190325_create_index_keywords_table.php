<?php

use Phinx\Migration\AbstractMigration;

class CreateIndexKeywordsTable extends AbstractMigration
{
		/**
		 * Change Method.
		 *
		 * Write your reversible migrations using this method.
		 *
		 * More information on writing migrations is available here:
		 * http://docs.phinx.org/en/latest/migrations.html#the-abstractmigration-class
		 *
		 * The following commands can be used in this method and Phinx will
		 * automatically reverse them when rolling back:
		 *
		 *    createTable
		 *    renameTable
		 *    addColumn
		 *    renameColumn
		 *    addIndex
		 *    addForeignKey
		 *
		 * Remember to call "create()" or "update()" and NOT "save()" when working
		 * with the Table class.
		 */
		public function change(){
			if(defined('ORM_ID_AS_UID') && ORM_ID_AS_UID){
				$strategy = ! defined('ORM_UID_STRATEGY') ? 'php' : ORM_UID_STRATEGY;
				$t = $this->table('keywords', ['id' => false, 'primary_key' => 'id']);
				switch($strategy){
					case 'mysql':
					case 'laravel-uuid':
						$t->addColumn('id', 'char', ['limit' => 36]);
						break;
					default:
					case 'php':
						$t->addColumn('id', 'char', ['limit' => 23]);
						break;
				}
			}
			else{
				$t = $this->table('keywords');
			}

			$t->addColumn('word', 'char', ['limit' => 64])
				->addColumn('lemme', 'char', ['limit' => 64])
				->addIndex(['word'], ['unique' => true])
				->addIndex(['lemme'])
				->create();
		}
}
