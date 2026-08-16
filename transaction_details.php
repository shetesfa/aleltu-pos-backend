<?php
// Compatibility route: receipts are the supported transaction detail view.
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
header('Location: receipt.php' . ($id ? '?id=' . $id : ''), true, 302);
exit;
