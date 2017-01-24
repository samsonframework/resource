<?php declare(strict_types=1);
/**
 * Created by Vitaly Iegorov <egorov@samsonos.com>
 * on 24.07.14 at 17:06
 */
namespace samsonframework\resource;

// TODO: Remove File dependency
use samson\core\File;
use samsonframework\core\ResourcesInterface;
use samsonframework\filemanager\FileManagerInterface;
use samsonframework\localfilemanager\LocalFileManager;

/**
 * Generic class to manage all web-application resources
 * @author Vitaly Egorov <egorov@samsonos.com>
 * @copyright 2014 SamsonOS
 * @deprecated
 */
class ResourceMap implements ResourcesInterface
{
    /** Number of lines to read in file to determine its PHP class */
    const CLASS_FILE_LINES_LIMIT = 100;

    /** RegExp for namespace definition matching */
    const NAMESPACE_DEFINITION_PATTERN =
        '/^\s*namespace\s+(?<namespace>[^;]+)/iu';

    /** RegExp for use definition matching */
    const USE_DEFINITION_PATTERN =
        '/^\s*use\s+(?<class>[^\s;]+)(\s+as\s+(?<alias>[^;]+))*/ui';

    /** RegExp for class definition matching */
    const CLASS_DEFINITION_PATTERN =
        '/^\s*(abstract\s*)?class\s+(?<class>[a-z0-9_]+)(\s+(extends)\s+(?<parent>[a-z0-9\\\\]+))?(\s+(implements)\s+(?<implements>[a-z0-9_\\\\, ]+))?/iu';

    /** @var array Collection of classes that are Module ancestors */
    public static $moduleAncestors = array(
        '\samson\core\CompressableExternalModule' => 'CompressableExternalModule',
        '\samson\core\ExternalModule' => 'ExternalModule',
        '\samson\core\Service' => 'Service',
        '\samson\core\CompressableService' => 'CompressableService',
        '\samsoncms\Application'=>'Application'
    );

    /** @var ResourceMap[] Collection of ResourceMaps gathered by entry points */
    public static $gathered = array();

    /**
     * Try to find ResourceMap by entry point
     *
     * @param string $entryPoint Path to search for ResourceMap
     * @param ResourceMap $pointer Variable where found ResourceMap will be returned
     *
     * @return bool True if ResourceMap is found for this entry point
     */
    public static function find($entryPoint, & $pointer = null)
    {
        // Pointer to find ResourceMap for this entry point
        $tempPointer = &self::$gathered[$entryPoint];

        // If we have already build resource map for this entry point
        if (isset($tempPointer)) {
            // Return pointer value
            $pointer = $tempPointer;

            return true;
        }

        return false;
    }

    /**
     * Find ResourceMap by entry point or create a new one.
     *
     * @param string $entryPoint Path to search for ResourceMap
     * @param bool $force Flag to force rebuilding Resource map from entry point
     * @param array $ignoreFolders Collection of folders to ignore
     * @return ResourceMap Pointer to ResourceMap object for passed entry point
     */
    public static function &get($entryPoint, $force = false, $ignoreFolders = array())
    {
        /** @var ResourceMap $resourceMap Pointer to resource map */
        $resourceMap = null;

        // If we have not already scanned this entry point or not forced to do it again
        if (!self::find($entryPoint, $resourceMap)) {
            // Create new resource map for this entry point
            $resourceMap = new ResourceMap(new LocalFileManager());
            $resourceMap->prepare($entryPoint, $ignoreFolders);

            // Build ResourceMap for this entry point
            $resourceMap->build($entryPoint);

        } elseif ($force) { // If we have found ResourceMap for this entry point but we forced to rebuild it
            $resourceMap->build($entryPoint);
        }

        return $resourceMap;
    }

    /** @var string  Resource map entry point */
    public $entryPoint;

    /** @var array Collection of gathered resources grouped by extension */
    public $resources = array();

    /** @var  array Collection of controllers actions by entry point */
    public $controllers = array();

    /** @var array Path to \samson\core\Module ancestor */
    public $module = array();

    /** @var array Collection of \samson\core\Module ancestors */
    public $modules = array();

    /** @var  array Collection of old-fashion global namespace module files by entry point */
    public $globals = array();

    /** @var  array Old-fashion model files collection by entry point */
    public $models = array();

    /** @var  array Collection of views by entry point */
    public $views = array();

    /** @var  array Collection of classes by entry point */
    public $classes = array();

    /** @var array Collection of className => class metadata */
    public $classData = array();

    /** @var array Collection of CSS resources */
    public $css = array();

    /** @var array Collection of LESS resources */
    public $less = array();

    /** @var array Collection of SASS resources */
    public $sass = array();

    /** @var array Collection of JS resources */
    public $js = array();

    /** @var array Collection of other PHP resources */
    public $php = array();

    /** @var array Collection of COFFEE resources */
    public $coffee = array();

    /** @var array Collection of folders that should be ignored in anyway */
    public $ignoreFolders = array(
        '.svn/',
        '.git/',
        '.idea/',
        'app/cache/',
        'tests/',
        'vendor/',
        'app/config/',
        'www/cms/',
        'out/',
        'features/',
        'ci/'
    );

    /** @var array Collection of files that must be ignored by ResourceMap */
    public $ignoreFiles = array(
        'phpunit.php',
        '.travis.yml',
        'phpunit.xml',
        'composer.lock',
        'license.md',
        '.gitignore',
        '.readme.md',
    );

    /** @var FileManagerInterface */
    protected $fileManager;

    /**
     * ResourceMap constructor.
     *
     * @param FileManagerInterface $fileManager
     */
    public function __construct(FileManagerInterface $fileManager)
    {
        $this->fileManager = $fileManager;
    }

    /**
     * Prepare ignorance folders
     * @param string $entryPoint Top level file path for scanning
     * @param array $ignoreFolders Collection of folders to be ignored in ResourceMap
     * @param array $ignoreFiles Collection of files to be ignored in ResourceMap
     */
    public function prepare($entryPoint, array $ignoreFolders = array(), array $ignoreFiles = array())
    {
        // Use only real paths
        $this->entryPoint = rtrim($entryPoint, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        // Combine passed folders to ignore with the default ones
        $ignoreFolders = array_merge($this->ignoreFolders, $ignoreFolders);
        // Clear original ignore folders collection
        $this->ignoreFolders = array();
        foreach ($ignoreFolders as $folder) {
            // Build path to folder at entry point
            $folder = realpath($this->entryPoint . $folder);
            // If path not empty - this folder exists
            if (isset($folder{0}) && is_dir($folder)) {
                $this->ignoreFolders[] = $folder;
            }
        }

        // Combine passed files to ignore with the default ones
        $this->ignoreFiles = array_merge($this->ignoreFiles, $ignoreFiles);

        // Store current ResourceMap in ResourceMaps collection
        self::$gathered[$this->entryPoint] = &$this;
    }

    /**
     * Determines if file is a class
     *
     * @param string $path Path to file for checking
     * @param string $class Variable to return full class name with name space
     * @param string $extends Variable to return parent class name
     *
     * @return bool True if file is a class file
     */
    public function isClass($path, &$class = '', &$extends = '')
    {
        // Class name space, by default - global namespace
        $namespace = '\\';
        // Open file handle for reading
        $file = fopen($path, 'r');
        // Uses class collection for correct class names
        $usesAliases = array();
        $usesNamespaces = array();
        // Read lines from file
        for ($i = 0; $i < self::CLASS_FILE_LINES_LIMIT; $i++) {
            // Read one line from a file
            $line = fgets($file);

            // Stop reading if file ended
            if ($line === false) {
                break;
            }

            $matches = array();

            // Read one line from a file and try to find namespace definition
            if ($namespace == '\\' && preg_match(self::NAMESPACE_DEFINITION_PATTERN, $line, $matches)) {
                $namespace .= $matches['namespace'] . '\\';
                // Try to find use statements
            } elseif (preg_match(self::USE_DEFINITION_PATTERN, $line, $matches)) {
                // Get only class name without namespace
                $useClass = substr($matches['class'], strrpos($matches['class'], '\\') + 1);
                // Store alias => full class name collection
                if (isset($matches['alias'])) {
                    $usesAliases[$matches['alias']] = $matches['class'];
                }
                // Store class name => full class name collection
                $usesNamespaces[$useClass] = ($matches['class']{0} == '\\' ? '' : '\\') . $matches['class'];
                // Read one line from a file and try to find class pattern
            } elseif (preg_match(self::CLASS_DEFINITION_PATTERN, $line, $matches)) {
                // Store module class name
                $class = $namespace . trim($matches['class']);

                // Create class metadata instance
                $this->classData[$path] = [
                    'path' => $path,
                    'className' => $class,
                ];
                
                // Handle implements interfaces
                if (array_key_exists('implements', $matches)) {
                    // Store implementing interface
                    $implements = explode(',', trim($matches['implements']));

                    $this->classData[$path]['implements'] = [];

                    foreach ($implements as $implement) {
                        $implement = trim($implement);
                        // If we have alias for this class
                        if (isset($usesAliases[$implement])) {
                            // Get full class name
                            $implement = $usesAliases[$implement];
                            // Get full class name
                        } elseif (isset($usesNamespaces[$implement])) {
                            $implement = $usesNamespaces[$implement];
                            // If there is no namespace
                        } elseif (strpos($implement, '\\') === false) {
                            $implement = $namespace . $implement;
                        }

                        $this->classData[$path]['implements'][] = $implement;
                    }
                }

                if (array_key_exists('parent', $matches)) {
                    // Store parent class
                    $extends = trim($matches['parent']);

                    // If we have alias for this class
                    if (isset($usesAliases[$extends])) {
                        // Get full class name
                        $extends = $usesAliases[$extends];
                        // Get full class name
                    } elseif (isset($usesNamespaces[$extends])) {
                        $extends = $usesNamespaces[$extends];
                        // If there is no namespace
                    } elseif (strpos($extends, '\\') === false) {
                        $extends = $namespace . $extends;
                    }

                    $this->classData[$path]['extends'] = $extends;

                    // Define if this class is Module ancestor
                    if (array_key_exists($extends, self::$moduleAncestors)) {
                        // Save class as module ancestor
                        self::$moduleAncestors[$class] = $matches['class'];
                        // Completed my sir!
                        return true;
                    }
                }

                // Add class to classes array
                $this->classes[$path] = $class;

                return false;
            }
        }

        return false;
    }

    /**
     * Determines if file is an SamsonPHP view file
     * @param string $path Path to file for checking
     * @return bool True if file is a SamsonPHP view file
     */
    public function isView($path)
    {
        // Try to match using old-style method by location and using new style by extension
        return strpos($path, 'app/view/') !== false || strpos($path, '.vphp') !== false;
    }

    /**
     * Determines if file is an SamsonPHP Module Class ancestor file
     *
     * @param string $path Path to file for checking
     * @param string $class Variable to return module controller class name
     * @param string $extends Variable to return parent class name
     *
     * @return bool True if file is a SamsonPHP view file
     */
    public function isModule($path, & $class = '', & $extends = '')
    {
        // If this is a .php file
        if (strpos($path, '.php') !== false && $this->isClass($path, $class, $extends)) {
            // Check if this is not a SamsonPHP core class
            if (strpos('CompressableExternalModule, ExternalModule, Service, CompressableService', str_replace('\samson\core\\', '', $class)) === true) {
                return false;
            } elseif (in_array($extends, array_keys(self::$moduleAncestors))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determines if file is an SamsonPHP model file
     * @param string $path Path to file for checking
     * @return bool True if file is a SamsonPHP model file
     */
    public function isModel($path)
    {
        // Try to match using old-style method by location
        return strpos($path, 'app/model/') !== false;
    }

    /**
     * Determines if file is an PHP file
     * @param string $path Path to file for checking
     * @return bool True if file is a PHP  file
     */
    public function isPHP($path)
    {
        // Just match file extension
        return strpos($path, '.php') !== false;
    }

    /**
     * Determines if file is an SamsonPHP global namespace file
     * @param string $path Path to file for checking
     * @return bool True if file is a SamsonPHP global namespace file
     */
    public function isGlobal($path)
    {
        // Check old-style by file name
        return basename($path, '.php') == 'global';
    }

    /**
     * Determines if file is an SamsonPHP controller file
     * @param string $path Path to file for checking
     * @return bool True if file is a SamsonPHP view file
     */
    public function isController($path)
    {
        // Check old-style by location and new-style function type by file name
        return strpos($path, 'app/controller/') !== false || basename($path, '.php') == 'controller';
    }

    /**
     * Convert Resource map to old-style "load_stack_*" format
     * @deprecated
     * @return array Collection of resources in old format
     */
    public function toLoadStackFormat()
    {
        return array(
            'resources' => $this->resources,
            'modules' => $this->module,
            'controllers' => $this->controllers,
            'models' => $this->models,
            'views' => $this->views,
            'php' => array_merge($this->php, $this->globals)
        );
    }

    /**
     * Perform resource gathering starting from $path entry point
     *
     * @param string $path Entry point to start scanning resources
     *
     * @return bool True if we had no errors on building path resource map
     * @throws \InvalidArgumentException
     */
    public function build($path = null)
    {
        // Validate path
        if ($path !== null && !file_exists($path)) {
            throw new \InvalidArgumentException('Path ['.$path.'] does not exists');
        }

        // If no other path is passed use current entry point and convert it to *nix path format
        $path = $path ?? $this->entryPoint;

        // Store new entry point
        $this->entryPoint = $path;

        // Collect all resources from entry point
        foreach ($this->fileManager->scan([$this->entryPoint], [], $this->ignoreFolders) as $file) {
            // Get real path to file
            $file = realpath($file);

            // Check if this file does not has to be ignored
            if (!in_array(basename($file), $this->ignoreFiles)) {
                // Class name
                $class = '';

                // Parent class
                $extends = '';

                // We can determine SamsonPHP view files by 100%
                if ($this->isView($file)) {
                    $this->views[] = $file;
                } elseif ($this->isGlobal($file)) {
                    $this->globals[] = $file;
                } elseif ($this->isModel($file)) {
                    $this->models[] = $file;
                } elseif ($this->isController($file)) {
                    $this->controllers[] = $file;
                } elseif ($this->isModule($file, $class, $extends)) {
                    $this->module = array($class, $file, $extends);
                    $this->modules[] = array($class, $file, $extends);
                } elseif ($this->isPHP($file)) {
                    $this->php[] = $file;
                } else { // Save resource by file extension
                    // Get extension as resource type
                    $rt = pathinfo($file, PATHINFO_EXTENSION);

                    // Check if resource type array cell created
                    if (!isset($this->resources[$rt])) {
                        $this->resources[$rt] = array();
                    }

                    // Add resource to collection
                    $this->resources[$rt][] = $file;
                }
            }
        }

        // Iterate all defined object variables
        foreach (array_keys(get_object_vars($this)) as $var) {
            // If we have matched resources with that type
            if (isset($this->resources[$var])) {
                // Bind object variable to resources collection
                $this->$var = &$this->resources[$var];
            }
        }

        return true;

    }
}
