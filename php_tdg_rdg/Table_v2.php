<?php
class Table {

    /**
     * O nome da tabela
     * @var string
     */
    private $name;

    /**
     * O driver MySQLi para a execução dos statements.
     * @var MySQLi
     */
    private $driver;

    /**
     * O statement a ser executado no banco de dados.
     * @var MySQLi_Stmt
     */
    private $stmt;

    /**
     * Armazena os nomes dos campos da tabela.
     * É obtido a partir de $metadata.
     * @var array
     */
    private $cols = array();

    /**
     * Armazena os nomes dos campos que compõem a chave primária da tabela.
     * @var array
     */
    private $primaryKey;

    /**
     * Se a chave primária é composta e uma das colunas
     * usa auto-incremnto, setamos $identity para o índice
     * ordinal do campo no array $_primary
     * (o array $_primary começa em 1).
     *
     * Caso a chave primária seja uma chave natural,
     * seu valor é NULL.
     *
     * @var integer|null
     */
    private $identity = null;

    /**
     * Checar ou não a integridade dos dados ao criar objetos Row desta tabela.
     * Desabilitar a checagem de integridade é útil para JOINs.
     * @var boolean
     */
    private $integrityCheck = true;

    /**
      * Armazena os metadados da tabela, a partir dos quais é possível conhecer a sua estrutura.
     * @var array
     */
    private $metadata = array();

    /**
     * O driver padrão MySQLi, caso não seja passado o parâmetro no construtor.
     * @var MySQLi
     */
    private static $defaultDriver;

    /**
     * Construtor.
     *
     * @param string $name: o nome da tabela
     * @param Driver $driver : o driver para o banco de dados
     * @param array $map : um "mapa" para os campos da tabela, da forma:
     *     array (
     *        name => 'nome do campo',
     *         type => 'tipo SQL do campo',
     *        is_primary => 'se o campo é parte da chave primária da tabela (boolean)'
     *    )
     */
    public function __construct($name, Driver $driver = null) {
        $this->setName($name);
        // Seta o valor de $driver para o default se não houver parâmetro
        ($driver === null) && $driver = self::$defaultDriver;
        
        // Se não houver nenhum dos dois aí temos um problema
        ($driver === null) && throw new Exception(
            'Nenhum driver disponível para o objeto Db_Table'
        );
        
        $this->setDriver($driver);
    }

    private function setName($name) {
        $this->name = $name;
    }

    public function getName() {
        return $this->name;
    }
    
    public static function setDefaultDriver(Driver $driver) {
        self::$defaultDriver = $driver;
    }
    
    public static function getDefaultDriver() {
        return self::$defaultDriver;
    }

    public function setDriver(Driver $driver) {
        $this->driver = $driver;
        return $this;
    }

    public function getDriver() {
        return $this->driver;
    }

    /**
     * Setando a checagem de integridade para false, é possível utilizar
     * o método createRow com dados não pertencentes à tabela. (Ex.: JOINs e alias).
     *
     * @param bool $opt
     * @return Table : fluent interface
     */
    public function setIntegrityCheck($opt) {
        $this->integrityCheck = (bool) $opt;
        return $this;
    }

    /**
     * Retorna as colunas da tabela.
     *
     * @return array
     */
    private function getCols() {
        if(empty($this->cols)) {
            $this->setupMetadata();
            $this->cols = array_keys($this->metadata);
        }
        return $this->cols;
    }

    /**
     * Retorna o nome da coluna que é a chave primária da tabela.
     * Caso a chave seja composta, retornará um array com os nomes
     * das colunas que a compõem.
     *
     * @return string|array
     */
    public function getPrimaryKey() {
        $this->setupPrimaryKey();
        if(count($this->primaryKey) == 1) {
            return reset($this->primaryKey);
        } else {
            return $this->primaryKey;
        }
    }

    /**
     * Retorna o nome do campo que é a identidade da chave primária.
     * Normalmente, trata-se de um campo com auto incremento.
     *
     * Se a chave primária da tabela é uma chave natural, este método
     * retornará sempre NULL.
     *
     * @return string|null
     */
    public function getIdentity() {
        $pk = $this->getPrimaryKey();

        if($this->identity === null) return null;

        if(is_array($pk)) {
            return $pk[$this->identity];
        } else {
            return $pk;
        }
     }

    /**
     * Insere os dados em $data no banco de dados.
     *
     * @param $data : os dados a inserir
     * @return int : o número de linhas inseridas
     */
    public function insert(array $data) {
        $data = $this->intersectData($data);
        return $this->driver->insert($this->getName(), $data);
    }

    /**
     * Atualiza os dados em $data no banco de dados.
     *
     * @param $data : os dados para atualizar
     * @param $cond : uma condicional para a atualização
     * @param array $condParams : caso a condição seja parte de um
     *        prepared statement, estes são os parâmetros
     * @return int : o número de linhas atualizadas
     */
    public function update(array $data, $cond, array $condParams = array()) {
        $data = $this->intersectData($data);
        return $this->driver->update($this->getName(),$data, $cond, $condParams);
    }

    /**
     * Deleta dados do banco.
     *
     * @param $cond : uma condicional para a atualização
     * @param array $condParams : caso a condição seja parte de um
     *        prepared statement, estes são os parâmetros
     * @return int : o número de linhas deletadas
     */
    public function delete($cond, array $condParams = array()) {
        return $this->driver->delete($this->getName(), $cond, $condParams);
    }

    /**
     * Retorna dados pela chave primária.
     * Se a chave primária é composta, há duas opções:
     *        1. Fornecer os valores correspondentes na ordem que os campos aparecem na tabela:
     *            $table->getById(array(1, 'foo'));
     *        2. Fornecer um conjunto de chaves/valores com o nome dos campos>
     *            $table->getById(array(
     *                 'campo2' => 'foo',
     *                'campo1' => 1
     *            ));
     *
     * @param mixed $id : o valor da chave primária do registro desejado
     * @return Row|null
     */
    public function getById($id) {
        $this->setupPrimaryKey();

        $id = (array) $id;
        if(($countId = count($id)) != ($countPk = count($this->primaryKey))) {
            throw new Exception("A tabela {$this->name} possui {$countPk} campos em sua chave primária.
                O número de campos fornecidos para a busca foi {$countId}!");
        }

        $sql = 'SELECT * FROM ' . $this->getName()
             . ' WHERE ';

        $fields = array();
        // Caso 1
        if(is_numeric(key($id))) {
            // Precisamos disso porque $this->primaryKey é um array 1-based
            foreach($this->primaryKey as $col) {
                $fields[] = $col . ' =  ?';
            }
        }
        // Caso 2
        else {
            $keys = array_keys($id);
            $diff = array_diff($this->primaryKey, $keys);
            if(!empty($diff)) {
                $pkStr = join(', ', $this->primaryKey);
                $keysStr = join(', ', $keys);
                throw new Exception("A tabela {$this->name} tem chave primária composta pelos campos
                    {$pkStr}. Os seguintes campos foram fornecidos: {$keysStr}");
            }

            foreach($keys as $col) {
                $fields[] = $col . ' = ?';
            }
        }

        $sql .= join(' AND ', $fields);

        $this->driver->query($sql, array_values($id));

        $data = $this->driver->fetchOne();
        if($data !== null) {
            return $this->doCreateRow($data, true);
        } else {
            return null;
        }
    }

    /**
     * Retorna um conjunto de linhas da tabela.
     *
     * @param string|array|null $fields : os campos da tabela a serem retornados.
     *    Caso seja nulo, utilizamos o SQL wildcard '*', que retornará todos os campos.
     * @param string $order : a ordenação da busca "{<nome_campo><ASC|DESC>}*"
     * @param int count : a quantidade de linhas a retornar
     * @param int offset : a linha inicial a partir da qual começar a buscar (0-based)
     * @return array
     */
    public function getAll($fields = null, $order = null, $count = null, $offset = null) {
        $currentIntegrityCheck = $this->integrityCheck;
        if($fields == null) {
            $fields = $this->getCols();
        } else {
            /* Caso não estejamos selecionando todas as colunas, é preciso que o
             * objeto Row retornado seja somente leitura. Isso se deve ao fato de
             * que ele não conterá toda a informação necessária para realizar uma
             * operação de UPDATE, por exemplo.
             */
            $diff = array_diff($this->getCols(), $fields);
            if(!empty($diff)) {
                // Fará com que os objetos Row gerados sejam somente leitura...
                $this->integrityCheck = false;
            }
        }
        $this->driver->select($this->getName(), $order, $count, $offset, $fields);
        $ret = array();
        $result = $this->driver->fetchAll();

        foreach($result as $rowData) {
            $ret[] = $this->doCreateRow($rowData, true);
        }

        // Retorna integrityCheck ao seu valor anterior...
        $this->integrityCheck = $currentIntegrityCheck;
        return $ret;
    }

    /**
     * Método público para a criação de um objeto Row a partir de $data.
     *
     * @param $data : os dados para a criação do objeto
     * @return Row
     */
    public function createRow(array $data = array()) {
        return $this->doCreateRow($data, false);
    }

    /**
     * Internamente, é possível configurar os dados como 'stored',
     * o que indica que os dados são proveniente do banco, logo, são válidos.
     * Em chamadas externas, é não é possível garantir isso.
     *
     * @param array $data : os dados para a criação do objeot
     * @param bool $stored : indica se os dados são ou não provenientes do banco.
     */
    private function doCreateRow(array $data, $stored) {
        if($this->integrityCheck === false && !empty($data)) {
            // Se a checagem de integridade está desabilitada, removemos a permissão de escrita
            $newRow = new Row($this, array(
                'readOnly' => true,
                'stored' => $stored,
                'data' => $data
            ));
        } else {
            $cols = $this->getCols();
            $defaults = array_combine($cols, array_fill(0, count($cols), null));
            $rowData = array_intersect_key(array_replace($defaults, $data),$defaults);

            $newRow = new Row($this, array(
                'readOnly' => false,
                'stored' => $stored,
                'data' => $rowData
            ));
        }
        return $newRow;
    }

    /**
     * Para operações de inserção e atualização, temos que garantir que
     * entre os dados não haja campos inexistentes na tabela,
     * o que geraria um erro na execução do statement.
     *
     * @param array $data : os dados para a operação
     * @return array : os dados filtrados, contendo apenas campos da tabela
     */
    private function intersectData(array $data) {
        $this->setupMetadata();
        return array_intersect_key($data, $this->metadata);
    }

    /**
     * Configura os metadados da tabela
     * @return void
     */
    private function setupMetadata() {
        if(empty($this->metadata)) {
            $this->metadata = $this->driver->describeTable($this->name);
            if(empty($this->metadata)) {
                throw new Exception("Impossível obter a descrição da tabela {$this->name}!");
            }
        }
    }

    /**
     * Configura a chave primária da tabela.
     * @return void
     */
    private function setupPrimaryKey() {
        if(!$this->primaryKey) {
            $this->setupMetadata();
            $this->primaryKey = array();

            foreach($this->metadata as $col) {
                if($col['PRIMARY'] === true) {
                    $colName = $col['COLUMN_NAME'];
                    $this->primaryKey[$col['PRIMARY_POSITION']] = $colName;

                    if($col['IDENTITY'] === true) {
                        $this->identity = $col['PRIMARY_POSITION'];
                    }
                }
            }
        }
    }

}
