<?php
namespace Pragma\Search;

use Pragma\Search\Processor;

trait Searchable{
	protected $indexed_cols = [];
	protected $infile_cols = [];
	protected $index_was_new = true;
	protected $immediatly_indexed = false;
	private 	$hooksHaveBeenIntialized = false;

	protected function index_cols(...$cols){
		$this->indexed_cols = array_flip($cols);
		if(!$this->hooksHaveBeenIntialized) {
			$this->pushHook('before_save', 'init_index_was_new', 'pragma:search:init_index_was_new');
			$this->pushHook('after_save', 'handle_index', 'pragma:search:handle_index');
			$this->pushHook('after_open', 'index_init_values', 'pragma:search:index_init_values_after_open');
			$this->pushHook('after_build', 'index_init_values', 'pragma:search:index_init_values_after_build');
			$this->pushHook('before_delete', 'index_delete', 'pragma:search:index_delete');
			$this->hooksHaveBeenIntialized = true;
		}

		return $this;
	}

	//pas vraiment de raison d'appeler cette méthode sans appeler avant index_cols
	//du coup peut-être prévoir un warning si ça n'a pas été fait avant
	protected function infile_cols(){
		$this->infile_cols = array_flip(func_get_args());
		return $this;
	}

	public function set_immediatly_indexed($val = true){
		$this->immediatly_indexed = $val;
		return $this;
	}

	protected function index_init_values(){
		$this->enableChangesDetection(true);
	}

	protected function init_index_was_new(){
		$this->index_was_new = $this->is_new();
	}

	protected function handle_index($last = false){
		if( empty($this->indexed_cols) ){
			return false;
		}

		$this->index_prepare($last);

		return true;
	}

	protected function index_prepare($last = false){
		$needImmediateIndex = false;
		if( $this->index_was_new ){
			foreach($this->indexed_cols as $col => $value){
				if(!empty($this->$col)){
					if( ! $this->immediatly_indexed) {
						PendingIndexCol::store($this, $col, isset($this->infile_cols[$col]));
					}
					else {
						$needImmediateIndex = true;
					}
				}
			}
		}
		else{
			foreach($this->initial_values as $col => $value){
				if(isset($this->indexed_cols[$col]) &&
						array_key_exists($col, $this->initial_values) &&
						$value != $this->$col
					){
					if(!empty($this->$col)){
						if( ! $this->immediatly_indexed) {
							PendingIndexCol::store($this, $col, isset($this->infile_cols[$col]));
						}
						else {
							$needImmediateIndex = true;
						}
					}
					else {
						if( ! $this->immediatly_indexed) {
							PendingIndexCol::store($this, $col, isset($this->infile_cols[$col]), true);
						}
						else {
							Processor::clean_col_index(get_class($this), $this->id, $col);
						}
					}
				}
			}
		}

		if($needImmediateIndex) {
			Processor::index_object(get_class($this), $this->as_array(), true, true);//immediate indexation of the object only if the object changed
		}
		return true;
	}

	public function get_indexed_cols(){
		return [$this->indexed_cols, $this->infile_cols];
	}

	protected function index_delete(){
		\Pragma\DB\DB::getDB()->query('DELETE FROM '.PendingIndexCol::getTableName().'
			WHERE indexable_type = ? AND indexable_id = ?', [get_class($this), $this->id]);

		PendingIndexCol::build([
			'indexable_type' 	=> get_class($this),
			'indexable_id' 		=> $this->id,
			'col' 				=> 'id',
			'value'				=> $this->id,
			'deleted' 			=> true,
		])->save();

		return true;
	}
}
