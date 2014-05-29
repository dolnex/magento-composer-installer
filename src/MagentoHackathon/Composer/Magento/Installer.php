<?php
/**
 * Composer Magento Installer
 */

namespace MagentoHackathon\Composer\Magento;

use Composer\Repository\InstalledRepositoryInterface;
use Composer\IO\IOInterface;
use Composer\Composer;
use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Installer\LibraryInstaller;
use Composer\Installer\InstallerInterface;
use Composer\Package\PackageInterface;

/**
 * Composer Magento Installer
 */
class Installer extends LibraryInstaller implements InstallerInterface
{
    /**
     * The base directory of the magento installation
     *
     * @var \SplFileInfo
     */
    protected $magentoRootDir = null;

    /**
     * The default base directory of the magento installation
     *
     * @var \SplFileInfo
     */
    protected $defaultMagentoRootDir = 'root';

    /**
     * The base directory of the modman packages
     *
     * @var \SplFileInfo
     */
    protected $modmanRootDir = null;

    /**
     * If set overrides existing files
     *
     * @var bool
     */
    protected $isForced = false;

    /**
     * If set the package will not be deployed (with any DeployStrategy)
     * Using a modman-root-dir is not supported yet but the modman-DeployStrategy so you might want to use the normal
     * modman script for this
     *
     * @var bool
     */
    protected $skipPackageDeployment = false;

    /**
     * The module's base directory
     *
     * @var string
     */
    protected $_source_dir;

    /**
     * @var string
     */
    protected $_deployStrategy = "symlink";


     const MAGENTO_REMOVE_DEV_FLAG = 'magento-remove-dev';
    const MAGENTO_MAINTANANCE_FLAG = 'maintenance.flag';
    const MAGENTO_CACHE_PATH = 'var/cache';
    const MAGENTO_ROOT_DIR_TMP_SUFFIX = '_tmp';
    const MAGENTO_ROOT_DIR_BACKUP_SUFFIX = '_bkup';

    protected $noMaintenanceMode = false;
    protected $originalMagentoRootDir = null;
    protected $backupMagentoRootDir = null;
    protected $removeMagentoDev = false;
    protected $keepMagentoCache = false;
    protected $_magentoLocalXmlPath = 'app/etc/local.xml';
    protected $_defaultEnvFilePaths = array(
        'app/etc/local.xml'
    );
    protected $_magentoDevDir = 'dev';
    protected $_magentoWritableDirs = array(
        'app/etc',
        'media',
        'var'
    );

    /**
     * Initializes Magento Module installer
     *
     * @param \Composer\IO\IOInterface $io
     * @param \Composer\Composer $composer
     * @param string $type
     * @throws \ErrorException
     */
    public function __construct(IOInterface $io, Composer $composer, $type = 'magento-module')
    {
        parent::__construct($io, $composer, $type);
        $this->initializeVendorDir();

        $this->annoy( $io );

        $extra = $composer->getPackage()->getExtra();

        if (isset($extra['magento-root-dir']) || (($rootDirInput = $io->ask('please define your magento root dir [' . $this->defaultMagentoRootDir . '] ', $this->defaultMagentoRootDir)) || $rootDirInput = $this->defaultMagentoRootDir)) {

            if (isset($rootDirInput)) {
                $extra['magento-root-dir'] = $rootDirInput;
                $this->updateJsonExtra($extra, $io);
            }

            $dir = rtrim(trim($extra['magento-root-dir']), '/\\');
            $this->magentoRootDir = new \SplFileInfo($dir);
            if (!is_dir($dir) && $io->askConfirmation('magento root dir "' . $dir . '" missing! create now? [Y,n] ')) {
                $this->initializeMagentoRootDir($dir);
                $io->write('magento root dir "' . $dir . '" created');
            }

            if (!is_dir($dir)) {
                $dir = $this->vendorDir . "/$dir";
                $this->magentoRootDir = new \SplFileInfo($dir);
            }
        }

        if (isset($extra['modman-root-dir'])) {

            $dir = rtrim(trim($extra['modman-root-dir']), '/\\');
            if (!is_dir($dir)) {
                $dir = $this->vendorDir . "/$dir";
            }
            if (!is_dir($dir)) {
                throw new \ErrorException("modman root dir \"{$dir}\" is not valid");
            }
            $this->modmanRootDir = new \SplFileInfo($dir);
        }

        if (is_null($this->magentoRootDir) || false === $this->magentoRootDir->isDir()) {
            $dir = $this->magentoRootDir instanceof \SplFileInfo ? $this->magentoRootDir->getPathname() : '';
            throw new \ErrorException("magento root dir \"{$dir}\" is not valid");
        }

        if (isset($extra['magento-force'])) {
            $this->isForced = (bool)$extra['magento-force'];
        }

        if (isset($extra['magento-deploystrategy'])) {
            $this->setDeployStrategy((string)$extra['magento-deploystrategy']);
        }

        if (!empty($extra['skip-package-deployment'])) {
            $this->skipPackageDeployment = true;
        }
    }

    /**
     * Create base requrements for project installation
     */
    protected function initializeMagentoRootDir() {
        if (!$this->magentoRootDir->isDir()) {
            $magentoRootPath = $this->magentoRootDir->getPathname();
            $pathParts = explode(DIRECTORY_SEPARATOR, $magentoRootPath);
            $baseDir = explode(DIRECTORY_SEPARATOR, $this->vendorDir);
            array_pop($baseDir);
            $pathParts = array_merge($baseDir, $pathParts);
            $directoryPath = '';
            foreach ($pathParts as $pathPart) {
                $directoryPath .= DIRECTORY_SEPARATOR . $pathPart;
                $this->filesystem->ensureDirectoryExists($directoryPath);
            }
        }

        // $this->getSourceDir($package);
    }


    /**
     *
     * @param array $extra
     * @param \Composer\IO\IOInterface $io
     * @return int
     */
    private function updateJsonExtra($extra, $io) {

        $file = Factory::getComposerFile();

        if (!file_exists($file) && !file_put_contents($file, "{\n}\n")) {
            $io->write('<error>' . $file . ' could not be created.</error>');

            return 1;
        }
        if (!is_readable($file)) {
            $io->write('<error>' . $file . ' is not readable.</error>');

            return 1;
        }
        if (!is_writable($file)) {
            $io->write('<error>' . $file . ' is not writable.</error>');

            return 1;
        }

        $json = new JsonFile($file);
        $composer = $json->read();
        $composerBackup = file_get_contents($json->getPath());
        $extraKey = 'extra';
        $baseExtra = array_key_exists($extraKey, $composer) ? $composer[$extraKey] : array();

        if (!$this->updateFileCleanly($json, $baseExtra, $extra, $extraKey)) {
            foreach ($extra as $key => $value) {
                $baseExtra[$key] = $value;
            }

            $composer[$extraKey] = $baseExtra;
            $json->write($composer);
        }
    }

    private function updateFileCleanly($json, array $base, array $new, $rootKey) {
        $contents = file_get_contents($json->getPath());

        $manipulator = new JsonManipulator($contents);

        foreach ($new as $childKey => $childValue) {
            if (!$manipulator->addLink($rootKey, $childKey, $childValue)) {
                return false;
            }
        }

        file_put_contents($json->getPath(), $manipulator->getContents());

        return true;
    }

    /**
     * @param string $strategy
     */
    public function setDeployStrategy($strategy)
    {
        $this->_deployStrategy = $strategy;
    }

    /**
     * Returns the strategy class used for deployment
     *
     * @param \Composer\Package\PackageInterface $package
     * @param string $strategy
     * @return \MagentoHackathon\Composer\Magento\Deploystrategy\DeploystrategyAbstract
     */
    public function getDeployStrategy(PackageInterface $package, $strategy = null)
    {
        if (null === $strategy) {
            $strategy = $this->_deployStrategy;
        }
        $targetDir = $this->getTargetDir();
        $sourceDir = $this->getSourceDir($package);
        switch ($strategy) {
            case 'copy' || $package->getType() === 'magento-core':
                $impl = new \MagentoHackathon\Composer\Magento\Deploystrategy\Copy($sourceDir, $targetDir);
                break;
            case 'link':
                $impl = new \MagentoHackathon\Composer\Magento\Deploystrategy\Link($sourceDir, $targetDir);
                break;
            case 'symlink':
            default:
                $impl = new \MagentoHackathon\Composer\Magento\Deploystrategy\Symlink($sourceDir, $targetDir);
        }
        // Inject isForced setting from extra config
        $impl->setIsForced($this->isForced);
        return $impl;
    }

    /**
     * Decides if the installer supports the given type
     *
     * @param  string $packageType
     * @return bool
     */
    public function supports($packageType)
    {
        return 'magento-module' === $packageType || 'magento-core' === $packageType;
    }

    /**
     * Return Source dir of package
     *
     * @param \Composer\Package\PackageInterface $package
     * @return string
     */
    protected function getSourceDir(PackageInterface $package)
    {
        $this->filesystem->ensureDirectoryExists($this->vendorDir);
        return $this->getInstallPath($package);
    }

    /**
     * Return the absolute target directory path for package installation
     *
     * @return string
     */
    protected function getTargetDir()
    {
        $targetDir = realpath($this->magentoRootDir->getPathname());
        return $targetDir;
    }

    /**
     * Installs specific package
     *
     * @param InstalledRepositoryInterface $repo    repository in which to check
     * @param PackageInterface             $package package instance
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {

        if ($package->getType() === 'magento-core' && !$this->preInstallMagentoCore()) {
            return;
        }

        parent::install($repo, $package);

        if (!$this->skipPackageDeployment) {
            $strategy = $this->getDeployStrategy($package);
            $strategy->setMappings($this->getParser($package)->getMappings());
            $strategy->deploy();
        }
        if ($package->getType() === 'magento-core') {
            $this->postInstallMagentoCore();
        }
    }

     /**
     * Install Magento core
     *
     * @param InstalledRepositoryInterface $repo repository in which to check
     * @param PackageInterface $package package instance
     */
    protected function preInstallMagentoCore() {
        if (!$this->io->askConfirmation('<info>Are you sure you want to install the Magento core?</info><error>Attention: Your Magento root dir will be cleared in the process!</error> [<comment>Y,n</comment>] ', true)) {
            $this->io->write('Skipping core installation...');
            return false;
        }
        $this->clearRootDir();
        return true;
    }

    protected function clearRootDir() {
        $this->filesystem->removeDirectory($this->magentoRootDir->getPathname());
        $this->filesystem->ensureDirectoryExists($this->magentoRootDir->getPathname());
    }

    public function prepareMagentoCore() {
        if (!$this->skipPackageDeployment) {
            $this->setMagentoPermissions();
            $this->redeployProject();
        }
    }

    /**
     * some directories have to be writable for the server
     */
    protected function setMagentoPermissions() {
        foreach ($this->_magentoWritableDirs as $dir) {
            if (!file_exists($this->getTargetDir() . DIRECTORY_SEPARATOR . $dir)) {
                mkdir($this->getTargetDir() . DIRECTORY_SEPARATOR . $dir);
            }
            $this->setPermissions($this->getTargetDir() . DIRECTORY_SEPARATOR . $dir, 0777, 0666);
        }
    }

    /**
     * set permissions recursively
     *
     * @param string $path Path to set permissions for
     * @param int $dirmode Permissions to be set for directories
     * @param int $filemode Permissions to be set for files
     */
    protected function setPermissions($path, $dirmode, $filemode) {
        if (is_dir($path)) {
            if (!@chmod($path, $dirmode)) {
                $this->io->write(
                        'Failed to set permissions "%s" for directory "%s"', decoct($dirmode), $path
                );
            }
            $dh = opendir($path);
            while (($file = readdir($dh)) !== false) {
                if ($file != '.' && $file != '..') { // skip self and parent pointing directories
                    $fullpath = $path . '/' . $file;
                    $this->setPermissions($fullpath, $dirmode, $filemode);
                }
            }
            closedir($dh);
        } elseif (is_file($path)) {
            if (false == !@chmod($path, $filemode)) {
                $this->io->write(
                        'Failed to set permissions "%s" for file "%s"', decoct($filemode), $path
                );
            }
        }
    }

    protected function redeployProject() {
        $ioInterface = $this->io;
        // init repos
        $composer = $this->composer;
        $installedRepo = $composer->getRepositoryManager()->getLocalRepository();

        $dm = $composer->getDownloadManager();
        $im = $composer->getInstallationManager();

        /*
         * @var $moduleInstaller MagentoHackathon\Composer\Magento\Installer
         */
        $moduleInstaller = $im->getInstaller("magento-module");

        foreach ($installedRepo->getPackages() as $package) {

            if ($ioInterface->getOption('verbose')) {
            $ioInterface->write($package->getName());
            $ioInterface->write($package->getType());
            }

            if ($package->getType() != "magento-module") {
                continue;
            }
            if ($ioInterface->getOption('verbose')) {
            $ioInterface->write("package {$package->getName()} recognized");
            }

            $strategy = $moduleInstaller->getDeployStrategy($package);
            if ($ioInterface->getOption('verbose')) {
            $ioInterface->write("used " . get_class($strategy) . " as deploy strategy");
            }
            $strategy->setMappings($moduleInstaller->getParser($package)->getMappings());

            $strategy->deploy();
        }


        return;
    }

    /**
     * Updates specific package
     *
     * @param InstalledRepositoryInterface $repo    repository in which to check
     * @param PackageInterface             $initial already installed package version
     * @param PackageInterface             $target  updated version
     *
     * @throws InvalidArgumentException if $from package is not installed
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {

        if (!$this->skipPackageDeployment) {
            if ($target->getType() === 'magento-core' && !$this->preUpdateMagentoCore()) {
                return;
            }

            $initialStrategy = $this->getDeployStrategy($initial);
            $initialStrategy->setMappings($this->getParser($initial)->getMappings());
            $initialStrategy->clean();
        }

        parent::update($repo, $initial, $target);

        if (!$this->skipPackageDeployment) {
            $targetStrategy = $this->getDeployStrategy($target);
            $targetStrategy->setMappings($this->getParser($target)->getMappings());
            $targetStrategy->deploy();
        }
        if ($target->getType() === 'magento-core') {
            $this->postUpdateMagentoCore();
        }
    }


    protected function preUpdateMagentoCore() {
        if (!$this->io->askConfirmation('<info>Are you sure you want to manipulate the Magento core installation</info> [<comment>Y,n</comment>]? ', true)) {
            $this->io->write('Skipping core update...');
            return false;
        }
        $tmpDir = $this->magentoRootDir->getPathname() . self::MAGENTO_ROOT_DIR_TMP_SUFFIX;
        $this->filesystem->ensureDirectoryExists($tmpDir);
        $this->originalMagentoRootDir = clone $this->magentoRootDir;
        $this->magentoRootDir = new \SplFileInfo($tmpDir);
        return true;
    }

    protected function postUpdateMagentoCore() {
        $tmpDir = $this->magentoRootDir->getPathname();
        $backupDir = $this->originalMagentoRootDir->getPathname() . self::MAGENTO_ROOT_DIR_BACKUP_SUFFIX;
        $this->backupMagentoRootDir = new \SplFileInfo($backupDir);

        $origRootDir = $this->originalMagentoRootDir->getPathName();
        $this->filesystem->rename($origRootDir, $backupDir);
        $this->filesystem->rename($tmpDir, $origRootDir);
        $this->magentoRootDir = clone $this->originalMagentoRootDir;
        
        $this->prepareMagentoCore();
        $this->cleanupPostUpdateMagentoCore();
    }

    protected function cleanupPostUpdateMagentoCore() {
        $rootDir = $this->magentoRootDir->getPathname();
        $backupDir = $this->backupMagentoRootDir->getPathname();
        $persistentFolders = array('media', 'var');
        copy($backupDir . DIRECTORY_SEPARATOR . $this->_magentoLocalXmlPath, $rootDir . DIRECTORY_SEPARATOR . $this->_magentoLocalXmlPath);
        foreach ($persistentFolders as $folder) {
            $this->filesystem->removeDirectory($rootDir . DIRECTORY_SEPARATOR . $folder);
            $this->filesystem->rename($backupDir . DIRECTORY_SEPARATOR . $folder, $rootDir . DIRECTORY_SEPARATOR . $folder);
        }
        if ($this->io->ask('Remove root backup? [Y,n] ', true)) {
            $this->filesystem->removeDirectory($backupDir);
            $this->io->write('Removed root backup!', true);
        } else {
            $this->io->write('Skipping backup removal...', true);
        }
        $this->clearMagentoCache();
    }

    public function toggleMagentoMaintenanceMode($active = false) {
        if (($targetDir = $this->getTargetDir()) && !$this->noMaintenanceMode) {
            $flagPath = $targetDir . DIRECTORY_SEPARATOR . self::MAGENTO_MAINTANANCE_FLAG;
            if ($active) {
                $this->io->write("Adding magento maintenance flag...");
                file_put_contents($flagPath, '*');
            } elseif (file_exists($flagPath)) {
                $this->io->write("Removing magento maintenance flag...");
                unlink($flagPath);
            }
        }
    }

    public function clearMagentoCache() {
        if (($targetDir = $this->getTargetDir()) && !$this->keepMagentoCache) {
            $magentoCachePath = $targetDir . DIRECTORY_SEPARATOR . self::MAGENTO_CACHE_PATH;
            if ($this->filesystem->removeDirectory($magentoCachePath)) {
                $this->io->write('Magento cache cleared');
            }
        }
    }

    /**
     * Uninstalls specific package.
     *
     * @param InstalledRepositoryInterface $repo    repository in which to check
     * @param PackageInterface             $package package instance
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        if (!$this->skipPackageDeployment) {
            $strategy = $this->getDeployStrategy($package);
            $strategy->setMappings($this->getParser($package)->getMappings());
            $strategy->clean();
        }

        parent::uninstall($repo, $package);
    }

    /**
     * Returns the modman parser for the vendor dir
     *
     * @param PackageInterface $package
     * @return Parser
     */
    public function getParser(PackageInterface $package)
    {
        $extra = $package->getExtra();

        if (isset($extra['map'])) {
            $parser = new MapParser($extra['map']);
            return $parser;
        } elseif (isset($extra['package-xml'])) {
            $parser = new PackageXmlParser($this->getSourceDir($package), $extra['package-xml']);
            return $parser;
        } elseif (file_exists($this->getSourceDir($package) . '/modman')) {
            $parser = new ModmanParser($this->getSourceDir($package));
            return $parser;
        } else {
            throw new \ErrorException('Unable to find deploy strategy for module: no known mapping');
        }

    }

    /**
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package)
    {

        if (!is_null($this->modmanRootDir) && true === $this->modmanRootDir->isDir()) {
            $targetDir = $package->getTargetDir();
            if (!$targetDir) {
                list($vendor, $targetDir) = explode('/', $package->getPrettyName());
            }
            $installPath = $this->modmanRootDir . '/' . $targetDir;
        } else {
            $installPath = parent::getInstallPath($package);
        }

        // Make install path absolute. This is needed in the symlink deploy strategies.
        if (DIRECTORY_SEPARATOR !== $installPath[0] && $installPath[1] !== ':') {
            $installPath = getcwd() . "/$installPath";
        }

        return $installPath;
    }

    /**
     * this function is for annoying people with messages.
     * 
     * First usage: get people to vote about the future release of composer so later I can say "you wanted it this way"
     * 
     * @param IOInterface $io
     */
    public function annoy(IOInterface $io)
    {
        $io->write('<comment> time for voting about the future of the #magento #composer installer. </comment>', true);
        $io->write('<comment> https://github.com/magento-hackathon/magento-composer-installer/blob/discussion-master/Milestone/2/index.md </comment>', true);
        $io->write('<error> For the case you don\'t vote, I will ignore your problems till iam finished with the resulting release. </error>', true);
    }
}
