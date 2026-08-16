<?php
// Deletions must be confirmed with a CSRF-protected POST in the delete manager.
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$query = $id ? '?tab=transactions&search=' . rawurlencode((string) $id) : '?tab=transactions';
header('Location: super_delete_manager.php' . $query, true, 302);
exit;
