<?php
namespace Pragma\Search;

class Module {
	public static function getDescription(){
		return array(
			"Pragma-Framework/Search",
			array(
				"index.php indexer:run\t\tIndex datas",
				"index.php indexer:rebuild\tRebuild full indexed datas",
			),
		);
	}
}