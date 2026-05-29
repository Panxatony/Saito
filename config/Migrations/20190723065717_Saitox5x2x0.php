<?php
use Migrations\BaseMigration;

class Saitox5x2x0 extends BaseMigration
{
    public function up()
    {
        $this->table('uploads')
            ->addColumn('title', 'string', [
                'after' => 'name',
                'default' => null,
                'length' => 200,
                'null' => true,
            ])
            ->update();

        $this->execute('DELETE FROM `settings` WHERE `name` IN (\'upload_max_img_size\')');
        $this->execute('DELETE FROM `settings` WHERE `name` IN (\'upload_max_number_of_uploads\')');
    }

    public function down()
    {
        $this->table('uploads')
            ->removeColumn('title')
            ->update();
    }
}
