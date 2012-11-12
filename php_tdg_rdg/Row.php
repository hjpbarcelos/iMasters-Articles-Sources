<?php
class Row implements IteratorAggregate, ArrayAccess {
	/**
	 * Armazena o objeto Table ao qual este objeto pertence.
	 * @var Table
	 */
	private $table;


	/**
	 * Armazena os dados do objeto.
	 * @var array
	 */
	private $data = array();


	/**
	 * Armazena os dados "limpos" do objeto, ou seja,
	 * os provenientes de uma operação SELECT no banco de dados.
	 * Ao utilizar set or unset, apenas a propriedade $data é alterada,
	 * $cleanData mantém-se da mesma forma até a execução de um refresh.
	 * @var array
	 */
	private $cleanData = array();

	/**
	 * Array de flags indicando de o campo foi alterado.
	 * Útil para fazer updates sob demanda, economizando
	 * um pouco no acesso ao banco de dados.
	 * @var array
	 */
	private $dirtyData = array();

	/**
	 * Indica se o objeto Row é somente leitura
	 * @var bool
	 */
	private $readOnly = false;

	/**
	 * Construtor.
	 * Aqui define-se a tabela à qual o objeto Row se refere.
	 * Além disso, definimos também os dados existentes no mesmo.
	 * Os únicos índices permitidos para os dados do objeto são
	 * os que são informados no construtor.
	 *
	 * @param Table $table : o objeto Table ao qual este objeto Row pertence.
	 * @param array $info : informações sobre a criação do objeto Row.
	 *
	 * Parâmetros válidos para $info:
	 * - data		=>	(array) os dados das colunas neste objeto Row
	 * - stored		=>	(boolean) se os dados são provindos do banco de dados ou não
	 * - readOnly	=>	(boolean) se é permitido ou não alterar os dados desta linha
	 */
	public function __construct(Table $table, array $info){
		$this->setTable($table);
		$this->setUp($info);
	}

	/**
	 * Checa as informações passadas ao construtor.
	 * É obrigatório informar ao menos os dados do objeto Row.
	 *
	 * @return void
	 * @throws Exception : caso não exista o índice 'data' em $info
	 */
	private function checkInfo(array $info){
		if(!array_key_exists('data', $info)) {
			throw new Exception('Nenhum dado para ser armazenado no objeto Row.');
		}
	}

	/**
	 * Configura o objeto Row.
	 *
	 * @param array $info : as informações sobre o objeto
	 * @return void
	 */
	private function setUp(array $info) {
		$this->checkInfo($info);
		$this->data = (array) $info['data'];

		foreach($info as $k => $val) {
			switch($k) {
				case 'readOnly':
					$this->readOnly = (bool) $val;
					break;
				case 'stored':
					if($val === true) {
						$this->cleanData = $this->data;
					} else {
						$this->dirtyData = array_combine(array_keys($this->data), array_fill(0, count($this->data), true));
					}
					break;
				default:
					// sem ação...
			}
		}
	}

	/**
	 * Seta uma tabela para o objeto Row.
	 *
	 * @param Table $table
	 * @return Row : fluent interface
	 */
	public function setTable(Table $table) {
		$this->table = $table;
		// Fluent interface
		return $this;
	}

	/**
	 * Retorna o objeto Table deste objeto.
	 *
	 * @return Table
	 */
	public function getTable(){
		return $this->table;
	}

	/**
	 * Retorna os dados deste objeto Row.
	 *
	 * @return array
	 */
	public function getData() {
		return $this->data;
	}

	/**
	 * Retorna se o objeto Row é somenta para leitura
	 *
	 * @return bool
	 */
	public function isReadOnly() {
		return $this->readOnly;
	}

	/**
	 * Converte um objeto Row em um array, retornando apenas seus campos.
	 *
	 * @return array
	 */
	public function toArray() {
		return $this->getData();
	}

	/**
	 * Retorna a chave primária do objeto Row.
	 *
	 * @return mixed
	 */
	public function getPk($useDirty = true) {
		$pkCol = $this->table->getPrimaryKey();
		$data = $useDirty === true ? $this->data : $this->cleanData;
		if(is_array($pkCol)) {
			$pkArrayKeys = array_combine($pkCol, array_fill(0, count($pkCol), null));
			return array_intersect_key($data, $pkArrayKeys);
		} else {
			return array_key_exists($pkCol, $data) ? $data[$pkCol] : null;
		}
	}

	/**
	 * Salva os dados do objeto atual no banco de dados (insere ou atualiza).
	 *
	 * @return int : o número de linhas afetadas.
	 */
	public function save() {
		if($this->isReadOnly()) {
			throw new Exception('Este objeto Row é somente-leitura.');
		}

		if(empty($this->cleanData)) {
			return $this->doInsert();
		} else {
			return $this->doUpdate();
		}
	}

	/**
	 * Faz a inserção no banco de dados dos dados no objeto Row.
	 *
	 * @return int : o número de linhas inseridas
	 */
	private function doInsert() {
		$data = array_intersect_key($this->data, $this->dirtyData);
		$result = $this->getTable()->insert($data);

		$pkId = $this->getTable()->getIdentity();
		if($pkId !== null && (!array_key_exists($pkId, $data) || $data[$pkId] === null)) {
			$this->set($pkId, $this->getTable()->getDriver()->lastInsertId());
		}

		$this->refresh();
		return $result;
	}

	/**
	 * Faz a atualização no banco de dados dos dados no objeto Row.
	 *
	 * @return int : o número de linhas atualizadas
	 */
	private function doUpdate() {
		$diffData = array_intersect_key($this->data, $this->dirtyData);
		if(!empty($diffData)) {
			$pk = $this->getPk();
			if($pk === null) {
				throw new Exception('Impossível atualizar uma linha sem chave primária!');
			}

			$pkCol = (array) $this->getTable()->getPrimaryKey();

			$cond = array();
			foreach($pkCol as $col) {
				$cond[] = $col . ' = ?';
			}

			$result = $this->getTable()->update($diffData, join(', ', $cond), (array) $pk);

			$this->refresh();
			return $result;
		}
		return 0;
	}

	/**
	 * Atualiza os dados da linha após uma operação no banco de dados.
	 *
	 * @return void
	 */
	private function refresh() {
		$pk = $this->getPk(true);
		if($pk !== null) {
			$row = $this->getTable()->getById($pk);
			if($row === null) {
				throw new Exception('Não foi possível atualizar a linha da tabela.
										Erro ao buscá-la no banco de dados');
			}

			$this->data = $row->toArray();
			$this->cleanData = $this->data;
			$this->dirtyData = array();
		}
	}

	/**
     * Remove os dados correspondente ao objeto Row do banco de dados.
	 *
	 * @return int: o número de linhas afetadas.
	 */
	public function delete() {
		if($this->readOnly) {
			throw new Exception('Impossível remover uma linha somente-leitura!');
		}

		$pk = $this->getPk(false);
		if($pk === null) {
			throw new Exception('Impossível remover uma linha sem chave primária!');
		}

		$pkCol = (array) $this->getTable()->getPrimaryKey();

		$cond = array();
		foreach($pkCol as $col) {
			$cond[] = $col . ' = ?';
		}

		$result = $this->getTable()->delete(join(', ', $cond), array($pk));

		$this->data = array_combine(
			array_keys($this->data),
			array_fill(0, count($this->data), null)
		);

		return $result;
	}

	/**
	 * Seta os dados do objeto a partir de um array.
	 *
	 * @param array $data
	 * @return Row : fluent interface
	 */
	public function setFromArray(array $data) {
		foreach($data as $key => $val) {
			$this->set($key, $val);
		}

		return $this;
	}

	/**
	 * Seta um valor para uma coluna do banco de dados.
	 *
	 * @param string $column : o nome da coluna a ser alterada
	 * @param mixed $value: o novo valor da coluna
	 * @return Row : fluent interface
	 * @throws Exception : caso a coluna não exista
	 */
	public function set($column, $value) {
		// Irá lançar uma exceção caso a coluna não exista...
		$this->verifyColumn($column);
		if($value !== $this->data[$column]) {
			$this->data[$column] = $value;
			$this->dirtyData[$column] = true;
		}
		// Fluent interface, permite o encadeamento de métodos: $row->set('bla', 'foo')->set('baz', 'bazzinga');
		return $this;
	}

	/**
	 * Retorna o valor de uma coluna.
	 *
	 * @param string $column : o nome da coluna
	 * @return mixed : o valor da coluna
	 * @throws Exception : caso a coluna não exista
	 */
	public function get($column) {
		// Irá lançar uma exceção caso a coluna não exista...
		$this->verifyColumn($column);
		return $this->data[$column];
	}

	/**
	 * Verifica se a coluna existe no objeto.
	 *
	 * @param sting $column : o nome da coluna
	 * @return boolean
	 * @throws Exception : caso a coluna não exista
	 */
	public function has($column) {
		return array_key_exists($column, $this->data);
	}

	/**
	 * Verifica se a coluna existe, lançando uma exceção caso não exista.
	 *
	 * @param $column : o nome da coluna
	 * @throws Exception : caso a coluna não exista
	 */
	private function verifyColumn($column) {
		if(!$this->has($column)) {
			throw new Exception(sprintf('A coluna "%s" não existe neste objeto Row!', $column));
		}
	}

	/**
	 * @see ArrayAccess::offsetSet()
	 */
	public function offsetSet($offset, $value) {
		$this->set($column, $value);
	}

	/**
	 * @see ArrayAccess::offsetGet()
	 */
	public function offsetGet($offset) {
		return $this->get($column);
	}

	/**
	 * @see ArrayAccess::offsetExists()
	 */
	public function offsetExists($offset) {
		return $this->has($column);
	}

	/**
	 * @see ArrayAccess::offsetUnset()
	 */
	public function offsetUnset($offset) {
		$this->verifyColumn($column);
		$this->set(offset, null);
	}

	/**
	 * @see IteratorAggregate::getIterator()
	 */
	public function getIterator() {
		return new ArrayIterator($this->data);
	}
}
