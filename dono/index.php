<?php
require dirname(__DIR__) . '/app/bootstrap.php';
$u = exigir_login('dono');
$fz = exigir_fazenda();
$id = $fz['id'];

$trabalhando = todos(
    'SELECT f.nome, f.funcao, p.entrada FROM ponto p JOIN funcionarios f ON f.id = p.funcionario_id
     WHERE p.fazenda_id = ? AND p.saida IS NULL ORDER BY p.entrada', [$id]);
$totalFunc = (int) valor('SELECT COUNT(*) FROM funcionarios WHERE fazenda_id = ? AND ativo = 1', [$id]);
$relatoriosHoje = (int) valor('SELECT COUNT(*) FROM relatorios WHERE fazenda_id = ? AND data = ?', [$id, hoje()]);
$pendentes = (int) valor("SELECT COUNT(*) FROM atividades WHERE fazenda_id = ? AND status = 'pendente'", [$id]);
$problemas = (int) valor("SELECT COUNT(*) FROM ocorrencias WHERE fazenda_id = ? AND status = 'aberta'", [$id]);
$totalMaq = (int) valor('SELECT COUNT(*) FROM maquinas WHERE fazenda_id = ? AND ativo = 1', [$id]);
$gastoMes = (float) valor('SELECT COALESCE(SUM(custo), 0) FROM manutencoes WHERE fazenda_id = ? AND data >= ?', [$id, date('Y-m-01')]);

$ultimos = todos(
    "SELECT r.data, r.criado_em, f.nome, COUNT(i.id) AS total, SUM(CASE WHEN i.status = 'feita' THEN 1 ELSE 0 END) AS feitas
     FROM relatorios r JOIN funcionarios f ON f.id = r.funcionario_id LEFT JOIN relatorio_itens i ON i.relatorio_id = r.id
     WHERE r.fazenda_id = ? GROUP BY r.id, r.data, r.criado_em, f.nome ORDER BY r.criado_em DESC LIMIT 6", [$id]);

$primeirosPassos = [
    ['Cadastrar a fazenda', true, 'fazendas.php'],
    ['Cadastrar as máquinas', $totalMaq > 0, 'maquinas.php'],
    ['Cadastrar funcionários', $totalFunc > 0, 'funcionarios.php'],
    ['Criar a primeira atividade', (int) valor('SELECT COUNT(*) FROM atividades WHERE fazenda_id = ?', [$id]) > 0, 'atividades.php'],
];
$faltaPasso = in_array(false, array_column($primeirosPassos, 1), true);

$titulo = 'Painel';
$aba = 'geral';
require RAIZ . '/app/topo.php';
?>
<h1 class="titulo-app">Visão geral</h1>
<p class="suave subtitulo-app"><?= e($fz['nome']) ?> · <?= data_br(hoje()) ?></p>

<?php if ($faltaPasso): ?>
  <div class="card borda-verde">
    <h2>Primeiros passos</h2>
    <ol class="passos">
      <?php foreach ($primeirosPassos as [$texto, $feito, $link]): ?>
        <li class="<?= $feito ? 'feito' : '' ?>"><?= $feito ? '✔' : '○' ?> <a href="<?= $link ?>"><?= $texto ?></a></li>
      <?php endforeach; ?>
    </ol>
  </div>
<?php endif; ?>

<div class="kpis">
  <div class="kpi"><span>Trabalhando agora</span><strong><?= count($trabalhando) ?><small> / <?= $totalFunc ?></small></strong></div>
  <div class="kpi"><span>Relatórios hoje</span><strong><?= $relatoriosHoje ?></strong></div>
  <a class="kpi" href="atividades.php"><span>Atividades pendentes</span><strong><?= $pendentes ?></strong></a>
  <a class="kpi <?= $problemas ? 'alerta-kpi' : '' ?>" href="maquinas.php"><span>Problemas em máquinas</span><strong><?= $problemas ?></strong></a>
  <div class="kpi"><span>Manutenção no mês</span><strong class="menor"><?= reais($gastoMes) ?></strong></div>
</div>

<div class="duas-colunas iguais">
  <div class="card">
    <h2>Em serviço agora</h2>
    <?php if (!$trabalhando): ?><p class="vazio">Ninguém com ponto aberto no momento.</p><?php endif; ?>
    <ul class="lista-simples">
      <?php foreach ($trabalhando as $t): ?>
        <li>🟢 <strong><?= e($t['nome']) ?></strong> <span class="suave"><?= e($t['funcao'] ?? '') ?> · desde <?= hora_br($t['entrada']) ?> (<?= horas_br(duracao_ponto($t['entrada'], null)) ?>)</span></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="card">
    <h2>Últimos relatórios</h2>
    <?php if (!$ultimos): ?><p class="vazio">Nenhum relatório recebido ainda.</p><?php endif; ?>
    <ul class="lista-simples">
      <?php foreach ($ultimos as $r): ?>
        <li><strong><?= e($r['nome']) ?></strong> <span class="suave">· <?= data_br($r['data']) ?> às <?= hora_br($r['criado_em']) ?> · <?= (int) $r['feitas'] ?>/<?= (int) $r['total'] ?> atividades feitas</span></li>
      <?php endforeach; ?>
    </ul>
    <p><a href="relatorios.php">Ver todos os relatórios →</a></p>
  </div>
</div>
<?php require RAIZ . '/app/rodape.php'; ?>
