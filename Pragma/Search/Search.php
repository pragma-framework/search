<?php
namespace Pragma\Search;

class Search{
	const START_PRECISION = 1;
	const END_PRECISION = 2;
	const LARGE_PRECISION = 3;
	const EXACT_PRECISION = 4;

	const RANKED_RESULTS = 1;
	const OBJECTS_RESULTS = 2;
	const FULL_RESULTS = 3;

	/*
	* $query : the search query
	* $results_type : allow user to choose which results he wants
	* $extends_to_lemmes : allow fallback to lemmes root
	* $with_context : if true, embeds the context in the results
	* $precision : determines the keyword search whether should be a LIKE 'word%', '%word' OR '%word%' OR 'word'
	* $types : filter on indexable_type. given as an array
	* $cols : filter on col. given as an array
	* $threshold : minimum ratio of the number of words found inside the expression of the user
	* $min_word_length : allow the developper to handle only words with a length greater (>=) than the one he choose
	* $min_word_length : allow to limit the number of distinct indexes treated with the sql query.
	*										 It does not ensure that the final result will be exactly the same as the limit
	* $queryFilters : is callback function taking for argument a queryBuilder and returning this queryBuilder.
										It allows to directly filter the result by modifying the query on the index
	*/
	public static function process($query,
																 $results_type = self::RANKED_RESULTS,
																 $extend_to_lemmes = false,
																 $with_context = false,
																 $precision = self::START_PRECISION,
																 $types = null,
																 $cols = null,
																 $threshold = 2/3,
																 $min_word_length = null,
																 $max_distinct_results = null,
																 $queryFilters = null
																){

		$with_context = $with_context && (!defined(PRAGMA_SEARCH_SKIP_CONTEXT) || ! PRAGMA_SEARCH_SKIP_CONTEXT);

		$query = trim($query);
		if(empty($query)){
			return [];
		}

		$words = Processor::parse($query, $min_word_length);
		if($extend_to_lemmes && $precision != self::EXACT_PRECISION){
			$lemmes = [];
			foreach($words as $w){
				$lemme = Processor::getStemmer()->stem($w);
				$lemmes[$lemme] = $lemme;
			}
			$words = $lemmes;
		}

		$keywords = [];
		$kw_origins = [];
		foreach($words as $w){
			$against = "";
			switch($precision){
				default:
				case static::START_PRECISION:
					$against = "$w%";
					break;
				case static::END_PRECISION:
					$against = "%$w";
					break;
				case static::LARGE_PRECISION:
					$against = "%$w%";
					break;
				case static::EXACT_PRECISION:
					$against = $w;
					break;
			}

			$kws = Keyword::forge()
									->select(['id', 'word'])
									->where($extend_to_lemmes ? 'lemme' : 'word', 'LIKE', $against)
									->get_arrays('id');

			foreach($kws as $id => $k) {
				$kw_origins[$id] = $w;
			}

			$keywords += $kws;
		}

		$objects = $ranked = $contexts = [];

		$truncated = false;

		if( ! empty($keywords) ){
			$details = ['keyword_id', 'indexable_type', 'indexable_id', 'col'];
			if($with_context){
				$details[] = 'context_id';
				$context_ids = [];
			}
			$query = Index::forge()
										->select($details)
										->where('keyword_id', 'in', array_keys($keywords));

			if( ! is_null($types) ){
				if( ! is_array($types) ){
					$types = [$types];
				}

				$query->where('indexable_type', 'in', $types);
			}

			if( ! is_null($cols) ){
				if( ! is_array($cols) ){
					$cols = [$cols];
				}

				$query->where('col', 'in', $cols);
			}

			if( ! is_null($queryFilters) && is_callable($queryFilters)) {
				$query = call_user_func($queryFilters, $query);
			}

			$indexes = $query->get_arrays();

			if( ! empty($indexes) ){
				$counts = [];
				foreach($indexes as $data){
					if(!isset($counts[$data['indexable_type']][$data['indexable_id']][$data['keyword_id']])){
						$counts[$data['indexable_type']][$data['indexable_id']][$kw_origins[$data['keyword_id']]] = 1;
					}

					if( $with_context ){
						$context_ids[$data['context_id']] = $data['context_id'];
					}

					if($results_type == self::RANKED_RESULTS || $results_type == self::FULL_RESULTS){
						if( ! isset($ranked[$data['indexable_type'] . '-' . $data['indexable_id']]) ){
							$ranked[$data['indexable_type'] . '-' . $data['indexable_id']] = [
								'obj' => [
									'type' => $data['indexable_type'],
									'id' => $data['indexable_id'],
									'keyword' => $keywords[$data['keyword_id']]
								],
								'count' => 0,
							];

							if($with_context){
								$ranked[$data['indexable_type'] . '-' . $data['indexable_id']]['obj']['contexts'] = [];
							}
						}

						if($with_context){
							$ranked[$data['indexable_type'] . '-' . $data['indexable_id']]['obj']['contexts'][$data['context_id']] = $data['context_id'];
						}
						$ranked[$data['indexable_type'] . '-' . $data['indexable_id']]['count']++;
					}

					if($results_type == self::OBJECTS_RESULTS || $results_type == self::FULL_RESULTS){
						if( ! isset($objects[$data['indexable_type']][$data['indexable_id']]) ){
							$objects[$data['indexable_type']][$data['indexable_id']] = [];
						}
						$from = ['col' => $data['col'], 'keyword' => $keywords[$data['keyword_id']]];
						if($with_context){
							$from['context_id'] = $data['context_id'];
						}

						$objects[$data['indexable_type']][$data['indexable_id']][] = $from;
					}
				}
				unset($kw_origins);

				/*
				We only return the results that are affected by the $threshold (2/3 by default) of the keywords
				 */
				$matchingWords = count($words)*$threshold;
				foreach($counts as $it => $itv){
					foreach($itv as $ii => $c){
						if(count($c) < $matchingWords){
							if($results_type == self::RANKED_RESULTS || $results_type == self::FULL_RESULTS){
								unset($ranked[$it."-".$ii]);
							}
							if($results_type == self::OBJECTS_RESULTS || $results_type == self::FULL_RESULTS){
								unset($objects[$it][$ii]);
							}
						}
					}
				}
				unset($counts);

				if($results_type == self::RANKED_RESULTS || $results_type == self::FULL_RESULTS){
					$ranked = array_values($ranked);
					usort($ranked, function($a, $b){
						return $a['count'] < $b['count'];
					});

					$tmp = [];
					array_walk($ranked, function($elem) use(&$tmp){
						$tmp[] = $elem['obj'];
					});

					$ranked = $tmp;
				}

				if(!is_null($max_distinct_results)) {
					switch($results_type) {
						case self::RANKED_RESULTS:
							$nbResults = count($ranked);
							array_splice($ranked, $max_distinct_results);
							$truncated = $nbResults > count($ranked);
							break;
						case self::OBJECTS_RESULTS:
						case self::FULL_RESULTS:
							$nbRankedResults = count($ranked);
							array_splice($ranked, $max_distinct_results);
							$truncated = $nbRankedResults > count($ranked);

							foreach($objects as $it => $itv) {
								$nbResults = count($objects[$it]);
								array_splice($objects[$it], $max_distinct_results);
								$truncated = $truncated || $nbResults > count($objects[$it]);
							}
							break;
					}
				}
			}
		}

		$results = ["truncated" => $truncated];

		switch($results_type){
			default:
			case self::RANKED_RESULTS:
				$results['ranked'] = $ranked;
				break;
			case self::OBJECTS_RESULTS:
				$results['objects'] = $objects;
				break;
			case self::FULL_RESULTS:
				$results['ranked'] = $ranked;
				$results['objects'] = $objects;
				break;
		}

		if($with_context){
			$results['contexts'] = empty($context_ids) ? [] : Context::forge()
																															 ->select(['id', 'context'])
																															 ->where('id', 'in', $context_ids)
																															 ->get_arrays('id');
		}

		return $results;
	}
}
