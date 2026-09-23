<?php

require_once __DIR__ . '/../app/Auth.php';

Auth::requirePost();

Auth::logout();

header('Location: login.php?logged_out=1', true, 303);
exit;
