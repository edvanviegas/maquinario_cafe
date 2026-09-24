<?php
require dirname(__DIR__) . '/app/bootstrap.php';
$u = exigir_login('dono');
$fz = exigir_fazenda();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $acao = post('acao');

    if ($acao === 'criar') {
        $titulo = post('titulo');
        if ($titulo === '') {
            flash('erro', 'Informe o título da atividade.');
            redirecionar('dono/atividades.php');
        }
        // Só aceita máquina e funcionário que sejam desta fazenda
        $maquina = um('SELECT id FROM maquinas WHERE id = ? AND fazenda_id = ?', [(int) post('maquina_id'), $fz['id']]);
        $func = um('SELECT id FROM funcionarios WHERE id = ? AND fazenda_id = ?', [(int) post('funcionario_id'), $fz['id']]);

        consulta(
            'INSERT INTO atividades (fazenda_id, titulo, descricao, tipo, maquina_id, funcionario_id, data_prevista, recorrente, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$fz['id'], $titulo, post('descricao') ?: null, post('tipo') === 'manutencao' ? 'manutencao' : 'operacao',
             $maquina['id'] ?? null, $func['id'] ?? null, post('data_prevista') ?: null, isset($_POST['recorrente']) ? 1 : 0, agora()]
        );
        flash('sucesso', 'Atividade criada. Ela já aparece no formulário dos funcionários.');
    }

    if ($acao === 'status' && in_array(post('valor'), ['pendente', 'concluida', 'arquivada'], true)) {
        consulta('UPDATE atividades SET status = ? WHERE id = ? AND fazenda_id = ?', [post('valor'), (int) post('id'), $fz['id']]);
    }
    redirecionar('dono/atividades.php');
}

$sqlBase = 'SELECT a.*, m.nome AS maquina, f.nome AS funcionario,
                   (SELECT MAX(r.data) FROM relatorio_itens i JOIN relatorios r ON r.id = i.relatorio_id
                     WHERE i.atividade_id = a.id AND i.status <> \'nao_feita\') AS ultima_execucao
            FROM atividades a
            LEFT JOIN maquinas m ON m.id = a.maquina_id
            LEFT JOIN funcionarios f ON f.id = a.funcionario_id
            WHERE a.fazenda_id = ? AND a.status = ?';
$pendentes = todos($sqlBase . ' ORDER BY a.recorrente DESC, a.data_prevista IS NULL, a.data_prevista, a.id', [$fz['id'], 'pendente']);
$concluidas = todos($sqlBase . ' ORDER BY a.id DESC LIMIT 20', [$fz['id'], 'concluida']);

$maquinas = todos('SELECT id, nome FROM maquinas WHERE fazenda_id = ? AND ativo = 1 ORDER BY nome', [$fz['id']]);
$funcionarios = todos('SELECT id, nome FROM funcionarios WHERE fazenda_id = ? AND ativo = 1 ORDER BY nome', [$fz['id']]);

function linha_atividade(array $a, bool $pendente): void
{
    $atrasada = $pendente && !$a['recorrente'] && $a['data_prevista'] && $a['data_prevista'] < hoje();
    ?>
    <div class="item-atividade">
      <div>
        <strong><?= e($a['titulo']) ?></strong>
        <?= $a['tipo'] === 'manutencao' ? '<span class="badge vermelho">Manutenção</span>' : '<span class="badge verde">Operação</span>' ?>
        <?= $a['recorrente'] ? '<span class="badge cinza">Todo dia</span>' : '' ?>
        <?= $atrasada ? '<span class="badge amarelo">Atrasada</span>' : '' ?>
        <?php if ($a['descricao']): ?><p class="suave"><?= nl2br(e($a['descricao'])) ?></p><?php endif; ?>
        <small class="suave">
          👤 <?= e($a['funcionario'] ?? 'Qualquer funcionário') ?>
          <?= $a['maquina'] ? ' · 🚜 ' . e($a['maquina']) : '' ?>
          <?= $a['data_prevista'] ? ' · 📅 ' . ($a['recorrente'] ? 'a partir de ' : '') . data_br($a['data_prevista']) : '' ?>
          <?= $a['ultima_execucao'] ? ' · última execução ' . data_br($a['ultima_execucao']) : '' ?>
        </small>
      </div>
      <div class="acoes">
        <?php foreach ($pendente ? ['concluida' => 'Concluir', 'arquivada' => 'Arquivar'] : ['pendente' => 'Reabrir'] as $valor => $rotulo): ?>
          <form method="post"><?= csrf_campo() ?>
            <input type="hidden" name="acao" value="status"><input type="hidden" name="id" value="<?= $a['id'] ?>">
            <input type="hidden" name="valor" value="<?= $valor ?>">
            <button class="botao pequeno contorno"><?= $rotulo ?></button>
          </form>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
}

$titulo = 'Atividades';
$aba = 'atividades';
require RAIZ . '/app/topo.php';
?>
<h1 class="titulo-app">Atividades</h1>

<div class="duas-colunas">
  <div>
    <div class="card">
      <h2>A fazer (<?= count($pendentes) ?>)</h2>
      <?php if (!$pendentes): ?><p class="vazio">Nenhuma atividade pendente.</p><?php endif; ?>
      <?php foreach ($pendentes as $a) linha_atividade($a, true); ?>
    </div>
    <div class="card">
      <h2>Concluídas recentemente</h2>
      <?php if (!$concluidas): ?><p class="vazio">Nada concluído ainda.</p><?php endif; ?>
      <?php foreach ($concluidas as $a) linha_atividade($a, false); ?>
    </div>
  </div>

  <form method="post" class="card formulario">
    <?= csrf_campo() ?>
    <input type="hidden" name="acao" value="criar">
    <h2>Nova atividade</h2>
    <div class="campo">
      <label for="titulo">Título *</label>
      <input id="titulo" name="titulo" required placeholder="Ex.: Roçar talhão 3">
    </div>
    <div class="campo">
      <label for="descricao">Instruções</label>
      <textarea id="descricao" name="descricao" rows="3"></textarea>
    </div>
    <div class="form-grade">
      <div class="campo">
        <label for="tipo">Tipo</label>
        <select id="tipo" name="tipo">
          <option value="operacao">Operação (trabalho de campo)</option>
          <option value="manutencao">Manutenção de máquina</option>
        </select>
      </div>
      <div class="campo">
        <label for="data_prevista">Data prevista</label>
        <input type="date" id="data_prevista" name="data_prevista" value="<?= hoje() ?>">
      </div>
      <div class="campo">
        <label for="maquina_id">Máquina usada</label>
        <select id="maquina_id" name="maquina_id">
          <option value="">Nenhuma</option>
          <?php foreach ($maquinas as $m): ?><option value="<?= $m['id'] ?>"><?= e($m['nome']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label for="funcionario_id">Responsável</label>
        <select id="funcionario_id" name="funcionario_id">
          <option value="">Qualquer funcionário</option>
          <?php foreach ($funcionarios as $f): ?><option value="<?= $f['id'] ?>"><?= e($f['nome']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <label class="checkbox"><input type="checkbox" name="recorrente"> Repetir todos os dias (ex.: checklist diário)</label>
    <button class="botao">Criar atividade</button>
  </form>
</div>
<?php require RAIZ . '/app/rodape.php'; ?>
