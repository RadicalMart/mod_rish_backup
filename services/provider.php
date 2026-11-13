<?php

declare(strict_types=1);

use Joomla\CMS\Extension\Service\Provider\HelperFactory;
use Joomla\CMS\Extension\Service\Provider\Module;
use Joomla\CMS\Extension\Service\Provider\ModuleDispatcherFactory;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

defined('_JEXEC') or die;

return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->registerServiceProvider(new ModuleDispatcherFactory('\\Rish\\Module\\RishBackup'));
        $container->registerServiceProvider(new HelperFactory('\\Rish\\Module\\RishBackup\\Administrator\\Helper'));
        $container->registerServiceProvider(new Module());
    }
};
