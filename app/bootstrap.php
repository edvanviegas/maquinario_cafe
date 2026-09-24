<?php
/*
 * Arquivo carregado por todas as páginas PHP do sistema:
 * sessão, conexão com o banco, login e funções auxiliares.
 */
declare(strict_types=1);

define('RAIZ', dirname(__DIR__));
require RAIZ . '/app/config.php';

date_default_timezone_set('America/Sao_Paulo');

session_name('cafemaq');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

/* Tipos de máquina (mesmos do catálogo) e intervalo de revisão preventiva em horas */
const TIPOS_MAQUINA = [
    'trator'               => ['nome' => 'Trator cafeeiro',            'revisao' => 250],
    'colhedora'            => ['nome' => 'Colhedora automotriz',       'revisao' => 150],
    'colhedora-tracionada' => ['nome' => 'Colhedora tracionada',       'revisao' => 150],
    'pulverizador'         => ['nome' => 'Pulverizador',               'revisao' => 100],
    'rocadeira'            => ['nome' => 'Roçadeira',                  'revisao' => 100],
    'adubadora'            => ['nome' => 'Adubadora / distribuidora',  'revisao' => 100],
    'derricadeira'         => ['nome' => 'Derriçadeira portátil',      'revisao' => 50],
    'outra'                => ['nome' => 'Outra',                      'revisao' => 250],
];

/* ===================== Banco de dados ===================== */

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }

    $opcoes = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    if (DB_DRIVER === 'sqlite') {
        $pdo = new PDO('sqlite:' . RAIZ . '/database/cafemaq.sqlite', null, null, $opcoes);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $existe = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='usuarios'")->fetch();
        if (!$existe) {
            $pdo->exec(file_get_contents(RAIZ . '/database/schema_sqlite.sql'));
        }
    } else {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $opcoes);
    }
    return $pdo;
}

function consulta(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function um(string $sql, array $params = []): ?array
{
    $linha = consulta($sql, $params)->fetch();
    return $linha ?: null;
}

function todos(string $sql, array $params = []): array
{
    return consulta($sql, $params)->fetchAll();
}

function valor(string $sql, array $params = [])
{
    return consulta($sql, $params)->fetchColumn();
}

function ultimo_id(): int
{
    return (int) db()->lastInsertId();
}

/* ===================== Utilidades ===================== */

function e($texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function agora(): string
{
    return date('Y-m-d H:i:s');
}

function hoje(): string
{
    return date('Y-m-d');
}

/* Monta um caminho relativo à raiz do site, funcione a página em qualquer subpasta */
function url(string $caminho = ''): string
{
    $pasta = str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME']));
    $raiz = str_replace('\\', '/', RAIZ);
    $nivel = $pasta === $raiz ? 0 : substr_count(trim(substr($pasta, strlen($raiz)), '/'), '/') + 1;
    return str_repeat('../', $nivel) . $caminho;
}

function redirecionar(string $caminho): void
{
    header('Location: ' . url($caminho));
    exit;
}

function flash(string $tipo, string $mensagem): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'msg' => $mensagem];
}

function pegar_flashes(): array
{
    $lista = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $lista;
}

function post(string $campo, string $padrao = ''): string
{
    return trim((string) ($_POST[$campo] ?? $padrao));
}

function numero_ou_nulo(string $valor): ?float
{
    $valor = str_replace(',', '.', trim($valor));
    return is_numeric($valor) ? (float) $valor : null;
}

function data_br(?string $data): string
{
    return $data ? date('d/m/Y', strtotime($data)) : '—';
}

function hora_br(?string $data): string
{
    return $data ? date('H:i', strtotime($data)) : '—';
}

function reais($valor): string
{
    return 'R$ ' . number_format((float) $valor, 2, ',', '.');
}

function horas_br(float $horas): string
{
    $h = (int) floor($horas);
    $m = (int) round(($horas - $h) * 60);
    if ($m === 60) {
        $h++;
        $m = 0;
    }
    return $h . 'h' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
}

function num_br($valor, int $casas = 1): string
{
    return number_format((float) $valor, $casas, ',', '.');
}

/* Horas entre entrada e saída; registros ainda abertos contam até agora */
function duracao_ponto(string $entrada, ?string $saida): float
{
    $fim = $saida ? strtotime($saida) : time();
    return max(0, ($fim - strtotime($entrada)) / 3600);
}

/* ===================== Segurança (CSRF) ===================== */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function verificar_csrf(): void
{
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Sessão expirada. Volte e tente novamente.');
    }
}

/* ===================== Login ===================== */

function usuario(): ?array
{
    static $usuario = false;
    if ($usuario === false) {
        $usuario = empty($_SESSION['uid']) ? null : um('SELECT * FROM usuarios WHERE id = ?', [$_SESSION['uid']]);
    }
    return $usuario;
}

function fazer_login(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $u['id'];
    unset($_SESSION['fazenda_id']);
    if ($u['tipo'] === 'funcionario') {
        vincular_funcionario($u);
    }
}

function pagina_inicial(array $u): string
{
    return $u['tipo'] === 'dono' ? 'dono/index.php' : 'funcionario/index.php';
}

function exigir_login(?string $tipo = null): array
{
    $u = usuario();
    if (!$u) {
        flash('info', 'Entre na sua conta para continuar.');
        redirecionar('entrar.php');
    }
    if ($tipo && $u['tipo'] !== $tipo) {
        redirecionar(pagina_inicial($u));
    }
    return $u;
}

/* Liga a conta do funcionário aos cadastros feitos pelo dono com o mesmo e-mail */
function vincular_funcionario(array $u): void
{
    consulta('UPDATE funcionarios SET usuario_id = ? WHERE usuario_id IS NULL AND LOWER(email) = LOWER(?)', [$u['id'], $u['email']]);
}

/* ===================== Fazenda / vínculo ===================== */

/* Fazenda selecionada pelo dono (ou a primeira dele) */
function fazenda_atual(): ?array
{
    $u = usuario();
    $f = um('SELECT * FROM fazendas WHERE id = ? AND dono_id = ?', [$_SESSION['fazenda_id'] ?? 0, $u['id']]);
    if (!$f) {
        $f = um('SELECT * FROM fazendas WHERE dono_id = ? ORDER BY id LIMIT 1', [$u['id']]);
        if ($f) {
            $_SESSION['fazenda_id'] = $f['id'];
        }
    }
    return $f;
}

function exigir_fazenda(): array
{
    $f = fazenda_atual();
    if (!$f) {
        flash('info', 'Cadastre sua fazenda para começar.');
        redirecionar('dono/fazendas.php');
    }
    return $f;
}

/* Cadastro de funcionário (vínculo com a fazenda) do usuário logado */
function vinculo_atual(): ?array
{
    return um(
        'SELECT f.*, fz.nome AS fazenda_nome FROM funcionarios f
         JOIN fazendas fz ON fz.id = f.fazenda_id
         WHERE f.usuario_id = ? AND f.ativo = 1 ORDER BY f.id LIMIT 1',
        [usuario()['id']]
    );
}

function ponto_aberto(int $funcionarioId): ?array
{
    return um('SELECT * FROM ponto WHERE funcionario_id = ? AND saida IS NULL ORDER BY entrada DESC LIMIT 1', [$funcionarioId]);
}
