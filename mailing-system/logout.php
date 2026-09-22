<?php

declare(strict_types=1);

require __DIR__ . '/src/auth.php';
authLogout();
header('Location: login.php');
exit;
