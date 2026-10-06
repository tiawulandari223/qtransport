<?php
$error = '';
if (is_post()) {
    csrf_check();
    if (login((string)post('email'), (string)post('password'))) redirect('dashboard');
    audit_log('session', '', 'login_failed', null, ['email' => (string)post('email')]);
    $error = 'Email atau password salah, atau akun nonaktif.';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk — Q-Transport</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-box">
    <h1>Q-Transport</h1>
    <div class="sub">Quality oversight for transportation management</div>
    <?php if ($error): ?><div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="index.php?p=login">
      <?= csrf_field() ?>
      <div class="form-row"><label>Email</label>
        <input type="email" name="email" required autofocus placeholder="nama@qtransport.local"></div>
      <div class="form-row"><label>Password</label>
        <input type="password" name="password" required></div>
      <button class="btn" type="submit">Masuk</button>
    </form>
    <div class="login-hint">
      <b>Akun demo (seed)</b><br>
      admin@qtransport.local / admin123<br>
      quality@qtransport.local / quality123<br>
      logistik@qtransport.local / logistik123<br>
      manajemen@qtransport.local / manajemen123<br>
      auditor@qtransport.local / auditor123
    </div>
  </div>
</div>
</body>
</html>
