<?php
require dirname(__DIR__) . '/app/bootstrap.php';
$u = exigir_login('dono');
$fz = exigir_fazenda();

$valida = fn($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d);
$de = $valida($_GET['de'] ?? '') ? $_GET['de'] : date('Y-m-d', strtotime('-6 days'));
$ate = $valida($_GET['ate'] ?? '') ? $_GET['ate'] : hoje();
$funcFiltro = (int) ($_GET['funcionario'] ?? 0);

$sql = 'SELECT r.*, f.nome, f.funcao FROM relatorios r JOIN funcionarios f ON f.id = r.funcionario_id
        WHERE r.fazenda_id = ? AND r.data BETWEEN ? AND ?';
$params = [$fz['id'], $de, $ate];
if ($funcFiltro) {
    $sql .= ' AND r.funcionario_id = ?';
    $params[] = $funcFiltro;
}
$relatorios = todos($sql . ' ORDER BY r.data DESC, r.criado_em DESC', $params);
$funcionarios = todos('SELECT id, nome FROM funcionarios WHERE fazenda_id = ? ORDER BY nome', [$fz['id']]);

$titulo = 'Relatórios';
$aba = 'relatorios';
require RAIZ . '/app/topo.php';
?>
<h1 class="titulo-app">Relatórios dos funcionários</h1>

<form class="card filtros" method="get">
  <div class="campo"><label for="de">De</label><input type="date" id="de" name="de" value="<?= e($de) ?>"></div>
  <div class="campo"><label for="ate">Até</label><input type="date" id="ate" name="ate" value="<?= e($ate) ?>"></div>
  <div class="campo">
    <label for="funcionario">Funcionário</label>
    <select id="funcionario" name="funcionario">
      <option value="0">Todos</option>
      <?php foreach ($funcionarios as $f): ?>
        <option value="<?= $f['id'] ?>" <?= $funcFiltro === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['nome']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="botao">Filtrar</button>
</form>

<?php if (!$relatorios): ?>
  <div class="card vazio">Nenhum relatório neste período.</div>
<?php endif; ?>

<?php foreach ($relatorios as $r):
    $itens = todos('SELECT i.*, m.nome AS maquina FROM relatorio_itens i LEFT JOIN maquinas m ON m.id = i.maquina_id WHERE i.relatorio_id = ?', [$r['id']]);
    $pontos = todos('SELECT * FROM ponto WHERE funcionario_id = ? AND entrada BETWEEN ? AND ? ORDER BY entrada', [$r['funcionario_id'], $r['data'] . ' 00:00:00', $r['data'] . ' 23:59:59']);
    $problemas = todos('SELECT o.*, m.nome AS maquina FROM ocorrencias o JOIN maquinas m ON m.id = o.maquina_id WHERE o.relatorio_id = ?', [$r['id']]);
    $horasPonto = array_sum(array_map(fn($p) => duracao_ponto($p['entrada'], $p['saida']), $pontos));
?>
  <details class="card relatorio" <?= count($relatorios) <= 3 ? 'open' : '' ?>>
    <summary>
      <strong><?= e($r['nome']) ?></strong>
      <span class="suave"><?= data_br($r['data']) ?> · enviado às <?= hora_br($r['criado_em']) ?> · ponto: <?= $pontos ? horas_br($horasPonto) : 'sem marcação' ?></span>
      <?= $problemas ? '<span class="badge vermelho">' . count($problemas) . ' problema(s)</span>' : '' ?>
    </summary>

    <?php if ($pontos): ?>
      <p class="suave">Marcações: <?= implode(', ', array_map(fn($p) => hora_br($p['entrada']) . '–' . ($p['saida'] ? hora_br($p['saida']) : 'aberto'), $pontos)) ?></p>
    <?php endif; ?>

    <div class="tabela-wrap">
      <table>
        <thead><tr><th>Atividade</th><th>Situação</th><th>Horas</th><th>Máquina</th><th>Horímetro</th></tr></thead>
        <tbody>
        <?php foreach ($itens as $i): ?>
          <tr>
            <td class="quebra"><?= e($i['atividade_titulo']) ?><?= $i['atividade_id'] ? '' : ' <span class="badge cinza">extra</span>' ?></td>
            <td><?= ['feita' => '<span class="badge verde">Feita</span>', 'parcial' => '<span class="badge amarelo">Parcial</span>', 'nao_feita' => '<span class="badge vermelho">Não feita</span>'][$i['status']] ?></td>
            <td><?= horas_br((float) $i['horas']) ?></td>
            <td><?= e($i['maquina'] ?? '—') ?></td>
            <td><?= $i['horimetro'] !== null ? num_br($i['horimetro']) . ' h' : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$itens): ?><tr><td colspan="5" class="suave">Nenhuma atividade informada.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php foreach ($problemas as $o): ?>
      <div class="alerta erro">⚠ <strong><?= e($o['maquina']) ?></strong> (gravidade <?= e($o['gravidade'] === 'media' ? 'média' : $o['gravidade']) ?>): <?= e($o['descricao']) ?>
        — <a href="maquinas.php?ocorrencia=<?= $o['id'] ?>#form-manutencao"><?= $o['status'] === 'aberta' ? 'registrar conserto' : 'resolvido' ?></a></div>
    <?php endforeach; ?>

    <?php if ($r['observacoes']): ?><p><strong>Observações:</strong> <?= nl2br(e($r['observacoes'])) ?></p><?php endif; ?>
  </details>
<?php endforeach; ?>
<?php require RAIZ . '/app/rodape.php'; ?>
