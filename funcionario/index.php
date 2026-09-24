<?php
require dirname(__DIR__) . '/app/bootstrap.php';
$u = exigir_login('funcionario');
vincular_funcionario($u);
$vinculo = vinculo_atual();

if ($vinculo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $acao = post('acao');
    $fid = (int) $vinculo['id'];
    $aberto = ponto_aberto($fid);

    if ($acao === 'entrada' && !$aberto) {
        consulta('INSERT INTO ponto (fazenda_id, funcionario_id, entrada) VALUES (?, ?, ?)', [$vinculo['fazenda_id'], $fid, agora()]);
        flash('sucesso', 'Entrada registrada às ' . date('H:i') . '. Bom trabalho!');
    }

    if ($acao === 'saida' && $aberto) {
        consulta('UPDATE ponto SET saida = ? WHERE id = ?', [agora(), $aberto['id']]);
        flash('sucesso', 'Saída registrada às ' . date('H:i') . '.');
    }

    if ($acao === 'relatorio') {
        if (um('SELECT id FROM relatorios WHERE funcionario_id = ? AND data = ?', [$fid, hoje()])) {
            flash('erro', 'Você já enviou o relatório de hoje.');
            redirecionar('funcionario/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();

        consulta('INSERT INTO relatorios (fazenda_id, funcionario_id, data, observacoes, criado_em) VALUES (?, ?, ?, ?, ?)',
            [$vinculo['fazenda_id'], $fid, hoje(), post('observacoes') ?: null, agora()]);
        $relatorioId = ultimo_id();

        $inserirItem = function (?int $atividadeId, string $titulo, string $status, float $horas, ?int $maquinaId, ?float $horimetro) use ($relatorioId) {
            consulta('INSERT INTO relatorio_itens (relatorio_id, atividade_id, atividade_titulo, status, horas, maquina_id, horimetro) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$relatorioId, $atividadeId, $titulo, $status, $horas, $maquinaId, $horimetro]);
            if ($maquinaId && $horimetro !== null) {
                consulta('UPDATE maquinas SET horimetro = ? WHERE id = ? AND horimetro < ?', [$horimetro, $maquinaId, $horimetro]);
            }
        };

        // Atividades do formulário (só as que realmente pertencem a este funcionário/fazenda)
        foreach ($_POST['itens'] ?? [] as $atividadeId => $item) {
            $a = um("SELECT * FROM atividades WHERE id = ? AND fazenda_id = ? AND status = 'pendente'
                     AND (funcionario_id IS NULL OR funcionario_id = ?)", [(int) $atividadeId, $vinculo['fazenda_id'], $fid]);
            $status = $item['status'] ?? '';
            if (!$a || !in_array($status, ['feita', 'parcial', 'nao_feita'], true)) {
                continue;
            }
            $inserirItem((int) $a['id'], $a['titulo'], $status, numero_ou_nulo($item['horas'] ?? '') ?? 0,
                $a['maquina_id'] ? (int) $a['maquina_id'] : null, numero_ou_nulo($item['horimetro'] ?? ''));

            if ($status === 'feita' && !$a['recorrente']) {
                consulta("UPDATE atividades SET status = 'concluida' WHERE id = ?", [$a['id']]);
            }
        }

        // Atividade extra, fora da lista
        if (post('extra_titulo') !== '') {
            $maq = um('SELECT id FROM maquinas WHERE id = ? AND fazenda_id = ?', [(int) post('extra_maquina'), $vinculo['fazenda_id']]);
            $inserirItem(null, post('extra_titulo'), 'feita', numero_ou_nulo(post('extra_horas')) ?? 0,
                $maq ? (int) $maq['id'] : null, numero_ou_nulo(post('extra_horimetro')));
        }

        // Problema em máquina vira um alerta para o dono
        $maqProblema = um('SELECT id FROM maquinas WHERE id = ? AND fazenda_id = ?', [(int) post('problema_maquina'), $vinculo['fazenda_id']]);
        if ($maqProblema && post('problema_descricao') !== '') {
            $gravidade = in_array(post('problema_gravidade'), ['baixa', 'media', 'alta'], true) ? post('problema_gravidade') : 'media';
            consulta('INSERT INTO ocorrencias (fazenda_id, maquina_id, funcionario_id, relatorio_id, descricao, gravidade, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$vinculo['fazenda_id'], $maqProblema['id'], $fid, $relatorioId, post('problema_descricao'), $gravidade, agora()]);
        }

        if (isset($_POST['registrar_saida']) && $aberto) {
            consulta('UPDATE ponto SET saida = ? WHERE id = ?', [agora(), $aberto['id']]);
        }

        $pdo->commit();
        flash('sucesso', 'Relatório enviado!' . (isset($_POST['registrar_saida']) && $aberto ? ' Saída registrada às ' . date('H:i') . '.' : ''));
    }
    redirecionar('funcionario/index.php');
}

$titulo = 'Meu dia';
$aba = 'dia';
require RAIZ . '/app/topo.php';

if (!$vinculo): ?>
  <div class="card vazio-grande">
    <h1>Aguardando vínculo com uma fazenda</h1>
    <p>Peça ao dono da fazenda para cadastrar você na aba <strong>Funcionários</strong> usando o e-mail:</p>
    <p class="email-destaque"><?= e($u['email']) ?></p>
    <p class="suave">Assim que ele cadastrar, basta atualizar esta página.</p>
    <a href="index.php" class="botao">Atualizar</a>
  </div>
<?php
    require RAIZ . '/app/rodape.php';
    exit;
endif;

$fid = (int) $vinculo['id'];
$aberto = ponto_aberto($fid);
$pontosHoje = todos('SELECT * FROM ponto WHERE funcionario_id = ? AND entrada >= ? ORDER BY entrada', [$fid, hoje() . ' 00:00:00']);
$horasHoje = array_sum(array_map(fn($p) => duracao_ponto($p['entrada'], $p['saida']), $pontosHoje));

$relatorioHoje = um('SELECT * FROM relatorios WHERE funcionario_id = ? AND data = ?', [$fid, hoje()]);

$atividades = todos(
    "SELECT a.*, m.nome AS maquina, m.horimetro AS maquina_horimetro FROM atividades a
     LEFT JOIN maquinas m ON m.id = a.maquina_id
     WHERE a.fazenda_id = ? AND a.status = 'pendente' AND (a.funcionario_id IS NULL OR a.funcionario_id = ?)
       AND (a.data_prevista IS NULL OR a.data_prevista <= ?)
     ORDER BY a.recorrente DESC, a.data_prevista, a.id", [$vinculo['fazenda_id'], $fid, hoje()]);

$proximas = todos(
    "SELECT titulo, data_prevista FROM atividades WHERE fazenda_id = ? AND status = 'pendente'
       AND (funcionario_id IS NULL OR funcionario_id = ?) AND data_prevista > ? AND recorrente = 0
     ORDER BY data_prevista LIMIT 5", [$vinculo['fazenda_id'], $fid, hoje()]);

$maquinas = todos('SELECT id, nome, horimetro FROM maquinas WHERE fazenda_id = ? AND ativo = 1 ORDER BY nome', [$vinculo['fazenda_id']]);
?>
<h1 class="titulo-app">Olá, <?= e(explode(' ', $u['nome'])[0]) ?>!</h1>
<p class="suave subtitulo-app"><?= e($vinculo['fazenda_nome']) ?> · <?= e($vinculo['funcao'] ?: 'Funcionário') ?> · <?= data_br(hoje()) ?></p>

<!-- ===== Bater ponto ===== -->
<div class="card ponto-card <?= $aberto ? 'trabalhando' : '' ?>">
  <div>
    <h2>Ponto</h2>
    <?php if ($aberto): ?>
      <p class="ponto-status">🟢 Trabalhando desde <strong><?= hora_br($aberto['entrada']) ?></strong></p>
    <?php else: ?>
      <p class="ponto-status">⚪ Fora do expediente</p>
    <?php endif; ?>
    <p class="suave">Hoje: <?= horas_br($horasHoje) ?> trabalhadas
      <?php foreach ($pontosHoje as $p): ?>
        · <?= hora_br($p['entrada']) ?>–<?= $p['saida'] ? hora_br($p['saida']) : 'agora' ?>
      <?php endforeach; ?>
    </p>
  </div>
  <form method="post">
    <?= csrf_campo() ?>
    <?php if ($aberto): ?>
      <input type="hidden" name="acao" value="saida">
      <button class="botao grande contorno-vermelho">Registrar saída</button>
    <?php else: ?>
      <input type="hidden" name="acao" value="entrada">
      <button class="botao grande verde">Registrar entrada</button>
    <?php endif; ?>
  </form>
</div>

<!-- ===== Relatório do dia ===== -->
<?php if ($relatorioHoje):
    $itens = todos('SELECT * FROM relatorio_itens WHERE relatorio_id = ?', [$relatorioHoje['id']]);
?>
  <div class="card borda-verde">
    <h2>✅ Relatório de hoje enviado</h2>
    <p class="suave">Enviado às <?= hora_br($relatorioHoje['criado_em']) ?>.</p>
    <ul class="lista-simples">
      <?php foreach ($itens as $i): ?>
        <li><?= ['feita' => '✔', 'parcial' => '◐', 'nao_feita' => '✖'][$i['status']] ?> <?= e($i['atividade_titulo']) ?>
          <?= $i['horas'] > 0 ? '<span class="suave">(' . horas_br((float) $i['horas']) . ')</span>' : '' ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($relatorioHoje['observacoes']): ?><p><strong>Observações:</strong> <?= nl2br(e($relatorioHoje['observacoes'])) ?></p><?php endif; ?>
  </div>
<?php else: ?>
  <form method="post" class="card formulario">
    <?= csrf_campo() ?>
    <input type="hidden" name="acao" value="relatorio">
    <h2>Relatório do dia</h2>
    <p class="suave">Marque o que foi feito em cada atividade passada pelo dono.</p>

    <?php if (!$atividades): ?>
      <p class="vazio">Nenhuma atividade para hoje. Se fez algo, descreva em “Outra atividade”.</p>
    <?php endif; ?>

    <?php foreach ($atividades as $a): ?>
      <fieldset class="atividade-form">
        <legend>
          <?= e($a['titulo']) ?>
          <?= $a['tipo'] === 'manutencao' ? '<span class="badge vermelho">Manutenção</span>' : '' ?>
          <?= $a['recorrente'] ? '<span class="badge cinza">Diária</span>' : '' ?>
          <?= !$a['recorrente'] && $a['data_prevista'] && $a['data_prevista'] < hoje() ? '<span class="badge amarelo">Atrasada</span>' : '' ?>
        </legend>
        <?php if ($a['descricao']): ?><p class="suave"><?= nl2br(e($a['descricao'])) ?></p><?php endif; ?>
        <div class="form-grade tres">
          <div class="campo">
            <label>Situação</label>
            <select name="itens[<?= $a['id'] ?>][status]">
              <option value="">— não informar —</option>
              <option value="feita">Feita</option>
              <option value="parcial">Feita em parte</option>
              <option value="nao_feita">Não feita</option>
            </select>
          </div>
          <div class="campo">
            <label>Horas gastas</label>
            <input type="number" step="0.25" min="0" max="24" name="itens[<?= $a['id'] ?>][horas]" placeholder="0">
          </div>
          <?php if ($a['maquina']): ?>
            <div class="campo">
              <label>Horímetro final — <?= e($a['maquina']) ?></label>
              <input type="number" step="0.1" min="0" name="itens[<?= $a['id'] ?>][horimetro]" placeholder="Atual: <?= num_br($a['maquina_horimetro']) ?>">
            </div>
          <?php endif; ?>
        </div>
      </fieldset>
    <?php endforeach; ?>

    <fieldset class="atividade-form">
      <legend>Outra atividade (fora da lista)</legend>
      <div class="form-grade">
        <div class="campo"><label>O que foi feito</label><input name="extra_titulo" placeholder="Ex.: Conserto de cerca"></div>
        <div class="campo"><label>Horas gastas</label><input type="number" step="0.25" min="0" max="24" name="extra_horas"></div>
        <div class="campo">
          <label>Máquina usada</label>
          <select name="extra_maquina">
            <option value="">Nenhuma</option>
            <?php foreach ($maquinas as $m): ?><option value="<?= $m['id'] ?>"><?= e($m['nome']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="campo"><label>Horímetro final</label><input type="number" step="0.1" min="0" name="extra_horimetro"></div>
      </div>
    </fieldset>

    <?php if ($maquinas): ?>
      <fieldset class="atividade-form problema">
        <legend>⚠ Alguma máquina apresentou problema?</legend>
        <div class="form-grade">
          <div class="campo">
            <label>Máquina</label>
            <select name="problema_maquina">
              <option value="">Nenhum problema</option>
              <?php foreach ($maquinas as $m): ?><option value="<?= $m['id'] ?>"><?= e($m['nome']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="campo">
            <label>Gravidade</label>
            <select name="problema_gravidade">
              <option value="baixa">Baixa — dá para usar</option>
              <option value="media" selected>Média — precisa de atenção</option>
              <option value="alta">Alta — máquina parada</option>
            </select>
          </div>
        </div>
        <div class="campo">
          <label>O que aconteceu?</label>
          <textarea name="problema_descricao" rows="2" placeholder="Ex.: vazamento de óleo hidráulico na mangueira traseira"></textarea>
        </div>
      </fieldset>
    <?php endif; ?>

    <div class="campo">
      <label for="observacoes">Observações do dia</label>
      <textarea id="observacoes" name="observacoes" rows="3"></textarea>
    </div>

    <?php if ($aberto): ?>
      <label class="checkbox"><input type="checkbox" name="registrar_saida" checked> Registrar minha saída ao enviar</label>
    <?php else: ?>
      <div class="alerta info">Você não registrou entrada hoje. O relatório será enviado mesmo assim.</div>
    <?php endif; ?>

    <button class="botao grande">Enviar relatório</button>
  </form>
<?php endif; ?>

<?php if ($proximas): ?>
  <div class="card">
    <h2>Próximas atividades</h2>
    <ul class="lista-simples">
      <?php foreach ($proximas as $p): ?><li>📅 <?= data_br($p['data_prevista']) ?> — <?= e($p['titulo']) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
<?php require RAIZ . '/app/rodape.php'; ?>
