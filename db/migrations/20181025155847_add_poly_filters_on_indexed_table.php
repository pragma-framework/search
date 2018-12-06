<?php

use Phinx\Migration\AbstractMigration;

class AddPolyFiltersOnIndexedTable extends AbstractMigration {
	public function change() {
		$this->table('indexed')->addColumn('polyfilters', "text", ['null' => true])->update();
	}
}
