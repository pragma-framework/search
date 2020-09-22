<?php
namespace Pragma\Search;

use Pragma\DB\DB;

class Processor{
	const DEFAULT_MIN_WORD_LENGTH = 3;
	public static $threshold = null;
	public static $stemmer = null;
	public static $keywords = null;

	public static function get_context($text){
		$lines = preg_split('/[\n\r\t]/', $text, null, PREG_SPLIT_NO_EMPTY);
		$context = [];
		if(!empty($lines)){
			array_walk($lines, function($line, $k) use (&$context){
				$context[] = ['line' => $line, 'words' => static::parse($line)];
			});
		}
		return $context;
	}

	public static function parse($line, $user_threshold = null){
		if(is_null(static::$threshold) || static::$threshold != $user_threshold){
			$min = defined('PRAGMA_SEARCH_MIN_WORD_LENGTH') ? PRAGMA_SEARCH_MIN_WORD_LENGTH : static::DEFAULT_MIN_WORD_LENGTH;

			if(is_null($user_threshold)){
				static::$threshold = $min;
			}
			else{
				if($user_threshold < $min){
					trigger_error("The min_word_length size is lesser than the one chosen for the indexation. You may want to redefine PRAGMA_SEARCH_MIN_WORD_LENGTH and rebuild your whole index in order to use this value");

				}
				static::$threshold = max($user_threshold, $min);
			}
		}

		$words = preg_split('/([\s!=\+:;\*\/\?,\'’`"&\(\)_¶§\|%\p{So}<>]+)|\.(?!\w)|( -)|(- )/mui', trim(self::clean_accents($line)), null, PREG_SPLIT_NO_EMPTY);
		$cleaned = [];
		if(!empty($words)){
			//preprocessing of the words founded
			array_walk($words, function($w, $key) use(&$cleaned){
				$w = mb_strtolower($w);

				if(\mb_strlen($w) < static::$threshold){
					return false;
				}
				if(\strpos($w, '-') !== false){
					$cleaned = array_merge($cleaned, array_filter(explode('-', $w), function($val) {
							return \mb_strlen($val) >= static::$threshold;
					}));
				}

				$cleaned[$w] = $w;
				return true;
			});

			$words = null;
		}
		return $cleaned;
	}

	public static function getStemmer(){
		if(is_null(static::$stemmer)){
			$stem_lang = defined('STEMMER_LANGUAGE') ? "Wamania\\Snowball\\".STEMMER_LANGUAGE : "Wamania\\Snowball\\English";
			static::$stemmer = new $stem_lang();
		}
		return static::$stemmer;
	}

	public static function index_object($obj, $clean = false, $immediatly = false){
		if(is_null(static::$keywords) && ! $immediatly){
			static::init_keywords();//in case we just want to index an object without rebuild the whole index
		}

		if(method_exists($obj, 'get_indexed_cols')){
			list($cols, $infile) = $obj->get_indexed_cols();
			if(empty($cols)){
				trigger_error("Object ".get_class($obj)." has no column to index");
				return;
			}

			foreach($cols as $col => $idx){
				$pk = $obj->get_primary_key();
				$objID = "";
				if(is_array($pk)) {
					$firstPk = true;
					foreach($pk as $field) {
						if($firstPk) {
							$firstPk = false;
						}
						else {
							$objID .= ':';
						}
						$objID .= $obj->$field;
					}
				}
				else {
					$objID = $obj->$pk;
				}

				static::index_col(get_class($obj), $objID, $col, $obj->$col, isset($infile[$col]), $clean, $immediatly);
			}
		}
		else{
			throw new \Exception("Object ".get_class($obj)." is not searchable", 1);
		}
	}

	public static function rebuild($classes = []){//repart de 0
		static::clean_all();
		static::$keywords = [];//no need to do a sql query

		$classes = array_merge($classes, Indexed::forge()->select(['classname', 'polyfilters'])->get_arrays('classname'));

		if(!empty($classes)){
			foreach($classes as $c){
				if(class_exists($c['classname'])){
					$all = [];
					if(empty($c['polyfilters'])) {
						$all = $c['classname']::all(true, function(&$obj) {
							$obj->skipHooks();
						} );
					}
					else {//need to create a query based on the filters
						$filters = json_decode($c['polyfilters'], true);
						$qb = $c['classname']::forge();
						if(!empty($filters)) {
							foreach($filters as $f) {
								if(is_array($f) && count($f) == 3) {
									$qb->where($f[0], $f[1], $f[2]);//assuming that the developper knows its columns
								}
							}
							$all = $qb->get_objects();
						}
					}
					if(!empty($all)){
						foreach($all as $obj){
							static::index_object($obj);
						}
					}
					$all = null;
					unset($all);
				}
			}
		}
	}

	public static function index_pendings(){
		static::init_keywords();
		$pendings = PendingIndexCol::forge()->get_arrays();
		if(!empty($pendings)){
			$cobayes = $keep_ids = [];
			$can_truncate = true;
			foreach($pendings as $p){
				//if the project shares the DB with other apps
				if( ! class_exists($p['indexable_type'])) {
					$can_truncate = false;
					$keep_ids[$p['id']] = $p['id'];
					continue;
				}else{
					$ref = new \ReflectionClass($p['indexable_type']);
					if($ref->isAbstract()){
						$can_truncate = false;
						$keep_ids[$p['id']] = $p['id'];
						continue;
					}
				}

				if( ! isset($cobayes[$p['indexable_type']])){
					$cobayes[$p['indexable_type']] = new $p['indexable_type'];
				}

				list($cols, $infile) = $cobayes[$p['indexable_type']]->get_indexed_cols();

				if($p['deleted']){
					foreach($cols as $col => $i){
						static::clean_col_index($p['indexable_type'], $p['indexable_id'], $col);
					}
				}else{
					static::index_col($p['indexable_type'], $p['indexable_id'], $p['col'], $p['value'], $p['infile'], true);//true : we should clean the index before re-indexing the col
				}
			}
			$db = DB::getDB();
			if($can_truncate) {
				$db->query('TRUNCATE '.PendingIndexCol::getTableName());
			}
			else if (!empty($keep_ids)) {
				$params = [];
				$db->query('DELETE FROM '.PendingIndexCol::getTableName().' WHERE id NOT IN ('.$db->getPDOParamsFor($keep_ids, $params).')', $params);
			}
		}

		static::clean_trailing_keywords();
	}

	private static function index_col($classname, $id, $col, $text = null, $infile = false, $clean = false, $immediatly = false){
		if($clean){
			static::clean_col_index($classname, $id, $col);
		}

		if( ! empty($text) ){
			$parsing = null;
			if( ! $infile ){
				$parsing = static::get_context($text);
			}
			elseif( ! empty($text) ){//especially useful for the documents
				if(file_exists($text)){
					$parsing = static::get_context(file_get_contents($text));
				}
			}

			if(!empty($parsing)){
				if($immediatly){
					static::init_keywords(true, $parsing);
				}

				$contexts = [];
				$words = [];

				$skip_contexts = defined('PRAGMA_SEARCH_SKIP_CONTEXT') && PRAGMA_SEARCH_SKIP_CONTEXT;

				foreach($parsing as $p){
					if(!empty($p['words'])){

						if( ! $skip_contexts ) {
							if(!isset($contexts[$p['line']])) {
								$contexts[$p['line']] = Context::build(['context' => $p['line']])->save();
							}

							$context = $contexts[$p['line']];
						}

						foreach($p['words'] as $w){
							if(isset(static::$keywords[$w])){
								$kw = Keyword::build(static::$keywords[$w]);//no save
							}
							else{
								//create can return a null object if the word is duplicated because of too many characters
								$kw = Keyword::create($w, static::getStemmer()->stem($w));
								if($kw) {
									static::$keywords[$w] = $kw->as_array();
								}
							}

							if ( ! $skip_contexts ) {
								if($kw && ! isset($words[$context->id][$w])) {
									$words[$context->id][$w] = 1;
									$kw->store($context, $classname, $id, $col);
									$kw = null;
									unset($kw);
								}
							}
							else if($kw && ! isset($words[$id][$col][$w])) { // if the contexts are skipped, the unicity will be based on the indexable_id and the keyword_id and the col
								$words[$id][$col][$w] = 1;
								$kw->store(null, $classname, $id, $col);//context_id will be null
								$kw = null;
								unset($kw);
							}
						}
					}
				}
				unset($contexts);
				unset($words);
			}
		}
	}

	private static function init_keywords($short_listed = false, $list = []){
		if( ! $short_listed ){
			static::$keywords = Keyword::forge()->get_arrays('word');
		}
		else{//we want to restrict the memory usage (especially in an immediat indexation)
			$words = [];
			if( ! empty($list) ){
				array_walk($list, function($val, $key) use (&$words) {
					$words += isset($val['words']) ? $val['words'] : [];
				});

				if(!empty($words)) {
					static::$keywords = Keyword::forge()
						->where('word', 'in', $words)->get_arrays('word');
				}
				else {
					static::$keywords = [];
				}
			}
			else{
				static::$keywords = [];
			}
		}
	}

	private static function clean_col_index($classname, $id, $col){
		$db = DB::getDB();

		$skip_contexts = defined('PRAGMA_SEARCH_SKIP_CONTEXT') && PRAGMA_SEARCH_SKIP_CONTEXT;

		if (!$skip_contexts) {
			$cids = Index::forge()
				->select('DISTINCT(context_id) as context_id')
				->where('indexable_type', '=', $classname)
				->where('indexable_id', '=', $id)
				->where('col', '=', $col)
				->get_arrays();
			$todelete = [];
			foreach($cids as $c){
				$todelete[] = $c['context_id'];
			}

			if(!empty($todelete)){
				$params = [];
				$db->query('DELETE FROM '.Context::getTableName().' WHERE id IN ('.DB::getPDOParamsFor($todelete, $params).')', $params);
			};
		}

		$db->query('DELETE FROM '.Index::getTableName().'
								WHERE indexable_type = ?
								AND indexable_id = ?
								AND col = ?', [$classname, $id, $col]);
	}

	private static function clean_all(){
		$db = DB::getDB();
		$db->query('TRUNCATE '.Keyword::getTableName());
		$db->query('TRUNCATE '.Index::getTableName());
		$db->query('TRUNCATE '.Context::getTableName());
	}

	private static function clean_trailing_keywords(){
		$db = DB::getDB();
		$db->query('DELETE k FROM '.Keyword::getTableName().' k
								LEFT JOIN '.Index::getTableName().' i ON k.id = i.keyword_id
								WHERE i.id IS NULL');
	}

	private static function clean_accents($string) {
		if ( !preg_match('/[\x80-\xff]/', $string) ) {
		  return $string;
		}
		$chars = array(
		// Decompositions for Latin-1 Supplement
		chr(195).chr(128) => 'A', chr(195).chr(129) => 'A',
		chr(195).chr(130) => 'A', chr(195).chr(131) => 'A',
		chr(195).chr(132) => 'A', chr(195).chr(133) => 'A',
		chr(195).chr(135) => 'C', chr(195).chr(136) => 'E',
		chr(195).chr(137) => 'E', chr(195).chr(138) => 'E',
		chr(195).chr(139) => 'E', chr(195).chr(140) => 'I',
		chr(195).chr(141) => 'I', chr(195).chr(142) => 'I',
		chr(195).chr(143) => 'I', chr(195).chr(145) => 'N',
		chr(195).chr(146) => 'O', chr(195).chr(147) => 'O',
		chr(195).chr(148) => 'O', chr(195).chr(149) => 'O',
		chr(195).chr(150) => 'O', chr(195).chr(153) => 'U',
		chr(195).chr(154) => 'U', chr(195).chr(155) => 'U',
		chr(195).chr(156) => 'U', chr(195).chr(157) => 'Y',
		chr(195).chr(159) => 's', chr(195).chr(160) => 'a',
		chr(195).chr(161) => 'a', chr(195).chr(162) => 'a',
		chr(195).chr(163) => 'a', chr(195).chr(164) => 'a',
		chr(195).chr(165) => 'a', chr(195).chr(167) => 'c',
		chr(195).chr(168) => 'e', chr(195).chr(169) => 'e',
		chr(195).chr(170) => 'e', chr(195).chr(171) => 'e',
		chr(195).chr(172) => 'i', chr(195).chr(173) => 'i',
		chr(195).chr(174) => 'i', chr(195).chr(175) => 'i',
		chr(195).chr(177) => 'n', chr(195).chr(178) => 'o',
		chr(195).chr(179) => 'o', chr(195).chr(180) => 'o',
		chr(195).chr(181) => 'o', chr(195).chr(182) => 'o',
		chr(195).chr(182) => 'o', chr(195).chr(185) => 'u',
		chr(195).chr(186) => 'u', chr(195).chr(187) => 'u',
		chr(195).chr(188) => 'u', chr(195).chr(189) => 'y',
		chr(195).chr(191) => 'y',
		// Decompositions for Latin Extended-A
		chr(196).chr(128) => 'A', chr(196).chr(129) => 'a',
		chr(196).chr(130) => 'A', chr(196).chr(131) => 'a',
		chr(196).chr(132) => 'A', chr(196).chr(133) => 'a',
		chr(196).chr(134) => 'C', chr(196).chr(135) => 'c',
		chr(196).chr(136) => 'C', chr(196).chr(137) => 'c',
		chr(196).chr(138) => 'C', chr(196).chr(139) => 'c',
		chr(196).chr(140) => 'C', chr(196).chr(141) => 'c',
		chr(196).chr(142) => 'D', chr(196).chr(143) => 'd',
		chr(196).chr(144) => 'D', chr(196).chr(145) => 'd',
		chr(196).chr(146) => 'E', chr(196).chr(147) => 'e',
		chr(196).chr(148) => 'E', chr(196).chr(149) => 'e',
		chr(196).chr(150) => 'E', chr(196).chr(151) => 'e',
		chr(196).chr(152) => 'E', chr(196).chr(153) => 'e',
		chr(196).chr(154) => 'E', chr(196).chr(155) => 'e',
		chr(196).chr(156) => 'G', chr(196).chr(157) => 'g',
		chr(196).chr(158) => 'G', chr(196).chr(159) => 'g',
		chr(196).chr(160) => 'G', chr(196).chr(161) => 'g',
		chr(196).chr(162) => 'G', chr(196).chr(163) => 'g',
		chr(196).chr(164) => 'H', chr(196).chr(165) => 'h',
		chr(196).chr(166) => 'H', chr(196).chr(167) => 'h',
		chr(196).chr(168) => 'I', chr(196).chr(169) => 'i',
		chr(196).chr(170) => 'I', chr(196).chr(171) => 'i',
		chr(196).chr(172) => 'I', chr(196).chr(173) => 'i',
		chr(196).chr(174) => 'I', chr(196).chr(175) => 'i',
		chr(196).chr(176) => 'I', chr(196).chr(177) => 'i',
		chr(196).chr(178) => 'IJ',chr(196).chr(179) => 'ij',
		chr(196).chr(180) => 'J', chr(196).chr(181) => 'j',
		chr(196).chr(182) => 'K', chr(196).chr(183) => 'k',
		chr(196).chr(184) => 'k', chr(196).chr(185) => 'L',
		chr(196).chr(186) => 'l', chr(196).chr(187) => 'L',
		chr(196).chr(188) => 'l', chr(196).chr(189) => 'L',
		chr(196).chr(190) => 'l', chr(196).chr(191) => 'L',
		chr(197).chr(128) => 'l', chr(197).chr(129) => 'L',
		chr(197).chr(130) => 'l', chr(197).chr(131) => 'N',
		chr(197).chr(132) => 'n', chr(197).chr(133) => 'N',
		chr(197).chr(134) => 'n', chr(197).chr(135) => 'N',
		chr(197).chr(136) => 'n', chr(197).chr(137) => 'N',
		chr(197).chr(138) => 'n', chr(197).chr(139) => 'N',
		chr(197).chr(140) => 'O', chr(197).chr(141) => 'o',
		chr(197).chr(142) => 'O', chr(197).chr(143) => 'o',
		chr(197).chr(144) => 'O', chr(197).chr(145) => 'o',
		chr(197).chr(146) => 'OE',chr(197).chr(147) => 'oe',
		chr(197).chr(148) => 'R',chr(197).chr(149) => 'r',
		chr(197).chr(150) => 'R',chr(197).chr(151) => 'r',
		chr(197).chr(152) => 'R',chr(197).chr(153) => 'r',
		chr(197).chr(154) => 'S',chr(197).chr(155) => 's',
		chr(197).chr(156) => 'S',chr(197).chr(157) => 's',
		chr(197).chr(158) => 'S',chr(197).chr(159) => 's',
		chr(197).chr(160) => 'S', chr(197).chr(161) => 's',
		chr(197).chr(162) => 'T', chr(197).chr(163) => 't',
		chr(197).chr(164) => 'T', chr(197).chr(165) => 't',
		chr(197).chr(166) => 'T', chr(197).chr(167) => 't',
		chr(197).chr(168) => 'U', chr(197).chr(169) => 'u',
		chr(197).chr(170) => 'U', chr(197).chr(171) => 'u',
		chr(197).chr(172) => 'U', chr(197).chr(173) => 'u',
		chr(197).chr(174) => 'U', chr(197).chr(175) => 'u',
		chr(197).chr(176) => 'U', chr(197).chr(177) => 'u',
		chr(197).chr(178) => 'U', chr(197).chr(179) => 'u',
		chr(197).chr(180) => 'W', chr(197).chr(181) => 'w',
		chr(197).chr(182) => 'Y', chr(197).chr(183) => 'y',
		chr(197).chr(184) => 'Y', chr(197).chr(185) => 'Z',
		chr(197).chr(186) => 'z', chr(197).chr(187) => 'Z',
		chr(197).chr(188) => 'z', chr(197).chr(189) => 'Z',
		chr(197).chr(190) => 'z', chr(197).chr(191) => 's'
		);

		$string = strtr($string, $chars);

		return $string;
	}
}
