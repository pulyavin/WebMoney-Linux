#!/usr/bin/env php
<?php
require_once("commands.php");
require_once("shell.php");

// парсим файл конфигурации
$config = parse_ini_file("config.ini", true);

require_once("./vendor/autoload.php");
require_once("./console/console.php");

use pulyavin\wmxml\WMXml as WMXml;
use baibaratsky\WebMoney\Signer as Signer;

try {
	// будем работать с классом Signer на PHP
	if (
		isset($config['main']['key_file'])
		&&
		isset($config['main']['key_password'])
	) {
		$wmsigner = new Signer($config['main']['wmid'], $config['main']['key_file'], $config['main']['key_password']);
	}
	// бинарный подписчик
	else {
		$wmsigner = $config['paths']['wmsigner'];
	}

	// создаём объект WebMoney XML API
	$wmxml = new WMXml(
		"classic",
		[
			"wmid"     => $config['main']['wmid'],
			"wmsigner" => $wmsigner,
			"rootca"   => $config['paths']['rootca'],
			"tranid"  => $config['paths']['tranid'],
			"connect"  => $config['curl']['connect'],
			"timeout"  => $config['curl']['timeout']
		]
	);

	// создаём объект ядра комманд
	$commands = new commands($wmxml, $config);

	//создаём объект консоли
	$console = new console();

	// запускаем shell
	shell::init($commands, $console)->start();
} catch (Exception $e) {
	echo $e->getMessage();
}
?>