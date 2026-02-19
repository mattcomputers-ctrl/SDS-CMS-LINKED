<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — <?= e(\SDS\Core\App::config('app.name', 'SDS System')) ?></title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-header">
            <h1><?= e(\SDS\Core\App::config('app.name', 'SDS System')) ?></h1>
            <p>Safety Data Sheet Authoring System</p>
        </div>

        <?= flash_messages() ?>

        <form method="POST" action="/login" class="login-form">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?= e(old('username')) ?>"
                       required autofocus autocomplete="username">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       required autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn-primary btn-block">Sign In</button>
        </form>
    </div>
</body>
</html>
