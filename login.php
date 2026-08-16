<?php
// Compatibility route for old bookmarks. The application login is index.php.
header('Location: index.php', true, 302);
exit;
