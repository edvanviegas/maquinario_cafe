-- =============================================================
-- CafeMaq - Estrutura do banco de dados (MySQL / MariaDB)
-- Importe este arquivo pelo phpMyAdmin do cPanel (HostGator).
-- =============================================================

CREATE TABLE usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  senha_hash VARCHAR(255) NULL,          -- NULL quando a conta usa só o Google
  google_id VARCHAR(64) NULL UNIQUE,
  tipo ENUM('dono','funcionario') NOT NULL,
  criado_em DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE fazendas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dono_id INT NOT NULL,
  nome VARCHAR(120) NOT NULL,
  municipio VARCHAR(120) NULL,
  estado CHAR(2) NULL,
  area_ha DECIMAL(10,2) NULL,
  producao_sacas INT NULL,
  criado_em DATETIME NOT NULL,
  FOREIGN KEY (dono_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Funcionário cadastrado pelo dono. usuario_id fica NULL até a pessoa
-- criar a conta (ou entrar com Google) usando o mesmo e-mail.
CREATE TABLE funcionarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fazenda_id INT NOT NULL,
  usuario_id INT NULL,
  nome VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL,
  funcao VARCHAR(80) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL,
  UNIQUE KEY fazenda_email (fazenda_id, email),
  FOREIGN KEY (fazenda_id) REFERENCES fazendas(id) ON DELETE CASCADE,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE maquinas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fazenda_id INT NOT NULL,
  nome VARCHAR(120) NOT NULL,
  tipo VARCHAR(40) NOT NULL,
  modelo VARCHAR(120) NULL,
  ano INT NULL,
  horimetro DECIMAL(10,1) NOT NULL DEFAULT 0,
  preco DECIMAL(12,2) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL,
  FOREIGN KEY (fazenda_id) REFERENCES fazendas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Atividades criadas pelo dono. funcionario_id NULL = qualquer funcionário.
-- recorrente = 1: aparece todo dia (ex.: checklist diário do trator).
CREATE TABLE atividades (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fazenda_id INT NOT NULL,
  titulo VARCHAR(160) NOT NULL,
  descricao TEXT NULL,
  tipo ENUM('operacao','manutencao') NOT NULL DEFAULT 'operacao',
  maquina_id INT NULL,
  funcionario_id INT NULL,
  data_prevista DATE NULL,
  recorrente TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('pendente','concluida','arquivada') NOT NULL DEFAULT 'pendente',
  criado_em DATETIME NOT NULL,
  FOREIGN KEY (fazenda_id) REFERENCES fazendas(id) ON DELETE CASCADE,
  FOREIGN KEY (maquina_id) REFERENCES maquinas(id) ON DELETE SET NULL,
  FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bater ponto
CREATE TABLE ponto (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fazenda_id INT NOT NULL,
  funcionario_id INT NOT NULL,
  entrada DATETIME NOT NULL,
  saida DATETIME NULL,
  FOREIGN KEY (fazenda_id) REFERENCES fazendas(id) ON DELETE CASCADE,
  FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Relatório diário (um por funcionário por dia)
CREATE TABLE relatorios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fazenda_id INT NOT NULL,
  funcionario_id INT NOT NULL,
  data DATE NOT NULL,
  observacoes TEXT NULL,
  criado_em DATETIME NOT NULL,
  UNIQUE KEY funcionario_dia (funcionario_id, data),
  FOREIGN KEY (fazenda_id) REFERENCES fazendas(id) ON DELETE CASCADE,
  FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE relatorio_itens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  relatorio_id INT NOT NULL,
  atividade_id INT NULL,
  atividade_titulo VARCHAR(160) NOT NULL,
  status ENUM('feita','parcial','nao_feita') NOT NULL,
  horas DECIMAL(5,2) NOT NULL DEFAULT 0,
  maquina_id INT NULL,
  horimetro DECIMAL(10,1) NULL,
  FOREIGN KEY (relatorio_id) REFERENCES relatorios(id) ON DELETE CASCADE,
  FOREIGN KEY (atividade_id) REFERENCES atividades(id) ON DELETE SET NULL,
  FOREIGN KEY (maquina_id) REFERENCES maquinas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Problemas em máquinas relatados pelos funcionários
CREATE TABLE ocorrencias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fazenda_id INT NOT NULL,
  maquina_id INT NOT NULL,
  funcionario_id INT NULL,
  relatorio_id INT NULL,
  descricao TEXT NOT NULL,
  gravidade ENUM('baixa','media','alta') NOT NULL DEFAULT 'media',
  status ENUM('aberta','resolvida') NOT NULL DEFAULT 'aberta',
  criado_em DATETIME NOT NULL,
  resolvida_em DATETIME NULL,
  FOREIGN KEY (fazenda_id) REFERENCES fazendas(id) ON DELETE CASCADE,
  FOREIGN KEY (maquina_id) REFERENCES maquinas(id) ON DELETE CASCADE,
  FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id) ON DELETE SET NULL,
  FOREIGN KEY (relatorio_id) REFERENCES relatorios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Manutenções realizadas (com custo)
CREATE TABLE manutencoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fazenda_id INT NOT NULL,
  maquina_id INT NOT NULL,
  data DATE NOT NULL,
  tipo ENUM('preventiva','corretiva') NOT NULL,
  descricao TEXT NOT NULL,
  custo DECIMAL(12,2) NOT NULL DEFAULT 0,
  horimetro DECIMAL(10,1) NULL,
  ocorrencia_id INT NULL,
  criado_em DATETIME NOT NULL,
  FOREIGN KEY (fazenda_id) REFERENCES fazendas(id) ON DELETE CASCADE,
  FOREIGN KEY (maquina_id) REFERENCES maquinas(id) ON DELETE CASCADE,
  FOREIGN KEY (ocorrencia_id) REFERENCES ocorrencias(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
