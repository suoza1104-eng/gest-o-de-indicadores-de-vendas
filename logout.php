<?php
session_start();
session_unset();
session_destroy();
header('Location: /meta_ads_manager_project/login.php');
exit;