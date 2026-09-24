<?php
/*
 * Cabeçalho das páginas do sistema.
 * Antes de incluir, defina: $titulo (texto da aba) e, opcionalmente, $aba (aba ativa do painel).
 */
$u = usuario();
$aba = $aba ?? '';

$abasDono = [
    'geral'        => ['dono/index.php',        'Visão geral'],
    'desempenho'   => ['dono/desempenho.php',   'Desempenho'],
    'relatorios'   => ['dono/relatorios.php',   'Relatórios'],
    'atividades'   => ['dono/atividades.php',   'Atividades'],
    'maquinas'     => ['dono/maquinas.php',     'Máquinas'],
    'funcionarios' => ['dono/funcionarios.php', 'Funcionários'],
    'fazendas'     => ['dono/fazendas.php',     'Fazendas'],
];
$abasFuncionario = [
    'dia'       => ['funcionario/index.php',     'Meu dia'],
    'historico' => ['funcionario/historico.php', 'Histórico'],
];
$abas = !$u ? [] : ($u['tipo'] === 'dono' ? $abasDono : $abasFuncionario);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($titulo ?? 'CafeMaq') ?> - CafeMaq</title>
  <link rel="icon" href="<?= url('img/logo.svg') ?>" type="image/svg+xml">
  <link rel="stylesheet" href="<?= url('css/style.css') ?>">
</head>
<body>

  <header>
    <nav class="container nav">
      <a href="<?= url('index.html') ?>" class="logo"><img src="<?= url('img/logo.svg') ?>" alt=""> CafeMaq</a>
      <button class="menu-botao" aria-label="Abrir menu">☰</button>
      <ul class="menu">
        <li><a href="<?= url('index.html') ?>">Início</a></li>
        <li><a href="<?= url('calculadora.html') ?>">Calculadora</a></li>
        <?php if ($u): ?>
          <li><a href="<?= url(pagina_inicial($u)) ?>" class="ativo">Painel</a></li>
          <li><a href="<?= url('sair.php') ?>">Sair (<?= e(explode(' ', $u['nome'])[0]) ?>)</a></li>
        <?php else: ?>
          <li><a href="<?= url('entrar.php') ?>" class="ativo">Entrar</a></li>
        <?php endif; ?>
      </ul>
    </nav>
  </header>

  <?php if ($abas): ?>
    <div class="painel-topo">
      <div class="container">
        <div class="painel-usuario">
          <span class="badge <?= $u['tipo'] === 'dono' ? 'vermelho' : 'verde' ?>"><?= $u['tipo'] === 'dono' ? 'Dono' : 'Funcionário' ?></span>
          <strong><?= e($u['nome']) ?></strong>
          <?php if ($u['tipo'] === 'dono' && ($_fazenda = fazenda_atual())): ?>
            <span class="painel-fazenda">· <?= e($_fazenda['nome']) ?> <a href="<?= url('dono/fazendas.php') ?>">(trocar)</a></span>
          <?php endif; ?>
        </div>
        <nav class="painel-abas">
          <?php foreach ($abas as $_abaChave => $_aba): ?>
            <a href="<?= url($_aba[0]) ?>" class="<?= $_abaChave === $aba ? 'ativo' : '' ?>"><?= $_aba[1] ?></a>
          <?php endforeach; ?>
        </nav>
      </div>
    </div>
  <?php endif; ?>

  <main class="container conteudo-app">
    <?php foreach (pegar_flashes() as $_flash): ?>
      <div class="alerta <?= e($_flash['tipo']) ?>"><?= e($_flash['msg']) ?></div>
    <?php endforeach; ?>
