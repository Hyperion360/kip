<?php // src/Admin/views/logs.php
$desc = [];
if ($filters['method'] !== '') $desc[] = $filters['method'];
if ($filters['status'] !== '') $desc[] = $filters['status'] . 'xx';
if ($filters['path'] !== '') $desc[] = $filters['path'];
if ($filters['user_id'] !== '') $desc[] = 'user ' . $filters['user_id'];
if ($filters['guests']) $desc[] = 'guests';
?>
<?php $this->layout('layout'); ?>
<header class="kip-pagehead-intro">
  <p class="kip-kicker">logs.sqlite · read only</p>
  <h1>Requests</h1>
</header>
<details class="kip-filters"<?= $desc === [] ? '' : ' open' ?>>
  <summary class="kip-filters-summary">Filters<?php if ($desc !== []): ?> <span class="kip-filters-active"><?= $this->e(implode(' · ', $desc)) ?></span><?php endif; ?></summary>
  <form class="kip-filters-form" method="get" action="/admin/logs">
    <label>Method
      <select name="method">
        <option value="">Any</option>
        <?php foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'] as $m): ?>
        <option value="<?= $this->e($m) ?>"<?= $filters['method'] === $m ? ' selected' : '' ?>><?= $this->e($m) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Status
      <select name="status">
        <option value="">Any</option>
        <?php foreach ([2 => '2xx', 3 => '3xx', 4 => '4xx', 5 => '5xx'] as $class => $label): ?>
        <option value="<?= $this->e((string) $class) ?>"<?= $filters['status'] === (string) $class ? ' selected' : '' ?>><?= $this->e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Path starts with
      <input type="text" name="path" value="<?= $this->e($filters['path']) ?>" placeholder="/admin">
    </label>
    <label>User id
      <input type="number" name="user_id" min="0" value="<?= $this->e($filters['user_id']) ?>">
    </label>
    <label class="kip-filters-check"><input type="checkbox" name="guests" value="1"<?= $filters['guests'] ? ' checked' : '' ?>> Guests only</label>
    <div class="kip-filters-actions">
      <button type="submit">Filter</button>
      <a href="/admin/logs">Reset</a>
    </div>
  </form>
  <p class="kip-filters-note kip-help">Prefix match, case-insensitive; % and _ match literally. Read only: this page never writes.</p>
</details>
<div class="kip-tablewrap">
<?php if ($rows === []): ?>
  <p class="kip-empty">No requests in this window.</p>
<?php else: ?>
  <table>
    <thead><tr>
      <th scope="col">When</th><th scope="col">Request</th><th scope="col">Status</th>
      <th scope="col" class="kip-right">Time</th><th scope="col">IP</th><th scope="col">User</th>
      <th scope="col" class="kip-right">#</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $row): $s = (int) $row['status']; ?>
      <tr>
        <td data-label="created_at" class="kip-mono"><?= $this->e($row['created_at']) ?></td>
        <td data-label="method"><span class="kip-method"><?= $this->e($row['method']) ?></span><?= $this->e($row['path']) ?></td>
        <td data-label="status"><span class="kip-chip<?= $s < 300 ? '' : ($s < 400 ? ' kip-chip-no' : ($s < 500 ? ' kip-chip-warn' : ' kip-chip-err')) ?>"><?= $s ?></span></td>
        <td data-label="duration_ms" class="kip-mono kip-right"><?= $this->e(number_format((float) $row['duration_ms'], 1)) ?> ms</td>
        <td data-label="ip" class="kip-mono"><?= $this->e($row['ip']) ?></td>
        <td data-label="user_id" class="kip-mono"><?php if ($row['user_id'] === null): ?><span class="kip-nodata">guest</span><?php else: ?><?= $this->e($row['user_id']) ?><?php endif; ?></td>
        <td data-label="id" class="kip-mono kip-right"><?= $this->e($row['id']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<nav class="kip-pager" aria-label="Pages">
  <span>Newest first · <?= $this->e($perPage) ?> per page</span>
  <div class="kip-pager-group">
    <?php if ($links['newest'] !== null): ?><a class="kip-text-link" href="<?= $this->e($links['newest']) ?>">Newest</a><?php endif; ?>
    <?php if ($links['prev'] !== null): ?><a class="kip-pager-link" href="<?= $this->e($links['prev']) ?>">&larr; Newer</a><?php endif; ?>
    <?php if ($links['next'] !== null): ?><a class="kip-pager-link" href="<?= $this->e($links['next']) ?>">Older &rarr;</a><?php endif; ?>
  </div>
</nav>
