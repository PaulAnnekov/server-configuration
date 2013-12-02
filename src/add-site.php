<?php

require_once('lib/Site.php');

if (empty($argv[1]))
{
    trigger_error('You must provide domain name');
    return;
}

$site = new Site();
$site->add($argv[1]);