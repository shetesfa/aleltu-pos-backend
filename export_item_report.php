<?php
// Compatibility route for the item report's previous export URL.
header('Location: filter_by_item.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 302);
exit;
