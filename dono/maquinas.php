<?php
require dirname(__DIR__) . '/app/bootstrap.php';
$u = exigir_login('dono');
$fz = exigir_fazenda();

/* Confere se a máquina é desta fazenda */
function maquina_da_fazenda(int $id, int $fazendaId): ?array
{
    return um('SELECT * FROM maquinas WHERE id = ? AND fazenda_id = ?', [$id, $fazendaId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $acao = post('acao');

    if ($acao === 'salvar_maquina') {
        $tipo = array_key_exists(post('tipo'), TIPOS_MAQUINA) ? post('tipo') : 'outra';
        $campos = [
            post('nome') ?: TIPOS_MAQUINA[$tipo]['nome'],
            $tipo,
            post('modelo') ?: null,
            numero_ou_nulo(post('ano')),
            numero_ou_nulo(post('horimetro')) ?? 0,
            numero_ou_nulo(post('preco')),
        ];
        $id = (int) post('id');
        if ($id && maquina_da_fazenda($id, (int) $fz['id'])) {
            consulta('UPDATE maquinas SET nome = ?, tipo = ?, modelo = ?, ano = ?, horimetro = ?, preco = ? WHERE id = ?', array_merge($campos, [$id]));
            flash('sucesso', 'Máquina atualizada.');
        } else {
            consulta('INSERT INTO maquinas (nome, tipo, modelo, ano, horimetro, preco, fazenda_id, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                array_merge($campos, [$fz['id'], agora()]));
            flash('sucesso', 'Máquina cadastrada.');
        }
    }

    if ($acao === 'manutencao') {
        $maq = maquina_da_fazenda((int) post('maquina_id'), (int) $fz['id']);
        if (!$maq || post('descricao') === '') {
            flash('erro', 'Escolha a máquina e descreva o serviço.');
            redirecionar('dono/maquinas.php');
        }
        $horimetro = numero_ou_nulo(post('horimetro'));
        $ocorrencia = um('SELECT id FROM ocorrencias WHERE id = ? AND fazenda_id = ?', [(int) post('ocorrencia_id'), $fz['id']]);

        consulta(
            'INSERT INTO manutencoes (fazenda_id, maquina_id, data, tipo, descricao, custo, horimetro, ocorrencia_id, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$fz['id'], $maq['id'], post('data') ?: hoje(), post('tipo') === 'corretiva' ? 'corretiva' : 'preventiva',
             post('descricao'), numero_ou_nulo(post('custo')) ?? 0, $horimetro, $ocorrencia['id'] ?? null, agora()]
        );
        if ($horimetro !== null && $horimetro > (float) $maq['horimetro']) {
            consulta('UPDATE maquinas SET horimetro = ? WHERE id = ?', [$horimetro, $maq['id']]);
        }
        if ($ocorrencia) {
            consulta("UPDATE ocorrencias SET status = 'resolvida', resolvida_em = ? WHERE id = ?", [agora(), $ocorrencia['id']]);
        }
        flash('sucesso', 'Manutenção registrada.');
    }

    if ($acao === 'resolver') {
        consulta("UPDATE ocorrencias SET status = 'resolvida', resolvida_em = ? WHERE id = ? AND fazenda_id = ?", [agora(), (int) post('id'), $fz['id']]);
        flash('sucesso', 'Ocorrência marcada como resolvida.');
    }

    if ($acao === 'ativo') {
        consulta('UPDATE maquinas SET ativo = ? WHERE id = ? AND fazenda_id = ?', [(int) post('valor'), (int) post('id'), $fz['id']]);
    }
    redirecionar('dono/maquinas.php');
}

$umAnoAtras = date('Y-m-d', strtotime('-12 months'));
$maquinas = todos(
    "SELECT m.*,
            (SELECT MAX(horimetro) FROM manutencoes WHERE maquina_id = m.id AND tipo = 'preventiva') AS ultima_preventiva,
            (SELECT COALESCE(SUM(custo), 0) FROM manutencoes WHERE maquina_id = m.id AND data >= ?) AS gasto_ano,
            (SELECT COUNT(*) FROM ocorrencias WHERE maquina_id = m.id AND status = 'aberta') AS abertas
     FROM maquinas m WHERE fazenda_id = ? ORDER BY ativo DESC, nome", [$umAnoAtras, $fz['id']]);

$ocorrencias = todos(
    "SELECT o.*, m.nome AS maquina, f.nome AS funcionario FROM ocorrencias o
     JOIN maquinas m ON m.id = o.maquina_id LEFT JOIN funcionarios f ON f.id = o.funcionario_id
     WHERE o.fazenda_id = ? AND o.status = 'aberta'
     ORDER BY CASE o.gravidade WHEN 'alta' THEN 1 WHEN 'media' THEN 2 ELSE 3 END, o.criado_em DESC", [$fz['id']]);

$historico = todos(
    'SELECT mn.*, m.nome AS maquina FROM manutencoes mn JOIN maquinas m ON m.id = mn.maquina_id
     WHERE mn.fazenda_id = ? ORDER BY mn.data DESC, mn.id DESC LIMIT 30', [$fz['id']]);

$editar = isset($_GET['editar']) ? maquina_da_fazenda((int) $_GET['editar'], (int) $fz['id']) : null;
$ocorrenciaSel = isset($_GET['ocorrencia']) ? um('SELECT * FROM ocorrencias WHERE id = ? AND fazenda_id = ?', [(int) $_GET['ocorrencia'], $fz['id']]) : null;
$ativas = array_filter($maquinas, fn($m) => $m['ativo']);

$titulo = 'Máquinas';
$aba = 'maquinas';
require RAIZ . '/app/topo.php';
?>
<h1 class="titulo-app">Máquinas e manutenção</h1>

<?php if ($ocorrencias): ?>
  <div class="card borda-vermelha">
    <h2>⚠ Problemas relatados pelos funcionários</h2>
    <?php foreach ($ocorrencias as $o): ?>
      <div class="ocorrencia">
        <div>
          <span class="badge <?= ['alta' => 'vermelho', 'media' => 'amarelo', 'baixa' => 'cinza'][$o['gravidade']] ?>">Gravidade <?= e($o['gravidade'] === 'media' ? 'média' : $o['gravidade']) ?></span>
          <strong><?= e($o['maquina']) ?></strong>
          <p><?= nl2br(e($o['descricao'])) ?></p>
          <small class="suave">Relatado por <?= e($o['funcionario'] ?? '—') ?> em <?= data_br($o['criado_em']) ?> às <?= hora_br($o['criado_em']) ?></small>
        </div>
        <div class="acoes">
          <a href="?ocorrencia=<?= $o['id'] ?>#form-manutencao" class="botao pequeno">Registrar conserto</a>
          <form method="post"><?= csrf_campo() ?>
            <input type="hidden" name="acao" value="resolver"><input type="hidden" name="id" value="<?= $o['id'] ?>">
            <button class="botao pequeno contorno">Marcar resolvido</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card">
  <h2>Máquinas da fazenda</h2>
  <?php if (!$maquinas): ?>
    <p class="vazio">Nenhuma máquina cadastrada. Use o formulário abaixo.</p>
  <?php else: ?>
    <div class="tabela-wrap">
      <table>
        <thead><tr><th>Máquina</th><th>Horímetro</th><th>Revisão preventiva</th><th>Gasto (12 meses)</th><th>Problemas</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($maquinas as $m):
            $intervalo = TIPOS_MAQUINA[$m['tipo']]['revisao'] ?? 250;
            $desde = $m['ultima_preventiva'] !== null ? (float) $m['horimetro'] - (float) $m['ultima_preventiva'] : null;
        ?>
          <tr class="<?= $m['ativo'] ? '' : 'inativo' ?>">
            <td>
              <strong><?= e($m['nome']) ?></strong><br>
              <small class="suave"><?= e(TIPOS_MAQUINA[$m['tipo']]['nome'] ?? $m['tipo']) ?><?= $m['modelo'] ? ' · ' . e($m['modelo']) : '' ?><?= $m['ano'] ? ' · ' . (int) $m['ano'] : '' ?></small>
            </td>
            <td><?= num_br($m['horimetro']) ?> h</td>
            <td>
              <?php if ($desde === null): ?>
                <span class="badge amarelo">Sem revisão registrada</span>
              <?php elseif ($desde >= $intervalo): ?>
                <span class="badge vermelho">Vencida (<?= num_br($desde, 0) ?> h de <?= $intervalo ?> h)</span>
              <?php else: ?>
                <span class="badge verde">Em dia · faltam <?= num_br($intervalo - $desde, 0) ?> h</span>
              <?php endif; ?>
            </td>
            <td><?= reais($m['gasto_ano']) ?></td>
            <td><?= $m['abertas'] ? '<span class="badge vermelho">' . $m['abertas'] . ' aberto(s)</span>' : '<span class="suave">—</span>' ?></td>
            <td class="acoes">
              <a href="?editar=<?= $m['id'] ?>#form-maquina" class="botao pequeno contorno">Editar</a>
              <form method="post"><?= csrf_campo() ?>
                <input type="hidden" name="acao" value="ativo"><input type="hidden" name="id" value="<?= $m['id'] ?>">
                <input type="hidden" name="valor" value="<?= $m['ativo'] ? 0 : 1 ?>">
                <button class="botao pequeno contorno"><?= $m['ativo'] ? 'Desativar' : 'Reativar' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="duas-colunas iguais">
  <form method="post" class="card formulario" id="form-manutencao">
    <?= csrf_campo() ?>
    <input type="hidden" name="acao" value="manutencao">
    <input type="hidden" name="ocorrencia_id" value="<?= e($ocorrenciaSel['id'] ?? '') ?>">
    <h2>Registrar manutenção</h2>
    <?php if ($ocorrenciaSel): ?>
      <div class="alerta info">Resolvendo o problema: “<?= e($ocorrenciaSel['descricao']) ?>”</div>
    <?php endif; ?>
    <div class="campo">
      <label for="maquina_id">Máquina *</label>
      <select id="maquina_id" name="maquina_id" required>
        <option value="">Selecione…</option>
        <?php foreach ($ativas as $m): ?>
          <option value="<?= $m['id'] ?>" <?= ($ocorrenciaSel['maquina_id'] ?? null) == $m['id'] ? 'selected' : '' ?>><?= e($m['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-grade">
      <div class="campo">
        <label for="tipo_m">Tipo</label>
        <select id="tipo_m" name="tipo">
          <option value="preventiva">Preventiva</option>
          <option value="corretiva" <?= $ocorrenciaSel ? 'selected' : '' ?>>Corretiva (conserto)</option>
        </select>
      </div>
      <div class="campo">
        <label for="data">Data</label>
        <input type="date" id="data" name="data" value="<?= hoje() ?>">
      </div>
      <div class="campo">
        <label for="custo">Custo (R$)</label>
        <input type="number" step="0.01" min="0" id="custo" name="custo" placeholder="0,00">
      </div>
      <div class="campo">
        <label for="horimetro_m">Horímetro</label>
        <input type="number" step="0.1" min="0" id="horimetro_m" name="horimetro">
      </div>
    </div>
    <div class="campo">
      <label for="descricao">Serviço realizado *</label>
      <textarea id="descricao" name="descricao" rows="3" required placeholder="Ex.: troca de óleo e filtros"></textarea>
    </div>
    <button class="botao">Registrar</button>
  </form>

  <form method="post" class="card formulario" id="form-maquina">
    <?= csrf_campo() ?>
    <input type="hidden" name="acao" value="salvar_maquina">
    <input type="hidden" name="id" value="<?= e($editar['id'] ?? '') ?>">
    <h2><?= $editar ? 'Editar máquina' : 'Cadastrar máquina' ?></h2>
    <div class="form-grade">
      <div class="campo">
        <label for="tipo">Tipo *</label>
        <select id="tipo" name="tipo">
          <?php foreach (TIPOS_MAQUINA as $chave => $t): ?>
            <option value="<?= $chave ?>" <?= ($editar['tipo'] ?? '') === $chave ? 'selected' : '' ?>><?= e($t['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label for="nome">Apelido / identificação</label>
        <input id="nome" name="nome" value="<?= e($editar['nome'] ?? '') ?>" placeholder="Ex.: Trator 02">
      </div>
      <div class="campo">
        <label for="modelo">Marca / modelo</label>
        <input id="modelo" name="modelo" value="<?= e($editar['modelo'] ?? '') ?>">
      </div>
      <div class="campo">
        <label for="ano">Ano</label>
        <input type="number" min="1950" max="2100" id="ano" name="ano" value="<?= e($editar['ano'] ?? '') ?>">
      </div>
      <div class="campo">
        <label for="horimetro">Horímetro atual (h)</label>
        <input type="number" step="0.1" min="0" id="horimetro" name="horimetro" value="<?= e($editar['horimetro'] ?? '0') ?>">
      </div>
      <div class="campo">
        <label for="preco">Valor (R$)</label>
        <input type="number" step="0.01" min="0" id="preco" name="preco" value="<?= e($editar['preco'] ?? '') ?>">
      </div>
    </div>
    <button class="botao"><?= $editar ? 'Salvar alterações' : 'Cadastrar máquina' ?></button>
    <?php if ($editar): ?><a href="maquinas.php" class="botao contorno">Cancelar</a><?php endif; ?>
  </form>
</div>

<div class="card">
  <h2>Histórico de manutenções</h2>
  <?php if (!$historico): ?>
    <p class="vazio">Nenhuma manutenção registrada.</p>
  <?php else: ?>
    <div class="tabela-wrap">
      <table>
        <thead><tr><th>Data</th><th>Máquina</th><th>Tipo</th><th>Serviço</th><th>Horímetro</th><th>Custo</th></tr></thead>
        <tbody>
        <?php foreach ($historico as $h): ?>
          <tr>
            <td><?= data_br($h['data']) ?></td>
            <td><?= e($h['maquina']) ?></td>
            <td><span class="badge <?= $h['tipo'] === 'preventiva' ? 'verde' : 'vermelho' ?>"><?= ucfirst($h['tipo']) ?></span></td>
            <td class="quebra"><?= e($h['descricao']) ?></td>
            <td><?= $h['horimetro'] !== null ? num_br($h['horimetro']) . ' h' : '—' ?></td>
            <td><?= reais($h['custo']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require RAIZ . '/app/rodape.php'; ?>
