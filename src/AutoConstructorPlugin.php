<?php

/**
 * AutoConstructorPLugin class file.
 *
 * @package    Extremis
 * @subpackage Composer
 */

namespace Oblak\Composer\Plugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use ReflectionClass;

class AutoConstructorPlugin implements PluginInterface, EventSubscriberInterface
{
    private const MESSAGE_RUNNING_PLUGIN   = 'Running autoconstructor...';
    private const MESSAGE_NO_CLASSES_FOUND = 'No classes found to add to autoconstructor...';

    /**
     * Extra keys for the composer.json file.
     */
    private const KEY_EXCLUDE_FILE    = 'autoconstructor-exclude-file';
    private const KEY_EXCLUDE_CLASSES = 'autoconstructor-exclude-classes';
    private const KEY_CLASS_PATH      = 'autoconstructor-class-path';
    private const KEY_MODULE_FILE     = 'autoconstructor-module-file';
    private const KEY_BASE_NAMESPACE  = 'autoconstructor-base-namespace';

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
     * @var string[]
     */
    private $excludes;

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
        $instance->generateFile();
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
        $this->cwd      = getcwd();
        $this->classes  = [];
        $this->excludes = $this->getExcludes();

        $this->processExecutor = new ProcessExecutor($this->io);
        $this->filesystem      = new Filesystem($this->processExecutor);
    }

    /**
     * Returns the exclude file path.
     *
     * @return string
     */
    private function getExcludes(): array
    {
        $excludes_file = $this->cwd . '/config/excludes.php';
        $extra         = $this->composer->getPackage()->getExtra();

        if (isset($extra[self::KEY_EXCLUDE_CLASSES]) && is_array($extra[self::KEY_EXCLUDE_CLASSES])) {
            return $extra[self::KEY_EXCLUDE_CLASSES];
        }

        if (isset($extra[self::KEY_EXCLUDE_FILE])) {
            $excludes_file = $this->cwd . '/config/excludes.php';
        }

        if (file_exists($excludes_file)) {
            return include $excludes_file;
        }

        throw new \InvalidArgumentException('No exclude file or exclude classes found');
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::POST_AUTOLOAD_DUMP => [
                ['generateFile', 0],
            ],

        ];
    }

    /**
     * Entry point for post autoload dump event.
     *
     * @throws \InvalidArgumentException
     * @throws LogicException
     * @throws ProcessFailedException
     * @throws RuntimeException
     */
    public function generateFile()
    {
        $io        = $this->io;
        $isVerbose = $io->isVerbose();
        $exitCode  = 0;

        if ($isVerbose) {
            $io->write(sprintf('<info>%s</info>', self::MESSAGE_RUNNING_PLUGIN));
        }

        $vendorDir = $this->composer->getConfig()->get('vendor-dir');

        $classnames = array_keys(array_filter(
            (include $vendorDir . '/composer/autoload_classmap.php'),
            [$this, 'filterClassNames'],
            ARRAY_FILTER_USE_BOTH,
        ));

        if (empty($classnames)) {
            if ($isVerbose) {
                $io->write(sprintf('<info>%s</info>', self::MESSAGE_NO_CLASSES_FOUND));
            }
        }

        $exitCode = $this->createModuleFile($classnames);

        if ($exitCode === 0) {
            if ($isVerbose) {
                $io->write('<info>Module file created successfully</info>');
            }
            return $exitCode;
        }

        if ($isVerbose) {
            $io->write('<error>Module file could not be created</error>');
        }

        return $exitCode;
    }

    private function createModuleFile($classnames)
    {
        $baseNamespace = $this->getBaseNamespace();

        $output = sprintf(
            "<?php\n/**\n * %s Modules\n *\n * @package    %s\n * @subpackage Config\n */\n\nreturn array(",
            $baseNamespace,
            $baseNamespace,
        );

        if (empty($classnames)) {
            $output .= ");\n";
            return $this->writeFile($output);
        }

        $max_length = array_reduce($classnames, function ($carry, $classname) {
            $length = strlen($this->getClassnameKey($classname));

            return ($length > $carry) ? $length : $carry;
        }, 0);

        foreach ($classnames as $classname) {
            $key     = $this->getClassnameKey($classname);
            $output .= sprintf(
                "\n    '%s'%s => '%s',",
                $key,
                str_repeat(' ', $max_length - strlen($key)),
                str_replace('\\', '\\\\', $classname),
            );
        }

        $output .= "\n);\n\n";

        return $this->writeFile($output);
    }

    private function writeFile(string $output): int
    {
        return file_put_contents($this->getModuleFile(), $output) > 0 ? 0 : 1;
    }

    private function getClassnameKey(string $classname): string
    {
        $exploded  = explode('\\', $classname);
        $class     = strtolower(array_pop($exploded));
        $namespace = strtolower(array_pop($exploded));

        return "{$namespace}-{$class}";
    }

    private function getClassPath()
    {
        $basePath = $this->cwd . '/framework';
        $extra    = $this->composer->getPackage()->getExtra();

        if (isset($extra[self::KEY_CLASS_PATH])) {
            $basePath = $this->cwd . '/' . $extra[self::KEY_CLASS_PATH];
        }

        return $basePath;
    }

    private function getBaseNamespace()
    {
        $baseNamespace = 'Extremis';
        $extra         = $this->composer->getPackage()->getExtra();

        if (isset($extra[self::KEY_BASE_NAMESPACE])) {
            $baseNamespace = $extra[self::KEY_BASE_NAMESPACE];
        }

        return $baseNamespace;
    }

    private function getModuleFile()
    {
        $modulePath = $this->cwd . '/config/modules.php';
        $extra      = $this->composer->getPackage()->getExtra();

        if (isset($extra[self::KEY_MODULE_FILE])) {
            $modulePath = $this->cwd . '/' . $extra[self::KEY_MODULE_FILE];
        }

        return $modulePath;
    }

    private function filterClassNames(string $path, string $classname): bool
    {

        $isAutoconstructable =
            strpos($classname, $this->getBaseNamespace(), 0) !== false &&
            strpos($path, $this->getClassPath(), 0) !== false &&
            (new ReflectionClass($classname))->isInstantiable();

        return $isAutoconstructable && !in_array($classname, $this->excludes);
    }
}
