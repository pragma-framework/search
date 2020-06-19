<?php
namespace Pragma\Search;

class Module
{
    public static function getDescription()
    {
        return [
            "Pragma-Framework/Search",
            [
                "index.php indexer:run\t\tIndex datas",
                "index.php indexer:rebuild\tRebuild full indexed datas",
                "index.php indexer:indexed\tUpdate indexed database",
            ],
        ];
    }
}
