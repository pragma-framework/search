<?php
namespace Pragma\Search;

use Pragma\DB\DB;

class Processor{
	const MIN_WORD_LENGTH = 3;
	public static $stemmer = null;
	public static $keywords = null;

	public static function get_context($text){
		$lines = preg_split('/[\n\r\t]/', $text, null, PREG_SPLIT_NO_EMPTY);
		$context = [];
		if(!empty($lines)){
			array_walk($lines, function($line, $k) use (&$context){
				$context[] = ['line' => $line, 'words' => static::parse($line, $context)];
			});
		}
		return $context;
	}

	public static function parse($line){
		$words = preg_split('/([\s!=\+:;\*\/\?,\'&\(\)_¶§\|%\p{So}<>]+)|\.(?!\w)|( -)|(- )/mui', trim($line), null, PREG_SPLIT_NO_EMPTY);
		if(!empty($words)){
			//preprocessing des mots trouvés
			$cleaned = [];
			array_walk($words, function($w, $key) use(&$cleaned){
				$w = mb_strtolower($w);

				if(\mb_strlen($w) < static::MIN_WORD_LENGTH){
					return false;
				}
				if(\strpos($w, '-') !== false){
					$cleaned = array_merge($cleaned, explode('-', $w));
				}

				$cleaned[$w] = $w;
				return true;
			});

			$words = null;
			return $cleaned;
		}
	}

	public static function getStemmer(){
		if(is_null(static::$stemmer)){
			$stem_lang = defined('STEMMER_LANGUAGE') ? "Wamania\\Snowball\\".STEMMER_LANGUAGE : "Wamania\\Snowball\\English";
			static::$stemmer = new $stem_lang();
		}
		return static::$stemmer;
	}

	public static function index_object($obj, $clean = false){
		if(is_null(static::$keywords)){
			static::init_keywords();//in case we just want to index an object without rebuild the whole index
		}

		if(method_exists($obj, 'get_indexed_cols')){
			list($cols, $infile) = $obj->get_indexed_cols();
			if(empty($cols)){
				throw new Exception("Object ".get_class($obj)." has no column to index", 1);
				return;
			}

			foreach($cols as $col => $idx){
				static::index_col(get_class($obj), $obj->id, $col, $obj->$col, isset($infile[$col]), $clean);
			}
		}
		else{
			throw new Exception("Object ".get_class($obj)." is not searchable", 1);
		}
	}

	public static function rebuild(){//repart de 0
		static::clean_all();
		static::$keywords = [];//no need to do a sql query
		$classes = Indexed::forge()->select(['classname'])->get_arrays();
		if(!empty($classes)){
			foreach($classes as $c){
				$all = $c['classname']::all();
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

	public static function index_pendings(){
		static::init_keywords();
		$pendings = PendingIndexCol::forge()->get_arrays();
		if(!empty($pendings)){
			$cobayes = [];
			foreach($pendings as $p){
				if( ! isset($cobayes[$p['indexable_type']])){
					$cobayes[$p['indexable_type']] = new $p['indexable_type'];
				}

				list($cols, $infile) = $cobayes[$p['indexable_type']]->get_indexed_cols();

				static::index_col($p['indexable_type'], $p['indexable_id'], $p['col'], $p['value'], $p['infile'], true);//true : we should clean the index before re-indexing the col
			}
		}
		$db = DB::getDB();
		$db->query('TRUNCATE '.PendingIndexCol::getTableName());
		static::clean_trailing_keywords();
	}

	private static function index_col($classname, $id, $col, $text = null, $infile = false, $clean = false){
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
				foreach($parsing as $p){
					if(!empty($p['words'])){
						$context = Context::build(['context' => $p['line']])->save();
						foreach($p['words'] as $w){
							if(isset(static::$keywords[$w])){
								$kw = Keyword::build(static::$keywords[$w]);//no save
							}
							else{
								$kw = Keyword::create($w, static::getStemmer()->stem($w));
								static::$keywords[$w] = $kw->as_array();
							}
							$kw->store($context, $classname, $id, $col);
							$kw = null;
							unset($kw);
						}
					}
				}
			}
		}
	}

	private static function init_keywords(){
		static::$keywords = Keyword::forge()->get_arrays('word');
	}

	private static function clean_col_index($classname, $id, $col){
		$db = DB::getDB();
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

		$db->query('DELETE FROM '.Index::getTableName().'
								WHERE indexable_type = ?
								AND indexable_id = ?
								AND col = ?', [$classname, $id, $col]);

		if(!empty($todelete)){
			$params = [];
			$db->query('DELETE FROM '.Context::getTableName().' WHERE id IN ('.DB::getPDOParamsFor($todelete, $params).')', $params);
		};
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

}
