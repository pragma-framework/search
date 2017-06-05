<?php
namespace Pragma\Search;

trait Searchable{
	protected $indexed_cols = [];
	protected $infile_cols = [];

	protected function index_cols(){
		$this->indexed_cols = array_flip(func_get_args());
		$this->pushHook('after_save', 'index_prepare');
		$this->pushHook('after_open', 'index_init_values');

		return $this;
	}

	//pas vraiment de raison d'appeler cette méthode sans appeler avant index_cols
	//du coup peut-être prévoir un warning si ça n'a pas été fait avant
	protected function infile_cols(){
		$this->infile_cols = array_flip(func_get_args());
		return $this;
	}

	protected function index_init_values($force = false){
		if( ! $this->initialized || $force){
			$this->initial_values = $this->fields;
			$this->initialized = true;
		}
	}

	protected function index_prepare($last = false){
		if( empty($this->indexed_cols) ){
			$this->index_init_values($last);
			return false;
		}

		if( $this->is_new() ){
			foreach($this->indexed_cols as $col){
				PendingIndexCol::store($this, $col, isset($this->infile_cols[$col]));
			}
		}
		else{
			foreach($this->initial_values as $col => $value){
				if(	isset($this->indexed_cols[$col]) &&
						array_key_exists($col, $this->initial_values) &&
						$value != $this->$col
					){
					PendingIndexCol::store($this, $col, isset($this->infile_cols[$col]));
				}
			}
		}

		$this->index_init_values($last);
		return true;
	}

	public function get_indexed_cols(){
		return [$this->indexed_cols, $this->infile_cols];
	}
}
