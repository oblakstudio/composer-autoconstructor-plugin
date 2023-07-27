<?php

/**
 * AutoConstructorPLugin class file.
 *
 * @package    Extremis
 * @subpackage Composer
 */

namespace Oblak\Extremis\Composer\Plugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;

class AutoConstructorPlugin implements PluginInterface, EventSubscriberInterface
{
    private const MESSAGE_RUNNING_AUTOCONSTRUCTOR = 'Running autoconstructor...';

    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var string
     */
    private $cwd;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var array
     */
    private $classes;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @var ProcessExecutor
     */
    private $processExecutor;

    /**
     * Triggers the plugin's main functionality.
     *
     * Makes it possible to run the plugin as a custom command.
     *
     * @param Event $event
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws LogicException
     * @throws ProcessFailedException
     * @throws RuntimeException
     */
    public static function run(Event $event)
    {
        $io       = $event->getIO();
        $composer = $event->getComposer();

        $instance = new static();

        $instance->io       = $io;
        $instance->composer = $composer;

        $instance->init();
        $instance->findClasses();
    }

    /**
     * {@inheritDoc}
     *
     * @throws \RuntimeException
     * @throws LogicException
     * @throws ProcessFailedException
     * @throws RuntimeException
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io       = $io;

        $this->init();
    }

    /**
     * {@inheritDoc}
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    /**
     * Prepares the plugin so it's main functionality can be run.
     *
     * @throws \RuntimeException
     * @throws LogicException
     * @throws ProcessFailedException
     * @throws RuntimeException
     */
    private function init()
    {
        $this->cwd     = getcwd();
        $this->classes = [];

        $this->processExecutor = new ProcessExecutor($this->io);
        $this->filesystem      = new Filesystem($this->processExecutor);
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::POST_AUTOLOAD_DUMP => array(
                array('findClasses', 0),
            ),

        );
    }

    /**
     * Entry point for post autoload dump event.
     *
     * @throws \InvalidArgumentException
     * @throws LogicException
     * @throws ProcessFailedException
     * @throws RuntimeException
     */
    public function findClasses()
    {
        $io        = $this->io;
        $isVerbose = $io->isVerbose();
        $exitCode  = 0;

        if ($isVerbose) {
            $io->write(sprintf('<info>%s</info>', self::MESSAGE_RUNNING_AUTOCONSTRUCTOR));
        }
    }
}
