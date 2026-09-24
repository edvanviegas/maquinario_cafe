<?php
/*
 * CafeMaq - Configurações
 *
 * TESTE LOCAL: deixe DB_DRIVER = 'sqlite'. O banco é criado sozinho em
 *              database/cafemaq.sqlite, sem precisar instalar nada.
 *
 * HOSTGATOR:   mude para 'mysql' e preencha os dados do banco criado no
 *              cPanel (Bancos de dados MySQL). Depois importe o arquivo
 *              database/schema_mysql.sql pelo phpMyAdmin.
 */
define('DB_DRIVER', 'sqlite');   // 'sqlite' ou 'mysql'

define('DB_HOST', 'localhost');
define('DB_NAME', 'usuario_cafemaq');   // na HostGator o nome tem o prefixo da conta
define('DB_USER', 'usuario_cafemaq');
define('DB_PASS', '');

/*
 * Login com Google
 * Crie um "ID do cliente OAuth" (tipo Aplicativo da Web) em
 * https://console.cloud.google.com/apis/credentials e cole o ID abaixo.
 * Em "Origens JavaScript autorizadas" adicione:
 *   http://localhost:8000          (teste local)
 *   https://seudominio.com.br      (site na HostGator)
 * Deixe vazio para esconder o botão do Google.
 */
define('GOOGLE_CLIENT_ID', '');
