<?php
require dirname(__DIR__) . '/app/bootstrap.php';
$u = exigir_login('dono');
$fz = exigir_fazenda();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $acao = post('acao');

    if ($acao === 'adicionar') {
        $nome = post('nome');
        $email = strtolower(post('email'));

        if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('erro', 'Informe nome e um e-mail válido.');
        } elseif (um('SELECT id FROM funcionarios WHERE fazenda_id = ? AND LOWER(email) = ?', [$fz['id'], $email])) {
            flash('erro', 'Este e-mail já está cadastrado nesta fazenda.');
        } else {
            $conta = um('SELECT * FROM usuarios WHERE LOWER(email) = ?', [$email]);
            if ($conta && $conta['tipo'] !== 'funcionario') {
                flash('erro', 'Este e-mail pertence a uma conta de dono. O funcionário precisa de uma conta do tipo "Funcionário".');
                redirecionar('dono/funcionarios.php');
            }
            consulta(
                'INSERT INTO funcionarios (fazenda_id, usuario_id, nome, email, funcao, criado_em) VALUES (?, ?, ?, ?, ?, ?)',
                [$fz['id'], $conta['id'] ?? null, $nome, $email, post('funcao') ?: null, agora()]
            );
            flash('sucesso', $conta
                ? "$nome já tinha conta e foi vinculado(a) à fazenda."
                : "$nome cadastrado(a). Peça para criar uma conta de funcionário com o e-mail $email.");
        }
    }

    if ($acao === 'ativo') {
        consulta('UPDATE funcionarios SET ativo = ? WHERE id = ? AND fazenda_id = ?', [(int) post('valor'), (int) post('id'), $fz['id']]);
        flash('sucesso', 'Cadastro atualizado.');
    }
    redirecionar('dono/funcionarios.php');
}

$funcionarios = todos(
    'SELECT f.*, (SELECT entrada FROM ponto WHERE funcionario_id = f.id AND saida IS NULL ORDER BY entrada DESC LIMIT 1) AS em_servico,
            (SELECT MAX(data) FROM relatorios WHERE funcionario_id = f.id) AS ultimo_relatorio
     FROM funcionarios f WHERE fazenda_id = ? ORDER BY ativo DESC, nome', [$fz['id']]);

$titulo = 'Funcionários';
$aba = 'funcionarios';
require RAIZ . '/app/topo.php';
?>
<h1 class="titulo-app">Funcionários</h1>

<div class="duas-colunas">
  <div class="card">
    <h2>Equipe da fazenda</h2>
    <?php if (!$funcionarios): ?>
      <p class="vazio">Nenhum funcionário cadastrado ainda.</p>
    <?php else: ?>
      <div class="tabela-wrap">
        <table>
          <thead><tr><th>Nome</th><th>Função</th><th>Conta</th><th>Agora</th><th>Último relatório</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($funcionarios as $f): ?>
            <tr class="<?= $f['ativo'] ? '' : 'inativo' ?>">
              <td><strong><?= e($f['nome']) ?></strong><br><small class="suave"><?= e($f['email']) ?></small></td>
              <td><?= e($f['funcao'] ?: '—') ?></td>
              <td><?= $f['usuario_id'] ? '<span class="badge verde">Vinculado</span>' : '<span class="badge amarelo">Aguardando cadastro</span>' ?></td>
              <td><?= $f['em_servico'] ? '<span class="badge verde">Trabalhando desde ' . hora_br($f['em_servico']) . '</span>' : '<span class="suave">—</span>' ?></td>
              <td><?= data_br($f['ultimo_relatorio']) ?></td>
              <td>
                <form method="post"><?= csrf_campo() ?>
                  <input type="hidden" name="acao" value="ativo"><input type="hidden" name="id" value="<?= $f['id'] ?>">
                  <input type="hidden" name="valor" value="<?= $f['ativo'] ? 0 : 1 ?>">
                  <button class="botao pequeno <?= $f['ativo'] ? 'contorno' : '' ?>"><?= $f['ativo'] ? 'Desativar' : 'Reativar' ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <form method="post" class="card formulario">
    <?= csrf_campo() ?>
    <input type="hidden" name="acao" value="adicionar">
    <h2>Cadastrar funcionário</h2>
    <div class="campo">
      <label for="nome">Nome *</label>
      <input id="nome" name="nome" required>
    </div>
    <div class="campo">
      <label for="email">E-mail *</label>
      <input type="email" id="email" name="email" required>
    </div>
    <div class="campo">
      <label for="funcao">Função</label>
      <input id="funcao" name="funcao" placeholder="Ex.: Operador de máquinas" list="funcoes">
      <datalist id="funcoes">
        <option value="Operador de máquinas"><option value="Tratorista"><option value="Mecânico">
        <option value="Colhedor"><option value="Encarregado"><option value="Serviços gerais">
      </datalist>
    </div>
    <button class="botao">Cadastrar</button>
    <div class="destaque pequeno-texto">
      <strong>Como o funcionário acessa?</strong> Ele cria uma conta do tipo <em>Funcionário</em>
      (ou entra com o Google) usando <strong>este mesmo e-mail</strong>. O vínculo com a fazenda é automático.
    </div>
  </form>
</div>
<?php require RAIZ . '/app/rodape.php'; ?>
