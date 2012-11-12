--
-- Estrutura da tabela `usuario`
--

CREATE TABLE IF NOT EXISTS `usuario` (
  `cpf` char(11) NOT NULL,
  `nome_completo` varchar(64) DEFAULT NULL,
  `endereco` varchar(128) DEFAULT NULL,
  `telefone` varchar(16) DEFAULT NULL,
  `senha` char(32) NOT NULL,
  `cargo` enum('host','gerente') NOT NULL DEFAULT 'host',
  PRIMARY KEY (`cpf`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Extraindo dados da tabela `usuario`
--

INSERT INTO `usuario` (`cpf`, `nome_completo`, `endereco`, `telefone`, `senha`, `cargo`) VALUES
('11111111111', 'Howard Wolowitz', NULL, NULL, 'e10adc3949ba59ae93733779051c9926', 'host'),
('25478412586', 'Sheldon Cooper', NULL, NULL, '93897cc117a734be93733779051c9926', 'host'),
('36478954215', 'Marcus Cascalhes', 'Rua XV de Novembro, 1400', '1633456732', 'e10adc3949ba59abbe56e057f20f883e', 'host'),
('4612378455', 'Henrique Cardoso', NULL, NULL, '8f3f07c9f13f5f85d42c5e08d17163aa', 'host'),
('46875214588', 'Nelson Maranhão', 'Avenida Equador, 1900', '1633445367', 'e8d95a51f3af4a3b134bf6bb680a213a', 'host'),
('G53627787392', 'José Robustus', NULL, NULL, 'f1887d3f9e6ee7a32fe5e76f4ab80d63', 'host'),
('54785265899', 'Pelé do Santos', NULL, NULL, '400be49a7eff60f8afa9e3c01cfda2e1', 'host'),
('65478522455', 'Luís Gomes', 'Rua Peru, 43', '1633447851', 'a09272b53419ab95507cdf127329336d', 'host'),
('87548965210', 'Danilo Pedroso', 'Rua Carlos Botelho, 2453', '1633697845', 'e36a2f90240e9e84483504fd4a704452', 'gerente');

