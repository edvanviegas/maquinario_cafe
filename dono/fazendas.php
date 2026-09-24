<?php
require dirname(__DIR__) . '/app/bootstrap.php';
$u = exigir_login('dono');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $acao = post('acao');

    if ($acao === 'salvar') {
        $nome = post('nome');
        if ($nome === '') {
            flash('erro', 'Informe o nome da fazenda.');
            redirecionar('dono/fazendas.php');
        }
        $campos = [
            $nome,
            post('municipio') ?: null,
            strtoupper(substr(post('estado'), 0, 2)) ?: null,
            numero_ou_nulo(post('area_ha')),
            numero_ou_nulo(post('producao_sacas')),
        ];
        $id = (int) post('id');
        if ($id) {
            consulta('UPDATE fazendas SET nome = ?, municipio = ?, estado = ?, area_ha = ?, producao_sacas = ? WHERE id = ? AND dono_id = ?',
                array_merge($campos, [$id, $u['id']]));
            flash('sucesso', 'Fazenda atualizada.');
        } else {
            consulta('INSERT INTO fazendas (nome, municipio, estado, area_ha, producao_sacas, dono_id, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)',
                array_merge($campos, [$u['id'], agora()]));
            $_SESSION['fazenda_id'] = ultimo_id();
            flash('sucesso', 'Fazenda cadastrada! Agora cadastre seus funcionários e máquinas.');
            redirecionar('dono/index.php');
        }
    }

    if ($acao === 'selecionar') {
        $_SESSION['fazenda_id'] = (int) post('id');
        redirecionar('dono/index.php');
    }
    redirecionar('dono/fazendas.php');
}

$fazendas = todos(
    'SELECT f.*, (SELECT COUNT(*) FROM funcionarios WHERE fazenda_id = f.id AND ativo = 1) AS total_func,
            (SELECT COUNT(*) FROM maquinas WHERE fazenda_id = f.id AND ativo = 1) AS total_maq
     FROM fazendas f WHERE dono_id = ? ORDER BY nome', [$u['id']]);

$editar = isset($_GET['editar']) ? um('SELECT * FROM fazendas WHERE id = ? AND dono_id = ?', [$_GET['editar'], $u['id']]) : null;
$atual = fazenda_atual();

$titulo = 'Fazendas';
$aba = 'fazendas';
require RAIZ . '/app/topo.php';
?>
<h1 class="titulo-app">Minhas fazendas</h1>

<div class="duas-colunas">
  <div>
    <?php if (!$fazendas): ?>
      <div class="vazio card">Você ainda não cadastrou nenhuma fazenda. Use o formulário ao lado para começar.</div>
    <?php endif; ?>
    <?php foreach ($fazendas as $f): ?>
      <div class="card item-lista <?= $atual && $atual['id'] == $f['id'] ? 'selecionado' : '' ?>">
        <div>
          <h3><?= e($f['nome']) ?></h3>
          <p class="suave">
            <?= e(trim(($f['municipio'] ?? '') . ($f['estado'] ? ' - ' . $f['estado'] : ''))) ?: 'Local não informado' ?>
            <?= $f['area_ha'] ? ' · ' . num_br($f['area_ha']) . ' ha' : '' ?>
            <?= $f['producao_sacas'] ? ' · ' . num_br($f['producao_sacas'], 0) . ' sacas/ano' : '' ?>
          </p>
          <p class="suave"><?= $f['total_func'] ?> funcionário(s) · <?= $f['total_maq'] ?> máquina(s)</p>
        </div>
        <div class="acoes">
          <?php if ($atual && $atual['id'] == $f['id']): ?>
            <span class="badge verde">Em uso</span>
          <?php else: ?>
            <form method="post"><?= csrf_campo() ?>
              <input type="hidden" name="acao" value="selecionar"><input type="hidden" name="id" value="<?= $f['id'] ?>">
              <button class="botao pequeno">Usar esta</button>
            </form>
          <?php endif; ?>
          <a href="?editar=<?= $f['id'] ?>" class="botao pequeno contorno">Editar</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <form method="post" class="card formulario">
    <?= csrf_campo() ?>
    <input type="hidden" name="acao" value="salvar">
    <input type="hidden" name="id" value="<?= e($editar['id'] ?? '') ?>">
    <h2><?= $editar ? 'Editar fazenda' : 'Cadastrar fazenda' ?></h2>
    <div class="campo">
      <label for="nome">Nome da fazenda *</label>
      <input id="nome" name="nome" value="<?= e($editar['nome'] ?? '') ?>" required>
    </div>
    <div class="form-grade">
      <div class="campo">
        <label for="municipio">Município</label>
        <input id="municipio" name="municipio" value="<?= e($editar['municipio'] ?? '') ?>">
      </div>
      <div class="campo">
        <label for="estado">UF</label>
        <input id="estado" name="estado" maxlength="2" value="<?= e($editar['estado'] ?? '') ?>" placeholder="MG">
      </div>
      <div class="campo">
        <label for="area_ha">Área de café (ha)</label>
        <input type="number" step="0.01" min="0" id="area_ha" name="area_ha" value="<?= e($editar['area_ha'] ?? '') ?>">
      </div>
      <div class="campo">
        <label for="producao_sacas">Produção (sacas/ano)</label>
        <input type="number" min="0" id="producao_sacas" name="producao_sacas" value="<?= e($editar['producao_sacas'] ?? '') ?>">
      </div>
    </div>
    <button class="botao"><?= $editar ? 'Salvar alterações' : 'Cadastrar fazenda' ?></button>
    <?php if ($editar): ?><a href="fazendas.php" class="botao contorno">Cancelar</a><?php endif; ?>
  </form>
</div>
<?php require RAIZ . '/app/rodape.php'; ?>
