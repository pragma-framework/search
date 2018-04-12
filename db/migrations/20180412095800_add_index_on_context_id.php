<?php

use Phinx\Migration\AbstractMigration;

class AddIndexOnContextId extends AbstractMigration {
    public function change() {
        $this->table('indexes')->addIndex(['context_id'])->update();
    }
}
