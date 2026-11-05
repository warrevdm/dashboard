<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_auth();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
redirect('reservation.php?id=' . $id . '#dossier-bewerken');
