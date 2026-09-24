<?php
require dirname(__DIR__) . '/app/bootstrap.php';
$u = exigir_login('dono');
$fz = exigir_fazenda();
$id = $fz['id'];

/* ===== Período escolhido: dia, semana ou mês ===== */
$periodo = in_array($_GET['p'] ?? '', ['dia', 'semana', 'mes'], true) ? $_GET['p'] : 'dia';
$ref = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['d'] ?? '') ? $_GET['d'] : hoje();
$ts = strtotime($ref);

$diasSemana = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
$meses = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

if ($periodo === 'dia') {
    $ini = $fim = date('Y-m-d', $ts);
    $anterior = date('Y-m-d', strtotime('-1 day', $ts));
    $proximo = date('Y-m-d', strtotime('+1 day', $ts));
    $rotulo = ucfirst($diasSemana[date('w', $ts)]) . ', ' . date('d/m/Y', $ts);
} elseif ($periodo === 'semana') {
    $ini = date('Y-m-d', strtotime('-' . (date('N', $ts) - 1) . ' days', $ts));   // segunda-feira
    $fim = date('Y-m-d', strtotime('+6 days', strtotime($ini)));
    $anterior = date('Y-m-d', strtotime('-7 days', strtotime($ini)));
    $proximo = date('Y-m-d', strtotime('+7 days', strtotime($ini)));
    $rotulo = 'Semana de ' . date('d/m', strtotime($ini)) . ' a ' . date('d/m/Y', strtotime($fim));
} else {
    $ini = date('Y-m-01', $ts);
    $fim = date('Y-m-t', $ts);
    $anterior = date('Y-m-d', strtotime('-1 month', strtotime($ini)));
    $proximo = date('Y-m-d', strtotime('+1 month', strtotime($ini)));
    $rotulo = ucfirst($meses[(int) date('n', $ts)]) . ' de ' . date('Y', $ts);
}

/* ===== Dados do período ===== */
$funcionarios = todos('SELECT id, nome, funcao, ativo FROM funcionarios WHERE fazenda_id = ? ORDER BY nome', [$id]);
$pontos = todos('SELECT funcionario_id, entrada, saida FROM ponto WHERE fazenda_id = ? AND entrada BETWEEN ? AND ?', [$id, "$ini 00:00:00", "$fim 23:59:59"]);
$itens = todos(
    'SELECT r.id AS relatorio_id, r.funcionario_id, r.data, i.status, i.horas, i.maquina_id
     FROM relatorios r LEFT JOIN relatorio_itens i ON i.relatorio_id = r.id
     WHERE r.fazenda_id = ? AND r.data BETWEEN ? AND ?', [$id, $ini, $fim]);
$ocorrencias = todos('SELECT funcionario_id FROM ocorrencias WHERE fazenda_id = ? AND criado_em BETWEEN ? AND ?', [$id, "$ini 00:00:00", "$fim 23:59:59"]);
$manut = um("SELECT COUNT(*) AS qtd, COALESCE(SUM(custo), 0) AS custo,
                    SUM(CASE WHEN tipo = 'preventiva' THEN 1 ELSE 0 END) AS preventivas
             FROM manutencoes WHERE fazenda_id = ? AND data BETWEEN ? AND ?", [$id, $ini, $fim]);
$usoMaquinas = todos(
    'SELECT m.nome, SUM(i.horas) AS horas, COUNT(DISTINCT r.id) AS dias
     FROM relatorio_itens i JOIN relatorios r ON r.id = i.relatorio_id JOIN maquinas m ON m.id = i.maquina_id
     WHERE r.fazenda_id = ? AND r.data BETWEEN ? AND ? GROUP BY m.id, m.nome ORDER BY horas DESC', [$id, $ini, $fim]);

/* ===== Consolida por funcionário ===== */
$porFunc = [];
foreach ($funcionarios as $f) {
    $porFunc[$f['id']] = $f + ['horas' => 0.0, 'dias' => [], 'relatorios' => [], 'feita' => 0, 'parcial' => 0, 'nao_feita' => 0, 'horas_ativ' => 0.0, 'problemas' => 0];
}
$horasPorDia = [];
foreach ($pontos as $p) {
    $h = duracao_ponto($p['entrada'], $p['saida']);
    $dia = substr($p['entrada'], 0, 10);
    $porFunc[$p['funcionario_id']]['horas'] += $h;
    $porFunc[$p['funcionario_id']]['dias'][$dia] = true;
    $horasPorDia[$dia] = ($horasPorDia[$dia] ?? 0) + $h;
}
foreach ($itens as $i) {
    $f = &$porFunc[$i['funcionario_id']];
    $f['relatorios'][$i['relatorio_id']] = true;
    if ($i['status']) {
        $f[$i['status']]++;
        $f['horas_ativ'] += (float) $i['horas'];
    }
    unset($f);
}
foreach ($ocorrencias as $o) {
    if ($o['funcionario_id'] && isset($porFunc[$o['funcionario_id']])) {
        $porFunc[$o['funcionario_id']]['problemas']++;
    }
}
// Mostra só quem está ativo ou teve movimento no período
$porFunc = array_filter($porFunc, fn($f) => $f['ativo'] || $f['horas'] > 0 || $f['relatorios']);
usort($porFunc, fn($a, $b) => $b['horas'] <=> $a['horas']);

$tot = [
    'horas' => array_sum(array_column($porFunc, 'horas')),
    'feita' => array_sum(array_column($porFunc, 'feita')),
    'parcial' => array_sum(array_column($porFunc, 'parcial')),
    'nao_feita' => array_sum(array_column($porFunc, 'nao_feita')),
    'relatorios' => array_sum(array_map(fn($f) => count($f['relatorios']), $porFunc)),
    'dias_trab' => array_sum(array_map(fn($f) => count($f['dias']), $porFunc)),
];
$totItens = $tot['feita'] + $tot['parcial'] + $tot['nao_feita'];
$taxa = $totItens ? $tot['feita'] / $totItens * 100 : null;

/* Gráfico: horas por dia (semana/mês) ou por funcionário (dia) */
$grafico = [];
if ($periodo === 'dia') {
    foreach ($porFunc as $f) $grafico[] = [explode(' ', $f['nome'])[0], $f['horas']];
} else {
    for ($d = strtotime($ini); $d <= strtotime($fim); $d = strtotime('+1 day', $d)) {
        $grafico[] = [$periodo === 'semana' ? ucfirst(mb_substr($diasSemana[date('w', $d)], 0, 3)) . ' ' . date('d', $d) : date('d', $d), $horasPorDia[date('Y-m-d', $d)] ?? 0];
    }
}
$maxGrafico = max(1, ...(array_column($grafico, 1) ?: [1]));
$maxHorasFunc = max(1, ...(array_column($porFunc, 'horas') ?: [1]));

$linkPeriodo = fn($p, $d) => '?p=' . $p . '&d=' . $d;

$titulo = 'Desempenho';
$aba = 'desempenho';
require RAIZ . '/app/topo.php';
?>
<h1 class="titulo-app">Resumo de desempenho</h1>

<div class="barra-periodo card">
  <div class="seletor">
    <?php foreach (['dia' => 'Diário', 'semana' => 'Semanal', 'mes' => 'Mensal'] as $chave => $nome): ?>
      <a href="<?= $linkPeriodo($chave, $ref) ?>" class="<?= $periodo === $chave ? 'ativo' : '' ?>"><?= $nome ?></a>
    <?php endforeach; ?>
  </div>
  <div class="navegacao">
    <a href="<?= $linkPeriodo($periodo, $anterior) ?>" class="botao pequeno contorno" aria-label="Período anterior">‹</a>
    <strong><?= $rotulo ?></strong>
    <a href="<?= $linkPeriodo($periodo, $proximo) ?>" class="botao pequeno contorno" aria-label="Próximo período">›</a>
    <?php if ($ref !== hoje()): ?><a href="<?= $linkPeriodo($periodo, hoje()) ?>" class="botao pequeno">Hoje</a><?php endif; ?>
  </div>
</div>

<div class="kpis">
  <div class="kpi"><span>Horas trabalhadas</span><strong><?= horas_br($tot['horas']) ?></strong></div>
  <div class="kpi"><span>Atividades feitas</span><strong><?= $tot['feita'] ?><small> + <?= $tot['parcial'] ?> parciais</small></strong></div>
  <div class="kpi"><span>Taxa de conclusão</span><strong><?= $taxa === null ? '—' : num_br($taxa, 0) . '%' ?></strong></div>
  <div class="kpi"><span>Relatórios entregues</span><strong><?= $tot['relatorios'] ?><small> / <?= $tot['dias_trab'] ?> dias trab.</small></strong></div>
  <div class="kpi <?= count($ocorrencias) ? 'alerta-kpi' : '' ?>"><span>Problemas relatados</span><strong><?= count($ocorrencias) ?></strong></div>
  <div class="kpi"><span>Custo de manutenção</span><strong class="menor"><?= reais($manut['custo']) ?></strong><small><?= (int) $manut['qtd'] ?> serviço(s), <?= (int) $manut['preventivas'] ?> preventiva(s)</small></div>
</div>

<div class="card">
  <h2><?= $periodo === 'dia' ? 'Horas trabalhadas por funcionário' : 'Horas trabalhadas por dia (equipe)' ?></h2>
  <?php if ($tot['horas'] <= 0): ?>
    <p class="vazio">Nenhuma marcação de ponto neste período.</p>
  <?php else: ?>
    <div class="grafico-colunas <?= count($grafico) > 20 ? 'denso' : '' ?>">
      <?php foreach ($grafico as [$nome, $horas]): ?>
        <div class="coluna" title="<?= e($nome) ?>: <?= horas_br($horas) ?>">
          <span class="valor"><?= $horas > 0 ? num_br($horas, 1) : '' ?></span>
          <div class="barra-vertical" style="height: <?= round($horas / $maxGrafico * 100) ?>%"></div>
          <span class="rotulo"><?= e($nome) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Desempenho por funcionário</h2>
  <?php if (!$porFunc): ?>
    <p class="vazio">Nenhum funcionário cadastrado.</p>
  <?php else: ?>
    <div class="tabela-wrap">
      <table>
        <thead>
          <tr><th>Funcionário</th><th>Horas (ponto)</th><th></th><th>Dias</th><th>Relatórios</th><th>Feitas</th><th>Parciais</th><th>Não feitas</th><th>Conclusão</th><th>Problemas</th></tr>
        </thead>
        <tbody>
        <?php foreach ($porFunc as $f):
            $total = $f['feita'] + $f['parcial'] + $f['nao_feita'];
            $conc = $total ? $f['feita'] / $total * 100 : null;
            $semRelatorio = count($f['dias']) - count($f['relatorios']);
        ?>
          <tr>
            <td><strong><?= e($f['nome']) ?></strong><br><small class="suave"><?= e($f['funcao'] ?? '') ?></small></td>
            <td><?= horas_br($f['horas']) ?></td>
            <td style="min-width:120px"><div class="barra" style="width: <?= round($f['horas'] / $maxHorasFunc * 100) ?>%"></div></td>
            <td><?= count($f['dias']) ?></td>
            <td><?= count($f['relatorios']) ?><?= $semRelatorio > 0 ? ' <span class="badge amarelo" title="Dias com ponto e sem relatório">' . $semRelatorio . ' pendente(s)</span>' : '' ?></td>
            <td><?= $f['feita'] ?></td>
            <td><?= $f['parcial'] ?></td>
            <td><?= $f['nao_feita'] ?></td>
            <td><?php if ($conc === null): ?>—<?php else: ?>
              <span class="badge <?= $conc >= 80 ? 'verde' : ($conc >= 50 ? 'amarelo' : 'vermelho') ?>"><?= num_br($conc, 0) ?>%</span><?php endif; ?></td>
            <td><?= $f['problemas'] ?: '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Uso das máquinas</h2>
  <?php if (!$usoMaquinas): ?>
    <p class="vazio">Nenhuma máquina informada nos relatórios deste período.</p>
  <?php else: ?>
    <div class="tabela-wrap">
      <table>
        <thead><tr><th>Máquina</th><th>Horas relatadas</th><th>Relatórios</th></tr></thead>
        <tbody>
        <?php foreach ($usoMaquinas as $m): ?>
          <tr><td><?= e($m['nome']) ?></td><td><?= horas_br((float) $m['horas']) ?></td><td><?= (int) $m['dias'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require RAIZ . '/app/rodape.php'; ?>
