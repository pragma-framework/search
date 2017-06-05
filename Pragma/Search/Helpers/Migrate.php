<?php
namespace Pragma\Search\Helpers;

use Composer\Script\Event;
use Composer\Installer\PackageEvent;

class Migrate{
	public static function postInstallCmd(Event $event) {
		if (self::checkConfig($event)) {
			// Run phinx migrate
			self::phinxMigrate($event,true);
		} else {
			die();
		}
	}
	public static function postUpdateCmd(Event $event) {
		if (self::checkConfig($event)) {
			// Run phinx migrate
			self::phinxMigrate($event);
		} else {
			die();
		}
	}

	// Don't use it
	public static function preUpdate(PackageEvent $event) {
		if($event->getOperation()->getPackage()->getName() == "pragma-framework/search"){
			if (!self::checkConfig($event)) {
				die();
			}
		}
	}

	// Don't use it
	public static function postUpdate(PackageEvent $event){
		if($event->getOperation()->getTargetPackage()->getName() == "pragma-framework/search"){
			if (self::checkConfig($event)) {
				// Run phinx migrate
				self::phinxMigrate($event);
			} else {
				die();
			}
		}
	}

	// Don't use it
	public static function postInstall(PackageEvent $event){
		if($event->getOperation()->getPackage()->getName() == "pragma-framework/search"){
			if (self::checkConfig($event)) {
				// Run phinx migrate
				self::phinxMigrate($event,true);
			} else {
				die();
			}
		}
	}

	protected static function checkConfig(&$event) {
		// base on ./vendor/pragma-framework/search/Pragma/Search/Helpers/ path
		if(!file_exists(realpath(__DIR__.'/../../../../../../').'/config/config.php')){
			$event->getIO()->writeError(array(
				"You need to configure your app.",
				"Create config/config.php and define database informations connection.",
				"And re-run composer install/update."
			));
			$event->stopPropagation();
			return false;
		}else{
			return true;
		}
	}

	protected static function phinxMigrate(&$event,$install = false){
		// base on ./vendor/pragma-framework/search/Pragma/Search/Helpers/ path
		$phinxApp = require realpath(__DIR__.'/../../../../../').'/robmorgan/phinx/app/phinx.php';
		$phinxTextWrapper = new \Phinx\Wrapper\TextWrapper($phinxApp);

		$phinxTextWrapper->setOption('configuration', __DIR__.'/../../../phinx.php');
		$phinxTextWrapper->setOption('parser', 'PHP');
		$phinxTextWrapper->setOption('environment', 'default');

		$log = $phinxTextWrapper->getMigrate();
		$event->getIO()->write($log);
		if ($install) {
			$log = $phinxTextWrapper->getSeed();
			$event->getIO()->write($log);
		}
	}

	protected static function preUninstall(PackageEvent $event) {
		// base on ./vendor/pragma-framework/search/Pragma/Search/Helpers/ path
		if($event->getOperation()->getPackage()->getName() == "pragma-framework/search" &&
			!file_exists(realpath(__DIR__.'/../../../../../../').'/config/config.php')){
			// base on ./vendor/pragma-framework/search/Pragma/Search/Helpers/ path
			$phinxApp = require realpath(__DIR__.'/../../../../../').'/robmorgan/phinx/app/phinx.php';
			$phinxTextWrapper = new \Phinx\Wrapper\TextWrapper($phinxApp);

			$phinxTextWrapper->setOption('configuration', __DIR__.'/../../../phinx.php');
			$phinxTextWrapper->setOption('parser', 'PHP');
			$phinxTextWrapper->setOption('environment', 'default');

			$log = $phinxTextWrapper->getRollback(null,0);
			$event->getIO()->write($log);
		}
	}
}
