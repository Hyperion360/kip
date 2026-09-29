<?php // src/Admin/views/logs.php ?>
<?php $this->layout('layout'); ?>
<h1>Requests</h1>
<form method="get" action="/admin/logs">
  <label>Method
    <select name="method">
      <option value="">any</option>
      <?php foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'] as $m): ?>
        <option value="<?= $this->e($m) ?>"<?= $filters['method'] === $m ? ' selected' : '' ?>><?= $this->e($m) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Status
    <select name="status">
      <option value="">any</option>
      <?php foreach ([2 => '2xx', 3 => '3xx', 4 => '4xx', 5 => '5xx'] as $class => $label): ?>
        <option value="<?= $this->e((string) $class) ?>"<?= $filters['status'] === (string) $class ? ' selected' : '' ?>><?= $this->e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Path prefix<br>
    <input type="text" name="path" value="<?= $this->e($filters['path']) ?>" placeholder="prefix, case-insensitive"></label>
  <label>User id<br>
    <input type="number" name="user_id" min="0" value="<?= $this->e($filters['user_id']) ?>"></label>
  <label><input type="checkbox" name="guests" value="1"<?= $filters['guests'] ? ' checked' : '' ?>> guests only (no user id)</label>
  <p><button>Filter</button> <a href="/admin/logs">reset</a></p>
</form>
<table>
  <tr>
    <th scope="col">id</th><th scope="col">created_at</th><th scope="col">method</th><th scope="col">path</th>
    <th scope="col">status</th><th scope="col">duration_ms</th><th scope="col">ip</th><th scope="col">user_id</th>
  </tr>
  <?php foreach ($rows as $row): ?>
  <tr>
    <?php $path = $this->e($row['path']); ?>
    <td><?= $this->e($row['id']) ?></td>
    <td><?= $this->e($row['created_at']) ?></td>
    <td><?= $this->e($row['method']) ?></td>
    <td title="<?= $path ?>"><?= $path ?></td>
    <td><?= $this->e($row['status']) ?></td>
    <td><?= $this->e(number_format((float) $row['duration_ms'], 1)) ?></td>
    <td><?= $this->e($row['ip']) ?></td>
    <td><?= $row['user_id'] === null ? 'guest' : $this->e($row['user_id']) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php if ($rows === []): ?><p>No requests in this window.</p><?php endif; ?>
<nav class="pager">
  <?php if ($links['prev'] !== null): ?><a href="<?= $this->e($links['prev']) ?>">&larr; Prev</a><?php endif; ?>
  <?php if ($links['next'] !== null): ?><a href="<?= $this->e($links['next']) ?>">Next &rarr;</a><?php endif; ?>
  <?php if ($links['newest'] !== null): ?><a href="<?= $this->e($links['newest']) ?>">Newest</a><?php endif; ?>
</nav>
