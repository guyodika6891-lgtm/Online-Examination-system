<?php
// Run this file every minute using cron job
require_once '../config/database.php';
require_once '../config/email_config.php';

processEmailQueue($pdo);
echo "Email queue processed at " . date('Y-m-d H:i:s');
?>