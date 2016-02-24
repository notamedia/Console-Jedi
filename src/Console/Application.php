<?php
/**
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Notamedia\ConsoleJedi\Console;

use Bitrix\Main\DB\ConnectionException;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Notamedia\ConsoleJedi\Console\Command\Agents;
use Notamedia\ConsoleJedi\Console\Command\Cache;
use Notamedia\ConsoleJedi\Console\Command\Environment;
use Notamedia\ConsoleJedi\Console\Command\InitCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Console Jedi application.
 */
class Application extends \Symfony\Component\Console\Application
{
    /**
     * Version of the Console Jedi application.
     */
    const VERSION = '1.0.0';
    /**
     * Default name of configuration file.
     */
    const CONFIG_DEFAULT_FILE = './.jedi.php';
    /**
     * Bitrix is unavailable.
     */
    const BITRIX_STATUS_UNAVAILABLE = 0;
    /**
     * Bitrix is available, but not have connection to DB.
     */
    const BITRIX_STATUS_NO_DB_CONNECTION = 5;
    /**
     * Bitrix is available.
     */
    const BITRIX_STATUS_COMPLETE = 10;
    /**
     * @var int
     */
    protected $bitrixStatus = Application::BITRIX_STATUS_UNAVAILABLE;
    /**
     * @var null|string
     */
    protected $documentRoot = null;
    
    private $configuration = null;

    /**
     * {@inheritdoc}
     */
    public function __construct($name = 'Console Jedi', $version = self::VERSION)
    {
        parent::__construct($name, static::VERSION);
    }

    /**
     * {@inheritdoc}
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        if ($this->getConfiguration() === null)
        {
            $this->loadConfiguration();
        }
        
        $this->initializeBitrix();
        
        if ($this->getConfiguration())
        {
            $this->addCommands([
                new Agents\OnCronCommand(),
                new Agents\RunCommand(),
                new Cache\ClearCommand(),
                new Environment\InitCommand()
            ]);
        }

        if ($this->getBitrixStatus() && $this->getConfiguration()['useModules'] === true)
        {
            $moduleCommands = $this->getModulesCommands();
            
            foreach ($moduleCommands as $moduleCommand)
            {
                $this->add($moduleCommand);
            }
        }

        $result = parent::doRun($input, $output);

        if ($this->getConfiguration() === null)
        {
            $output->writeln(PHP_EOL . '<error>No configuration loaded.</error> Please run <info>init</info> command first');
        }
        else
        {
            switch ($this->getBitrixStatus())
            {
                case static::BITRIX_STATUS_UNAVAILABLE:
                    $output->writeln(PHP_EOL . sprintf('<error>No Bitrix kernel found in %s.</error> Please run <info>env:init</info> command to configure', $this->documentRoot));
                    break;

                case static::BITRIX_STATUS_NO_DB_CONNECTION:
                    $output->writeln(PHP_EOL . '<error>Bitrix database connection is unavailable.</error>');
                    break;

                case static::BITRIX_STATUS_COMPLETE:
                    if ($this->getCommandName($input) === null)
                    {
                        $output->writeln(PHP_EOL . sprintf('Using Bitrix <info>kernel v%s</info>.</info>', SM_VERSION),
                            OutputInterface::VERBOSITY_VERY_VERBOSE);
                    }
                    break;
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultCommands()
    {
        $commands = parent::getDefaultCommands();
        $commands[] = new InitCommand();
        
        return $commands;
    }

    /**
     * Loading application configuration.
     * 
     * @param string $path Path to configuration file.
     *
     * @return bool
     * 
     * @throws \Exception
     */
    public function loadConfiguration($path = self::CONFIG_DEFAULT_FILE)
    {
        if (!is_file($path))
        {
            return false;
        }
        
        $this->configuration = include $path;
        
        if (!is_array($this->configuration))
        {
            throw new \Exception('Configuration file ' . $path . ' must return an array');
        }

        $filesystem = new Filesystem();
        if ($filesystem->isAbsolutePath($this->configuration['web-dir']))
            $_SERVER['DOCUMENT_ROOT'] = $this->documentRoot = $this->configuration['web-dir'];
        else
            $_SERVER['DOCUMENT_ROOT'] = $this->documentRoot = $this->getRoot() . '/' . $this->configuration['web-dir'];

        if (!is_dir($_SERVER['DOCUMENT_ROOT']))
        {
            return false;
        }
        
        return true;
    }

    /**
     * Gets application configuration.
     * 
     * @return null|array
     */
    public function getConfiguration()
    {
        return $this->configuration;
    }

    /**
     * Gets console commands from modules.
     * 
     * @return array
     * 
     * @throws \Bitrix\Main\LoaderException
     */
    protected function getModulesCommands()
    {
        $commands = [];
                
        foreach (ModuleManager::getInstalledModules() as $module)
        {
            $moduleBitrixDir = $_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/' . $module['ID'];
            $moduleLocalDir = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . $module['ID'];
            $cliFile = '/cli.php';
            
            if (is_file($moduleBitrixDir . $cliFile))
            {
                $cliFile = $moduleBitrixDir . $cliFile;
            }
            elseif (is_file($moduleLocalDir . $cliFile))
            {
                $cliFile = $moduleLocalDir . $cliFile;
            }
            else
            {
                continue;
            }
            
            if (!Loader::includeModule($module['ID']))
            {
                continue;
            }
                
            $config = include_once $cliFile;

            if (isset($config['commands']) && is_array($config['commands']))
            {
                $commands = array_merge($commands, $config['commands']);
            }
        }
        
        return $commands;
    }

    /**
     * Initialize kernel of Bitrix.
     * 
     * @return int The status of readiness kernel.
     */
    public function initializeBitrix()
    {
        if (!$this->checkBitrix())
        {
            return static::BITRIX_STATUS_UNAVAILABLE;
        }
        
        define('NO_KEEP_STATISTIC', true);
        define('NOT_CHECK_PERMISSIONS', true);

        try
        {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
            
            if (defined('B_PROLOG_INCLUDED') && B_PROLOG_INCLUDED === true)
            {
                $this->bitrixStatus = static::BITRIX_STATUS_COMPLETE;
            }
        }
        catch (ConnectionException $e)
        {
            $this->bitrixStatus = static::BITRIX_STATUS_NO_DB_CONNECTION;
        }
        
        return $this->bitrixStatus;
    }

    /**
     * Checks readiness of Bitrix for kernel initialize.
     * 
     * @return bool
     */
    public function checkBitrix()
    {
        if (
            !$_SERVER['DOCUMENT_ROOT']
            || !is_file($_SERVER['DOCUMENT_ROOT'] . '/bitrix/.settings.php')
            || !is_file($_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/dbconn.php'))
        {
            return false;
        }
        
        return true;
    }

    /**
     * Gets Bitrix status.
     * 
     * @return int Value of constant `Application::BITRIX_STATUS_*`.
     */
    public function getBitrixStatus()
    {
        return $this->bitrixStatus;
    }

    /**
     * Gets root directory from which are running Console Jedi.
     * 
     * @return string
     */
    public function getRoot()
    {
        return getcwd();
    }
}