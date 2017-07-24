<?php

use Phinx\Migration\AbstractMigration;

use Pragma\Search\Keyword;

class UpdateTableKeywords extends AbstractMigration
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
    public function up()
    {
        $t = $this->table(Keyword::getTableName());
        $t->changeColumn('word', 'char', ['limit' => 64, 'collation' => 'utf8_bin'])->update();
    }
    public function donw()
    {
        $t = $this->table(Keyword::getTableName());
        $t->changeColumn('word', 'char', ['limit' => 64, 'collation' => 'utf8_general_ci'])->update();
    }
}
