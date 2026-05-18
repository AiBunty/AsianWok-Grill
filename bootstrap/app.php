<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Config/Env.php';

use AWG\Config\Env;

Env::load(dirname(__DIR__) . '/.env');

date_default_timezone_set(Env::get('APP_TIMEZONE', 'Asia/Kolkata'));
