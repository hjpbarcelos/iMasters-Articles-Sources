<?php
class Driver {
	const INSERT = 0;
	const UPDATE = 1;
	const DELETE = 2;
	const SELECT = 3;

	const FETCH_ASSOC 	= 10;
	const FETCH_NUM		= 20;
	const FETCH_ARRAY 	= 30;
	const FETCH_OBJ		= 40;

	/**
	 * Armazena o próximo statement a ser executado
	 * @var MySQLi_stmt
	 */
	private $stmt;

	/**
	 * Armazena o objeto de conexão com o banco de dados.
	 * @var MySQLi
	 */
	private $connection;

	/**
	 * Armazena a operação atualmente sendo executada.
	 * @var int : veja as constantes de classe
	 */
	private $currOp;

	/**
	 * O modo de fetch dos dados no banco.
	 * @var int : veja as constantes de classe
	 */
	private $fetchMode = self::FETCH_ASSOC;

	/**
	 * Armazena os nomes das colunas de tabelas
	 * @var array
	 */
	private $keys = array();

	/**
	 * Armazena os valores das colunas de tabelas
	 * @var array
	 */
	private $values = array();

	/**
	 * Construtor.
	 *
	 * @param MySQLi $conn : um objeto MySQLi para a conexão com o banco
	 */
	public function __construct(MySQLi $conn) {
		$this->connect($conn);
	}

	/**
	 * Seta o modo de fetch para o objeto.
	 *
	 * @param int $mode : veja as constantes de classe
	 * @return Driver : fluent interface
	 */
	public function setFetchMode($mode) {
		$this->fetchMode = $mode;
		return $this;
	}

	/**
	 * Conecta-se ao banco de dados através de um objeto MySQLi.
	 *
	 * @param MySQLi $conn : o objeto para conexão com o banco
	 * @return Driver : fluent interface
	 */
	public function connect(MySQLi $conn = null) {
		if(!$this->isConnected() && $conn === null) {
			throw new Exception('Conexão com o banco de dados fechada!
					Precisamos de um conector.');
		}

		if(!$this->isConnected()) {
			$this->connection = $conn;
		}

		return $this;
	}

	/**
	 * Retorna o objeto de conexão com o banco de dados.
	 *
	 * @return MySQLi
	 */
	public function getConnection() {
		$this->connect();
		return $this->connection;
	}

	/**
	 * Inicia uma transação, deligando o autocommit.
	 *
	 * @return Driver : fluent interface
	 */
	public function begin() {
		$this->connection->autocommit(false);
		return $this;
	}

	/**
	 * Cancela a transação em andamento.
	 *
	 * @return Driver : fluent interface
	 */
	public function rollback() {
		$this->connection->rollback();
		return $this;
	}

	/**
	 * Salva a transação em andamento.
	 *
	 * @return Driver : fluent interface
	 */
	public function commit() {
		$this->connection->commit();
		return $this;
	}

	/**
	 * Verifica se o Driver está conectado ao banco.
	 *
	 * @return bool
	 */
	public function isConnected() {
		return $this->connection instanceof MySQLi;
	}

	/**
	 * Fecha a conexão com o banco de dados.
	 *
	 * @return void
	 */
	public function close() {
		if($this->isConnected()) {
			$this->connection->close();
			unset($this->connection);
			$this->connection = null;
		}
	}

	/**
	 * Prepara um statement.
	 *
	 * @param string $sql : um statement SQL válido
	 * @return MySQLi_stmt
	 */
	private function prepare($sql) {
		$stmt = $this->getConnection()->prepare($sql);
		if($stmt === false) {
			throw new Exception($this->connection->error);
		}
		return $stmt;
	}

	/**
	 * Insere dados em uma tabela.
	 *
	 * @param string $tableName: o nome da tabela
	 * @param array $data : os dados para inserir
	 * @return int : o número de linhas inseridas com sucesso
	 */
	public function insert($tableName, array $data) {
		$this->currOp = self::INSERT;
		$cols = array_keys($data);

		$sql = 'INSERT INTO ' . $tableName
			 . '(' . join(', ', $cols ) .  ')'
			 . ' VALUES (' . join(', ', array_fill(0, count($data), '?')) . ')';

		$this->stmt = $this->prepare($sql);
		$this->bindParams($data);
		$this->execute();
		return $this->stmt->affected_rows;
	}

	/**
	 * Atualiza dados em uma tabela, com base numa condicional.
	 *
	 * @param string $tableName: o nome da tabela
	 * @param array $data : os dados para inserir
	 * @param string $cond : uma condicional colocada em uma cláusula WHERE
	 * @param array $condParams : parâmetros da condição, caso a mesma
	 * 		faça parte de um prepared statement
	 * @return int : o número de linhas atualizadas
	 */
	public function update($tableName, array $data, $cond, array $condParams = array()) {
		$this->currOp = self::UPDATE;
		$cols = array_keys($data);

		$set = array();
		foreach($data as $key => $value) {
			$set[] = $key . ' = ?';
		}

		$sql = 'UPDATE ' . $tableName
			 . ' SET ' . join(', ', $set)
			 . ' WHERE ' . $cond;

		$params = array_merge($data, $condParams);
		$this->stmt = $this->prepare($sql);
		$this->bindParams($params);
		$this->execute();
		return $this->stmt->affected_rows;
	}

	/**
	 * Deleta dados de uma tabela, com base numa condicional.
	 *
	 * @param string $tableName: o nome da tabela
	 * @param string $cond : uma condicional colocada em uma cláusula WHERE
	 * @param array $condParams : parâmetros da condição, caso a mesma
	 * 		faça parte de um prepared statement
	 * @return int : o número de linhas deletadas
	 */
	public function delete($tableName, $cond, array $condParams = array()) {
		$this->currOp = self::DELETE;

		$sql = 'DELETE FROM ' . $tableName
			 . ' WHERE ' . $cond;

		$this->stmt = $this->prepare($sql);
		$this->bindParams($condParams);
		$this->execute();
		return $this->stmt->affected_rows;
	}

	/**
	 * Realiza uma seleção de dados na tabela, guiada por alguns parâmetros.
	 *
	 * @param string $tableName: o nome da tabela
	 * @param string $order : como a busca deve ser ordenada, na forma
	 *		{<col_name> ASC | DESC}, {<col_name> ASC | DESC}, ...
	 * @param int $count : o número de linhas para selecionar
	 * @param int $offset : a linha a partir do qual começar a busca (0-based)
	 * @param array $fields : os campos da tabela a selecionar.
	 * 		Caso este parâmetro não estaja setado, utilizamos o SQL wildcard '*'
	 * @return boolean
	 */
	public function select($tableName, $order = null, $count = null, $offset = null, $fields = array()) {
		$this->currOp = self::SELECT;

		$fields = (array) $fields;
		$selFields = array();
		if(empty($fields)) {
			$selFields[] = '*';
		} else {
			foreach($fields as $alias => $colName) {
				if(is_string($alias)) {
					$selFields[] = $colName . ' AS ' . $alias;
				} else {
					$selFields[] = $colName;
				}
			}
		}

		$sql = 'SELECT ' . join(', ', $selFields)
			 . ' FROM ' . $tableName;

		if($order !== null) {
			$sql .= ' ORDER BY ' . $order;
		}

		if(($count === null || $count === 0) && $offset !== null) {
			$count = PHP_INT_MAX;
		}

		if((int) $count > 0) {
			if($offset === null) {
				$offset = 0;
			}

			$sql .= ' LITMIT ' . $count . ' OFFSET ' . $offset;
		}

		$this->stmt = $this->prepare($sql);
		return $this->execute();
	}

	/**
	 * Executa uma query.
	 *
	 * @param string $sqlStmt : uma sentença SQL
	 * @param array $params : os parâmetros caso $sqlStmt seja um prepared statement
	 * @return bool
	 */
	public function query($sqlStmt, array $params = array()) {
		if(!is_string($sqlStmt)) {
			throw new Exception('O parametro #1 do método Driver::query() deve ser uma string');
		}

		if(preg_match('/^select|describe/i', $sqlStmt)) {
			$this->currOp = self::SELECT;
		} else if(preg_match('/^insert/i', $sqlStmt)) {
			$this->currOp = self::INSERT;
		} else if(preg_match('/^update/i', $sqlStmt)) {
			$this->currOp = self::UPDATE;
		} else {
			$this->currOp = self::DELETE;
		}

		$this->stmt = $this->prepare($sqlStmt);

		if($this->stmt === false) {
			throw new Exception($this->connection->error);
		}
		return $this->execute($params);
	}

	/**
	 * Retorna todas as linhas buscadas a partir de um SELECT statement.
	 *
	 * @return array
	 */
	public function fetchAll() {
		$data = array();
		while($row = $this->doFetch()) {
			$data[] = $row;
		}
		return $data;
	}

	/**
	 * Retorna a primeira linha buscada a partir de um SELECT statement.
	 *
	 * @return Row|null
	 */
	public function fetchOne() {
		return $this->doFetch();
	}

	/**
	 * Faz de fato a busca dos dados no banco.
	 *
	 * @return Row|null
	 */
	private function doFetch() {
		if($this->currOp !== self::SELECT) {
			throw new Exception('Fetch só pode ser executado com operações do tipo SELECT');
		}

		// Faz o fetch no banco de dados...
		$ret = $this->stmt->fetch();
		// Caso tenhamos chegado ao fim...
		if(!$ret) {
			// O statement agora passa a apontar para o início da tabela...
			$this->stmt->reset();
			return null;
		}

		// Faz uma cópia dos valores, pois eles são referências
		// Caso não façamos isso, ao fazer um novo fetch, os dados são sobrescritos
		$values = array();
		foreach($this->values as $val) {
			$values[] = $val;
		}

		$row = null;
		switch($this->fetchMode) {
			case self::FETCH_NUM:
				return $values;
			case self::FETCH_ASSOC:
				return array_combine($this->keys, $values);
			case self::FETCH_ARRAY:
				$assoc = array_combne($this->keys, $values);
				return array_merge($this->keys, $values);
			case self::FETCH_OBJ:
				return (object) array_combine($this->keys, $values);
			default:
				throw new Exception('Modo de fetch inválido');
		}
	}

	/**
	 * Executa o statement atual
	 *
	 * @param array $params : parâmetros de statement para binding
	 * @return bool
	 */
	private function execute(array $params = array()) {
		if($this->stmt === null) {
			return null;
		}
		$this->bindParams($params);
		$ret = $this->stmt->execute();
		if($ret === false) {
			throw new Exception('Error # ' . $this->stmt->errno .
				': ' . $this->stmt->error);
		}

		// Obtenção dos metadados do select, tais como as colunas retornadas
		$metaData = $this->stmt->result_metadata();
		if($this->stmt->errno) {
			throw new Exception('Erro na obtenção dos metadados:' . $this->stmt->error);
		}
		// Metadados só são retornados em operações de SELECT
		if($metaData !== false) {
			// Definindo as chaves do array (nome dos campos da tabela)
			$this->keys = array();
			foreach($metaData->fetch_fields() as $col) {
				$this->keys[] = $col->name;
			}

			// Criamos um array do tamanho do array $keys, com valores NULL
			$aux = array_fill(0, count($this->keys), null);
			$refs = array();

			// MySQLi_stmt::bind_result precisa de referências para funcionar
			foreach($aux as $i => &$f) {
				// Dessa forma $refs passa a referenciar $aux
				$refs[$i] = &$f;
			}

			$this->stmt->store_result();

			// Associa os resultados ao array $values
			call_user_func_array(
				array(
					$this->stmt, 'bind_result'
				),
				$refs

			);

			$this->values = $aux;
		}
		return $ret;
	}

	/**
	 * Faz o binding de parâmetros para o statement atual.
	 *
	 * @param array $params : os parâmetros de binding
	 * @return bool
	 */
	private function bindParams(array $params) {
		if(empty($params)) {
			return;
		}

		// Precisamos indicar o tipod os parâmetros de binding...
		$bindStr = '';
		foreach($params as $col => $value) {
			// São 3 possíveis...
			if(is_int($value)) {
				$bindStr .= 'i';
			} elseif(is_float($value)){
				$bindStr .= 'd';
			} else {
				$bindStr .= 's';
			}
		}

		// Adiciona a string para indicar os formatos dos dados
		array_unshift($params, $bindStr);

		// Para bind_param funcionar, ele precisa de referências.
		// A única maneira de associar referências de arrays em PHP é a abaixo
		$stmtParams = array();
		foreach($params as $key => &$value) {
			$stmtParams[$key] = &$value;
		}

		// Chamamos a função MySQLi_stmt::bind_param com os argumentos apropriados
		$ret = call_user_func_array(
			array($this->stmt, 'bind_param'),
			$params
		);

		if($ret === false) {
			throw new Exception('Erro ao executar bind_param no statement: ', $this->stmt->error);
		}
	}

	/**
	 * Retorna o último ID auto_increment inserido no banco, na sessão atual.
	 *
	 * @return int
	 */
 	public function lastInsertId() {
		return $this->connection->insert_id;
	}

	/**
	 * Retorna informações sobre uma tabela.
	 * Com este método é possível mapear automaticamente uma tabela,
	 * sem a necessidade de setar campos manualmente.
	 *
	 * @return array
	 */
	public function describeTable($tableName) {
		$sql = 	'DESCRIBE `' . $tableName . '`';

		$this->query($sql);
		$results = $this->fetchAll();

		$desc = array();

		$defaults = array(
			'Lenght' 			=> null,
			'Scale' 			=> null,
			'Precision' 		=> null,
			'Unsigned' 			=> null,
			'Primary' 			=> false,
			'PrimaryPosition'	=> null,
			'Identity'			=> false
		);

		$i = 1;
		$p = 1;
		foreach($results as $key => $row) {
			$row = array_merge($defaults, $row);
			if (preg_match('/unsigned/', $row['Type'])) {
				$row['Unsigned'] = true;
			}
			if (preg_match('/^((?:var)?char)\((\d+)\)/', $row['Type'], $matches)) {
				$row['Type'] = $matches[1];
				$row['Length'] = $matches[2];
			} else if (preg_match('/^decimal\((\d+),(\d+)\)/', $row['Type'], $matches)) {
				$row['Type'] = 'decimal';
				$row['Precision'] = $matches[1];
				$row['Scale'] = $matches[2];
			} else if (preg_match('/^float\((\d+),(\d+)\)/', $row['Type'], $matches)) {
				$row['Type'] = 'float';
				$row['Precision'] = $matches[1];
				$row['Scale'] = $matches[2];
			} else if (preg_match('/^((?:big|medium|small|tiny)?int)\((\d+)\)/', $row['Type'], $matches)) {
				$row['Type'] = $matches[1];
			}

			if (strtoupper($row['Key']) == 'PRI') {
				$row['Primary'] = true;
				$row['PrimaryPosition'] = $p;
				if ($row['Extra'] == 'auto_increment') {
					$row['Identity'] = true;
				} else {
					$row['Identity'] = false;
				}
				++$p;
			}

			$desc[$row['Field']] = array(
			                'TABLE_NAME'		=> $tableName,
			                'COLUMN_NAME'		=> $row['Field'],
			                'COLUMN_POSITION'	=> $i,
			                'DATA_TYPE'			=> strtoupper($row['Type']),
			                'DEFAULT_VALUE'		=> $row['Default'],
			                'NULLABLE'			=> (bool) ($row['Null'] == 'YES'),
			                'LENGHT'			=> $row['Lenght'],
			                'SCALE'				=> $row['Scale'],
			                'PRECISION'			=> $row['Precision'],
			                'UNSIGNED'			=> $row['Unsigned'],
			                'PRIMARY'			=> $row['Primary'],
			                'PRIMARY_POSITION'	=> $row['PrimaryPosition'],
			                'IDENTITY'			=> $row['Identity']
			);
			++$i;
		}
		return $desc;
	}
}
