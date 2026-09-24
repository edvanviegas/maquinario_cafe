<?php
require dirname(__DIR__) . '/app/bootstrap.php';
$u = exigir_login('funcionario');
$vinculo = vinculo_atual();
if (!$vinculo) {
    redirecionar('funcionario/index.php');
}

$inicio = date('Y-m-d', strtotime('-30 days'));
$pontos = todos('SELECT * FROM ponto WHERE funcionario_id = ? AND entrada >= ? ORDER BY entrada DESC', [$vinculo['id'], $inicio . ' 00:00:00']);
$relatorios = todos(
    "SELECT r.*, COUNT(i.id) AS total, SUM(CASE WHEN i.status = 'feita' THEN 1 ELSE 0 END) AS feitas, COALESCE(SUM(i.horas), 0) AS horas
     FROM relatorios r LEFT JOIN relatorio_itens i ON i.relatorio_id = r.id
     WHERE r.funcionario_id = ? AND r.data >= ? GROUP BY r.id ORDER BY r.data DESC", [$vinculo['id'], $inicio]);

// Agrupa as marcações de ponto por dia
$dias = [];
foreach ($pontos as $p) {
    $dia = substr($p['entrada'], 0, 10);
    $dias[$dia]['marcacoes'][] = $p;
    $dias[$dia]['horas'] = ($dias[$dia]['horas'] ?? 0) + duracao_ponto($p['entrada'], $p['saida']);
}
$totalHoras = array_sum(array_column($dias, 'horas'));

$titulo = 'Histórico';
$aba = 'historico';
require RAIZ . '/app/topo.php';
?>
<h1 class="titulo-app">Meu histórico</h1>
<p class="suave subtitulo-app">Últimos 30 dias · <?= count($dias) ?> dia(s) trabalhados · <?= horas_br($totalHoras) ?> no total</p>

<div class="duas-colunas iguais">
  <div class="card">
    <h2>Ponto</h2>
    <?php if (!$dias): ?><p class="vazio">Nenhuma marcação nos últimos 30 dias.</p><?php endif; ?>
    <div class="tabela-wrap">
      <table>
        <?php if ($dias): ?><thead><tr><th>Dia</th><th>Marcações</th><th>Total</th></tr></thead><?php endif; ?>
        <tbody>
        <?php foreach ($dias as $dia => $d): ?>
          <tr>
            <td><?= data_br($dia) ?></td>
            <td><?= implode(', ', array_map(fn($p) => hora_br($p['entrada']) . '–' . ($p['saida'] ? hora_br($p['saida']) : 'aberto'), array_reverse($d['marcacoes']))) ?></td>
            <td><strong><?= horas_br($d['horas']) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h2>Relatórios enviados</h2>
    <?php if (!$relatorios): ?><p class="vazio">Nenhum relatório nos últimos 30 dias.</p><?php endif; ?>
    <div class="tabela-wrap">
      <table>
        <?php if ($relatorios): ?><thead><tr><th>Dia</th><th>Atividades feitas</th><th>Horas</th></tr></thead><?php endif; ?>
        <tbody>
        <?php foreach ($relatorios as $r): ?>
          <tr>
            <td><?= data_br($r['data']) ?></td>
            <td><?= (int) $r['feitas'] ?> de <?= (int) $r['total'] ?></td>
            <td><?= horas_br((float) $r['horas']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require RAIZ . '/app/rodape.php'; ?>
