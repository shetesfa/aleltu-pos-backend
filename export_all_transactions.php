<?php
// The old export link is retained without exposing a missing page. The report
// remains filtered and can be exported from the supported report screen.
header('Location: all_transactions.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 302);
exit;
