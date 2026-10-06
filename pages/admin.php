<?php
$title = 'Manajemen Data & Pengguna';
$action = (string)($_GET['a'] ?? '');
deny_unless(can('admin'), 'Hanya Admin.');

$tabs = ['users' => 'Pengguna & role', 'risk' => 'Daftar risiko', 'supplier' => 'Supplier', 'product' => 'Produk', 'settings' => 'Threshold & konfigurasi'];
$tab = (string)($_GET['tab'] ?? 'users');
if (!isset($tabs[$tab])) $tab = 'users';

// ---------------- users ----------------
if (is_post() && $action === 'save_user') {
    csrf_check();
    $id = (int)post('id');
    $name = (string)post('name');
    $email = strtolower((string)post('email'));
    $role = (string)post('role');
    $password = (string)post('password');
    $errors = [];
    if ($name === '') $errors[] = 'Nama wajib diisi.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email tidak valid.';
    if (!isset(ROLE_LABELS[$role])) $errors[] = 'Role tidak valid.';
    if (!$id && strlen($password) < 8) $errors[] = 'Password minimal 8 karakter.';
    if ($id && $password !== '' && strlen($password) < 8) $errors[] = 'Password minimal 8 karakter.';
    if (one('SELECT id FROM users WHERE email = ? AND id != ?', [$email, $id])) $errors[] = 'Email sudah terdaftar.';
    if ($errors) {
        flash(implode(' ', $errors), 'err');
    } elseif ($id) {
        $old = one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$old) { flash('Pengguna tidak ditemukan.', 'err'); redirect('admin', ['tab' => 'users']); }
        q('UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?', [$name, $email, $role, $id]);
        $before = ['name' => $old['name'], 'email' => $old['email'], 'role' => $old['role']];
        $after = ['name' => $name, 'email' => $email, 'role' => $role];
        $diff = changed_fields($before, $after);
        if ($diff) audit_log('user', (string)$id, 'update', $before, $after);
        if ($password !== '') {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
            audit_log('user', (string)$id, 'password_change', null, ['email' => $email]);
        }
        flash('Pengguna diperbarui.');
    } else {
        q('INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?)',
            [$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
        audit_log('user', (string)db()->lastInsertId(), 'create', null, ['name' => $name, 'email' => $email, 'role' => $role]);
        flash('Pengguna ditambahkan.');
    }
    redirect('admin', ['tab' => 'users']);
}

if (is_post() && $action === 'toggle_user') {
    csrf_check();
    $id = (int)post('id');
    $me = auth_user();
    if ($id === (int)$me['id']) {
        flash('Tidak bisa menonaktifkan akun sendiri.', 'err');
    } else {
        $old = one('SELECT active FROM users WHERE id = ?', [$id]);
        if ($old) {
            $new = $old['active'] ? 0 : 1;
            q('UPDATE users SET active = ? WHERE id = ?', [$new, $id]);
            audit_log('user', (string)$id, $new ? 'activate' : 'deactivate', ['active' => $old['active']], ['active' => $new]);
            flash('Akun ' . ($new ? 'diaktifkan' : 'dinonaktifkan') . '. Data historis tetap tersimpan.');
        }
    }
    redirect('admin', ['tab' => 'users']);
}

// ---------------- risk items ----------------
if (is_post() && $action === 'save_risk') {
    csrf_check();
    $id = (int)post('id');
    $name = (string)post('name');
    $scope = in_array(post('scope'), ['cold', 'ambient', 'both'], true) ? post('scope') : 'both';
    $mit = (string)post('mitigation');
    $temp = post('temp_related') ? 1 : 0;
    if ($name === '') { flash('Nama risiko wajib diisi.', 'err'); }
    else {
        if ($id) {
            $old = one('SELECT * FROM risk_items WHERE id = ?', [$id]);
            q('UPDATE risk_items SET name = ?, scope = ?, mitigation = ?, temp_related = ? WHERE id = ?', [$name, $scope, $mit, $temp, $id]);
            $new = one('SELECT * FROM risk_items WHERE id = ?', [$id]);
            $diff = changed_fields($old, $new);
            if ($diff) audit_log('risk_item', (string)$id, 'update', $old, $diff);
            flash('Item risiko diperbarui.');
        } else {
            $max = (int)scalar('SELECT COALESCE(MAX(sort_order),0) FROM risk_items');
            q('INSERT INTO risk_items(name,scope,mitigation,temp_related,sort_order) VALUES(?,?,?,?,?)', [$name, $scope, $mit, $temp, $max + 1]);
            audit_log('risk_item', (string)db()->lastInsertId(), 'create', null, ['name' => $name, 'scope' => $scope]);
            flash('Item risiko ditambahkan.');
        }
    }
    redirect('admin', ['tab' => 'risk']);
}

if (is_post() && $action === 'toggle_risk') {
    csrf_check();
    $id = (int)post('id');
    $old = one('SELECT active FROM risk_items WHERE id = ?', [$id]);
    if ($old) {
        $new = $old['active'] ? 0 : 1;
        q('UPDATE risk_items SET active = ? WHERE id = ?', [$new, $id]);
        audit_log('risk_item', (string)$id, $new ? 'activate' : 'deactivate', ['active' => $old['active']], ['active' => $new]);
        flash('Item risiko ' . ($new ? 'diaktifkan' : 'dinonaktifkan') . '. Penilaian lama tidak berubah.');
    }
    redirect('admin', ['tab' => 'risk']);
}

// ---------------- suppliers ----------------
if (is_post() && $action === 'save_supplier') {
    csrf_check();
    $id = (int)post('id');
    $name = (string)post('name');
    if ($name === '') { flash('Nama supplier wajib diisi.', 'err'); }
    elseif (one('SELECT id FROM suppliers WHERE name = ? AND id != ?', [$name, $id])) { flash('Nama supplier sudah dipakai.', 'err'); }
    else {
        $v = [$name, (string)post('contact'), (string)post('phone'), (string)post('email')];
        if ($id) {
            $old = one('SELECT * FROM suppliers WHERE id = ?', [$id]);
            q('UPDATE suppliers SET name=?, contact=?, phone=?, email=? WHERE id = ?', array_merge($v, [$id]));
            $new = one('SELECT * FROM suppliers WHERE id = ?', [$id]);
            $diff = changed_fields($old, $new);
            if ($diff) audit_log('supplier', (string)$id, 'update', $old, $diff);
            flash('Supplier diperbarui.');
        } else {
            q('INSERT INTO suppliers(name,contact,phone,email) VALUES(?,?,?,?)', $v);
            audit_log('supplier', (string)db()->lastInsertId(), 'create', null, ['name' => $name]);
            flash('Supplier ditambahkan.');
        }
    }
    redirect('admin', ['tab' => 'supplier']);
}

// ---------------- products ----------------
if (is_post() && $action === 'save_product') {
    csrf_check();
    $id = (int)post('id');
    $name = (string)post('name');
    $cluster = in_array(post('cluster'), ['cold', 'ambient'], true) ? post('cluster') : 'cold';
    if ($name === '') { flash('Nama produk wajib diisi.', 'err'); }
    else {
        if ($id) {
            $old = one('SELECT * FROM products WHERE id = ?', [$id]);
            q('UPDATE products SET name = ?, cluster = ? WHERE id = ?', [$name, $cluster, $id]);
            $new = one('SELECT * FROM products WHERE id = ?', [$id]);
            $diff = changed_fields($old, $new);
            if ($diff) audit_log('product', (string)$id, 'update', $old, $diff);
            flash('Produk diperbarui.');
        } else {
            q('INSERT INTO products(name,cluster) VALUES(?,?)', [$name, $cluster]);
            audit_log('product', (string)db()->lastInsertId(), 'create', null, ['name' => $name, 'cluster' => $cluster]);
            flash('Produk ditambahkan.');
        }
    }
    redirect('admin', ['tab' => 'product']);
}

// ---------------- settings ----------------
if (is_post() && $action === 'save_settings') {
    csrf_check();
    $keys = ['rpn_low_max', 'rpn_med_max', 'rpn_high_max', 'rank_a_min', 'rank_b_min', 'tps_due_days', 'report_due_days'];
    $vals = [];
    $errors = [];
    foreach ($keys as $k) {
        $v = (string)post($k);
        if (!preg_match('/^\d+$/', $v)) { $errors[] = "$k harus bilangan bulat."; continue; }
        $vals[$k] = (int)$v;
    }
    if (!$errors) {
        if (!(isset($vals['rpn_low_max']) && isset($vals['rpn_med_max']) && isset($vals['rpn_high_max'])
            && $vals['rpn_low_max'] < $vals['rpn_med_max'] && $vals['rpn_med_max'] < $vals['rpn_high_max'] && $vals['rpn_high_max'] < 1000)) {
            $errors[] = 'Threshold RPN harus berurutan: low < medium < high < 1000.';
        }
        if (isset($vals['rank_a_min']) && isset($vals['rank_b_min']) && $vals['rank_a_min'] <= $vals['rank_b_min']) {
            $errors[] = 'Threshold Rank A harus lebih besar dari Rank B.';
        }
    }
    if ($errors) {
        flash(implode(' ', $errors), 'err');
    } else {
        $before = [];
        foreach ($vals as $k => $v) {
            $before[$k] = setting($k, '');
            q('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$k, (string)$v]);
        }
        audit_log('settings', 'global', 'update', $before, $vals);
        flash('Konfigurasi disimpan. Perubahan threshold langsung dipakai perhitungan berikutnya.');
    }
    redirect('admin', ['tab' => 'settings']);
}
?>
<div class="bar-nav">
  <?php foreach ($tabs as $k => $label): ?>
    <a class="<?= $tab === $k ? 'active' : '' ?>" href="<?= e(url('admin', ['tab' => $k])) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'users'):
    $editId = (int)($_GET['edit'] ?? 0);
    $e2 = $editId ? one('SELECT * FROM users WHERE id = ?', [$editId]) : null;
    $users = all('SELECT * FROM users ORDER BY active DESC, name');
?>
<div class="panel">
  <h3><?= $editId ? 'Edit pengguna' : 'Tambah pengguna' ?></h3>
  <form class="mini" method="post">
    <?= csrf_field() ?><input type="hidden" name="a" value="save_user">
    <input type="hidden" name="id" value="<?= (int)($e2['id'] ?? 0) ?>">
    <div><label>Nama lengkap *</label><input name="name" required value="<?= e($e2['name'] ?? '') ?>"></div>
    <div><label>Email *</label><input type="email" name="email" required value="<?= e($e2['email'] ?? '') ?>"></div>
    <div><label>Role *</label><select name="role"><?= sel_options(ROLE_LABELS, $e2['role'] ?? 'logistics') ?></select></div>
    <div><label>Password <?= $editId ? '(kosongkan bila tidak diubah)' : '*' ?></label>
      <input type="password" name="password" <?= $editId ? '' : 'required' ?> autocomplete="new-password" minlength="8"></div>
    <div class="rowbtns">
      <button class="btn" type="submit"><?= $editId ? 'Simpan perubahan' : 'Tambah pengguna' ?></button>
      <?php if ($editId): ?><a class="btn ghost" href="<?= e(url('admin', ['tab' => 'users'])) ?>">Batal</a><?php endif; ?>
      <span class="muted">Menonaktifkan akun tidak menghapus data historis pengguna tersebut.</span>
    </div>
  </form>
</div>
<div class="panel">
  <h3>Daftar pengguna</h3>
  <div class="tablewrap"><table>
    <tr><th>Nama</th><th>Email</th><th>Role</th><th>Status</th><th>Dibuat</th><th></th></tr>
    <?php foreach ($users as $u): ?>
    <tr>
      <td><?= e($u['name']) ?></td><td><?= e($u['email']) ?></td>
      <td><span class="tag"><?= e(ROLE_LABELS[$u['role']]) ?></span></td>
      <td><?= $u['active'] ? '<span class="badge b-low">Aktif</span>' : '<span class="badge b-neutral">Nonaktif</span>' ?></td>
      <td class="nowrap"><?= e(fmt_date($u['created_at'])) ?></td>
      <td class="nowrap">
        <a class="btn sm ghost" href="<?= e(url('admin', ['tab' => 'users', 'edit' => (int)$u['id']])) ?>">Edit</a>
        <form method="post" style="display:inline"<?= confirm_form($u['active'] ? 'Nonaktifkan akun ini?' : 'Aktifkan akun ini?') ?>>
          <?= csrf_field() ?><input type="hidden" name="a" value="toggle_user">
          <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <button class="btn sm ghost" type="submit"><?= $u['active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>

<?php elseif ($tab === 'risk'):
    $editId = (int)($_GET['edit'] ?? 0);
    $e2 = $editId ? one('SELECT * FROM risk_items WHERE id = ?', [$editId]) : null;
    $items = all('SELECT * FROM risk_items ORDER BY sort_order');
?>
<div class="panel">
  <h3><?= $editId ? 'Edit item risiko' : 'Tambah item risiko' ?></h3>
  <form class="mini" method="post">
    <?= csrf_field() ?><input type="hidden" name="a" value="save_risk">
    <input type="hidden" name="id" value="<?= (int)($e2['id'] ?? 0) ?>">
    <div><label>Nama risiko *</label><input name="name" required value="<?= e($e2['name'] ?? '') ?>"></div>
    <div><label>Scope cluster</label>
      <select name="scope"><?= sel_options(['both' => 'Cold Chain & Ambient', 'cold' => 'Cold Chain saja', 'ambient' => 'Ambient saja'], $e2['scope'] ?? 'both') ?></select></div>
    <div><label>Terkait suhu? (untuk hitung success rate TPS)</label>
      <select name="temp_related"><option value="">Tidak</option>
        <option value="1" <?= !empty($e2['temp_related']) ? 'selected' : '' ?>>Ya — deviasi ini menggagalkan pengiriman TPS</option></select></div>
    <div><label>Saran mitigasi</label><input name="mitigation" value="<?= e($e2['mitigation'] ?? '') ?>"></div>
    <div class="rowbtns">
      <button class="btn" type="submit"><?= $editId ? 'Simpan perubahan' : 'Tambah item risiko' ?></button>
      <?php if ($editId): ?><a class="btn ghost" href="<?= e(url('admin', ['tab' => 'risk'])) ?>">Batal</a><?php endif; ?>
    </div>
  </form>
</div>
<div class="panel">
  <h3>Daftar risiko standar</h3>
  <div class="tablewrap"><table>
    <tr><th>#</th><th>Nama risiko</th><th>Scope</th><th>Suhu?</th><th>Saran mitigasi</th><th>Status</th><th></th></tr>
    <?php foreach ($items as $it): ?>
    <tr>
      <td class="right"><?= (int)$it['sort_order'] ?></td>
      <td><?= e($it['name']) ?></td>
      <td><span class="tag"><?= $it['scope'] === 'both' ? 'Keduanya' : ($it['scope'] === 'cold' ? 'Cold Chain' : 'Ambient') ?></span></td>
      <td><?= $it['temp_related'] ? 'Ya' : '—' ?></td>
      <td><?= e($it['mitigation']) ?></td>
      <td><?= $it['active'] ? '<span class="badge b-low">Aktif</span>' : '<span class="badge b-neutral">Nonaktif</span>' ?></td>
      <td class="nowrap">
        <a class="btn sm ghost" href="<?= e(url('admin', ['tab' => 'risk', 'edit' => (int)$it['id']])) ?>">Edit</a>
        <form method="post" style="display:inline"<?= confirm_form($it['active'] ? 'Nonaktifkan item risiko ini?' : 'Aktifkan item risiko ini?') ?>>
          <?= csrf_field() ?><input type="hidden" name="a" value="toggle_risk">
          <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
          <button class="btn sm ghost" type="submit"><?= $it['active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>

<?php elseif ($tab === 'supplier'):
    $editId = (int)($_GET['edit'] ?? 0);
    $e2 = $editId ? one('SELECT * FROM suppliers WHERE id = ?', [$editId]) : null;
    $rows = all('SELECT * FROM suppliers ORDER BY name');
?>
<div class="panel">
  <h3><?= $editId ? 'Edit supplier' : 'Tambah supplier' ?></h3>
  <form class="mini" method="post">
    <?= csrf_field() ?><input type="hidden" name="a" value="save_supplier">
    <input type="hidden" name="id" value="<?= (int)($e2['id'] ?? 0) ?>">
    <div><label>Nama supplier *</label><input name="name" required value="<?= e($e2['name'] ?? '') ?>"></div>
    <div><label>Kontak</label><input name="contact" value="<?= e($e2['contact'] ?? '') ?>"></div>
    <div><label>Telepon</label><input name="phone" value="<?= e($e2['phone'] ?? '') ?>"></div>
    <div><label>Email</label><input type="email" name="email" value="<?= e($e2['email'] ?? '') ?>"></div>
    <div class="rowbtns"><button class="btn" type="submit"><?= $editId ? 'Simpan' : 'Tambah supplier' ?></button>
      <?php if ($editId): ?><a class="btn ghost" href="<?= e(url('admin', ['tab' => 'supplier'])) ?>">Batal</a><?php endif; ?></div>
  </form>
</div>
<div class="panel"><h3>Daftar supplier</h3>
  <table><tr><th>Nama</th><th>Kontak</th><th>Telepon</th><th>Email</th><th></th></tr>
  <?php foreach ($rows as $r): ?>
  <tr><td><?= e($r['name']) ?></td><td><?= e($r['contact'] ?: '-') ?></td><td><?= e($r['phone'] ?: '-') ?></td>
    <td><?= e($r['email'] ?: '-') ?></td>
    <td><a class="btn sm ghost" href="<?= e(url('admin', ['tab' => 'supplier', 'edit' => (int)$r['id']])) ?>">Edit</a></td></tr>
  <?php endforeach; ?>
  </table>
</div>

<?php elseif ($tab === 'product'):
    $editId = (int)($_GET['edit'] ?? 0);
    $e2 = $editId ? one('SELECT * FROM products WHERE id = ?', [$editId]) : null;
    $rows = all('SELECT * FROM products ORDER BY cluster, name');
?>
<div class="panel">
  <h3><?= $editId ? 'Edit produk' : 'Tambah produk' ?></h3>
  <form class="mini" method="post">
    <?= csrf_field() ?><input type="hidden" name="a" value="save_product">
    <input type="hidden" name="id" value="<?= (int)($e2['id'] ?? 0) ?>">
    <div><label>Nama produk *</label><input name="name" required value="<?= e($e2['name'] ?? '') ?>"></div>
    <div><label>Cluster *</label><select name="cluster"><?= sel_options(['cold' => 'Cold Chain', 'ambient' => 'Ambient'], $e2['cluster'] ?? 'cold') ?></select></div>
    <div class="rowbtns"><button class="btn" type="submit"><?= $editId ? 'Simpan' : 'Tambah produk' ?></button>
      <?php if ($editId): ?><a class="btn ghost" href="<?= e(url('admin', ['tab' => 'product'])) ?>">Batal</a><?php endif; ?></div>
  </form>
</div>
<div class="panel"><h3>Daftar produk</h3>
  <table><tr><th>Nama</th><th>Cluster</th><th></th></tr>
  <?php foreach ($rows as $r): ?>
  <tr><td><?= e($r['name']) ?></td><td><span class="tag"><?= $r['cluster'] === 'cold' ? 'Cold Chain' : 'Ambient' ?></span></td>
    <td><a class="btn sm ghost" href="<?= e(url('admin', ['tab' => 'product', 'edit' => (int)$r['id']])) ?>">Edit</a></td></tr>
  <?php endforeach; ?>
  </table>
</div>

<?php else: ?>
<div class="panel">
  <h3>Threshold &amp; konfigurasi sistem</h3>
  <form class="mini" method="post">
    <?= csrf_field() ?><input type="hidden" name="a" value="save_settings">
    <div><label>RPN maks kategori Low</label><input name="rpn_low_max" value="<?= e(setting('rpn_low_max', '80')) ?>"></div>
    <div><label>RPN maks kategori Medium</label><input name="rpn_med_max" value="<?= e(setting('rpn_med_max', '250')) ?>"></div>
    <div><label>RPN maks kategori High</label><input name="rpn_high_max" value="<?= e(setting('rpn_high_max', '600')) ?>"></div>
    <div><label>Rank supplier A minimal success rate (%)</label><input name="rank_a_min" value="<?= e(setting('rank_a_min', '90')) ?>"></div>
    <div><label>Rank supplier B minimal success rate (%)</label><input name="rank_b_min" value="<?= e(setting('rank_b_min', '75')) ?>"></div>
    <div><label>TPS "segera jatuh tempo" ≤ (hari)</label><input name="tps_due_days" value="<?= e(setting('tps_due_days', '60')) ?>"></div>
    <div><label>Review laporan "segera" ≤ (hari)</label><input name="report_due_days" value="<?= e(setting('report_due_days', '30')) ?>"></div>
    <div class="rowbtns">
      <button class="btn" type="submit">Simpan konfigurasi</button>
      <span class="muted">Diubah tanpa ubah kode; perubahan tercatat di audit trail.</span>
    </div>
  </form>
</div>
<?php endif; ?>
