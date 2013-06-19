<?php
/**
 * Whoops - php errors for cool kids
 * @author Filipe Dobreira <http://github.com/filp>
 */

namespace Whoops\Handler;
use Whoops\Handler\Handler;
use Whoops\Util\TemplateEngine;
use Whoops\Util\ShallowAssetCompiler;
use Whoops\Util\VariableDumper;
use InvalidArgumentException;

class PrettyPageHandler extends Handler
{
    /**
     * @var string
     */
    private $resourcesPath;

    /**
     * @var array[]
     */
    private $extraTables = array();

    /**
     * @var string
     */
    private $pageTitle = 'Whoops! There was an error.';

    /**
     * A string identifier for a known IDE/text editor, or a closure
     * that resolves a string that can be used to open a given file
     * in an editor. If the string contains the special substrings
     * %file or %line, they will be replaced with the correct data.
     *
     * @example
     *  "txmt://open?url=%file&line=%line"
     * @var mixed $editor
     */
    protected $editor;

    /**
     * A list of known editor strings
     * @var array
     */
    protected $editors = array(
        'sublime'  => 'subl://open?url=file://%file&line=%line',
        'textmate' => 'txmt://open?url=file://%file&line=%line',
        'emacs'    => 'emacs://open?url=file://%file&line=%line',
        'macvim'   => 'mvim://open/?url=file://%file&line=%line'
    );

    /**
     * Constructor.
     */
    public function __construct()
    {
        if (extension_loaded('xdebug')) {
            // Register editor using xdebug's file_link_format option.
            $this->editors['xdebug'] = function($file, $line) {
                return str_replace(array('%f', '%l'), array($file, $line), ini_get('xdebug.file_link_format'));
            };
        }
    }

    /**
     * @return int|null
     */
    public function handle()
    {
        // Check conditions for outputting HTML:
        // @todo: make this more robust
        if(php_sapi_name() === 'cli' && !isset($_ENV['whoops-test'])) {
            return Handler::DONE;
        }

        // Get a Template Engine instance to manage rendering
        // the error display's components:
        $templateEngine = new TemplateEngine;
        $this->setupDefaultVariableDumpers($templateEngine->getVariableDumper());

        // Get the 'pretty-template.php' template file
        // @todo: Integrate with TemplateEngine
        if(!($resources = $this->getResourcesPath())) {
            $resources = __DIR__ . '/../Resources';

            $templateEngine->addSearchPath($resources);
        }

        $stylesheet = $templateEngine->getAssetCompiler()->compileCssResources(
            array(
                $templateEngine->getResource("css/whoops.base.css")
            )
        );

        // Prepare the $v global variable that will pass relevant
        // information to the template
        $inspector = $this->getInspector();
        $frames    = $inspector->getFrames();

        $v = array(
            'title'        => $this->getPageTitle(),
            'name'         => $inspector->getExceptionName(),
            'message'      => $inspector->getException()->getMessage(),
            'frames'       => $frames,
            'hasFrames'    => !!count($frames),
            'handler'      => $this,
            'handlers'     => $this->getRun()->getHandlers(),
            'stylesheet'  => $stylesheet,

            'tables'      => array(
                'Server/Request Data'   => $_SERVER,
                'GET Data'              => $_GET,
                'POST Data'             => $_POST,
                'Files'                 => $_FILES,
                'Cookies'               => $_COOKIE,
                'Session'               => isset($_SESSION) ? $_SESSION:  array(),
                'Environment Variables' => $_ENV
            )
        );

        $extraTables = array_map(function($table) {
            return $table instanceof \Closure ? $table() : $table;
        }, $this->getDataTables());

        // Add extra entries list of data tables:
        $v["tables"] = array_merge($extraTables, $v["tables"]);

        $templateEngine->executeTemplate("views/error.html.php", $v);

        return Handler::QUIT;
    }

    /**
     * Registers the default variable dumpers available to whoops, which
     * cover a basic scenario for all possible types. Extensions can
     * add more granular dumpers, which will have precedence.
     * 
     * @param Whoops\Util\VariableDumper
     */
    private function setupDefaultVariableDumpers(VariableDumper $variableDumper)
    {
        $dumpers = array(
            // Match all variables:
            array(
                "whoops.generic", "views/dumper/generic.html.php",
                VariableDumper::MATCH_ALL, null
            ),

            // Match arrays:
            array(
                "whoops.array", "views/dumper/array.html.php",
                VariableDumper::MATCH_EQUAL, "array"
            ),

            // Match objects:
            array(
                "whoops.object", "views/dumper/object.html.php",
                VariableDumper::MATCH_EQUAL, "object"
            ),

            // Match whoops handlers:
            array(
                "whoops.handler", "views/dumper/whoops_handler.html.php",
                VariableDumper::MATCH_CLOSURE, function($variable) {
                    return is_subclass_of($variable, "Whoops\\Handler\\HandlerInterface");
                }
            )
        );

        $variableDumper->addDumpers($dumpers);
    }

    /**
     * Adds an entry to the list of tables displayed in the template.
     * The expected data is a simple associative array. Any nested arrays
     * will be flattened with print_r
     * @param string $label
     * @param array  $data
     */
    public function addDataTable($label, array $data)
    {
        $this->extraTables[$label] = $data;
    }

    /**
     * Lazily adds an entry to the list of tables displayed in the table.
     * The supplied callback argument will be called when the error is rendered,
     * it should produce a simple associative array. Any nested arrays will
     * be flattened with print_r.
     * @param string   $label
     * @param callable $callback Callable returning an associative array
     */
    public function addDataTableCallback($label, /* callable */ $callback)
    {
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Expecting callback argument to be callable');
        }

        $this->extraTables[$label] = function() use ($callback) {
            try {
                $result = call_user_func($callback);

                // Only return the result if it can be iterated over by foreach().
                return is_array($result) || $result instanceof \Traversable ? $result : array();
            } catch (\Exception $e) {
                // Don't allow failiure to break the rendering of the original exception.
                return array();
            }
        };
    }

    /**
     * Returns all the extra data tables registered with this handler.
     * Optionally accepts a 'label' parameter, to only return the data
     * table under that label.
     * @param string|null $label
     * @return array[]
     */
    public function getDataTables($label = null)
    {
        if($label !== null) {
            return isset($this->extraTables[$label]) ?
                   $this->extraTables[$label] : array();
        }

        return $this->extraTables;
    }

    /**
     * Adds an editor resolver, identified by a string
     * name, and that may be a string path, or a callable
     * resolver. If the callable returns a string, it will
     * be set as the file reference's href attribute.
     *
     * @example
     *  $run->addEditor('macvim', "mvim://open?url=file://%file&line=%line")
     * @example
     *   $run->addEditor('remove-it', function($file, $line) {
     *       unlink($file);
     *       return "http://stackoverflow.com";
     *   });
     * @param  string $identifier
     * @param  string $resolver
     */
    public function addEditor($identifier, $resolver)
    {
        $this->editors[$identifier] = $resolver;
    }

    /**
     * Set the editor to use to open referenced files, by a string
     * identifier, or a callable that will be executed for every
     * file reference, with a $file and $line argument, and should
     * return a string.
     *
     * @example
     *   $run->setEditor(function($file, $line) { return "file:///{$file}"; });
     * @example
     *   $run->setEditor('sublime');
     *
     * @param string|callable $editor
     */
    public function setEditor($editor)
    {
        if(!is_callable($editor) && !isset($this->editors[$editor])) {
            throw new InvalidArgumentException(
                "Unknown editor identifier: $editor. Known editors:" .
                implode(",", array_keys($this->editors))
            );
        }

        $this->editor = $editor;
    }

    /**
     * Given a string file path, and an integer file line,
     * executes the editor resolver and returns, if available,
     * a string that may be used as the href property for that
     * file reference.
     *
     * @param  string $filePath
     * @param  int    $line
     * @return string|false
     */
    public function getEditorHref($filePath, $line)
    {
        if($this->editor === null) {
            return false;
        }

        $editor = $this->editor;
        if(is_string($editor)) {
            $editor = $this->editors[$editor];
        }

        if(is_callable($editor)) {
            $editor = call_user_func($editor, $filePath, $line);
        }

        // Check that the editor is a string, and replace the
        // %line and %file placeholders:
        if(!is_string($editor)) {
            throw new InvalidArgumentException(
                __METHOD__ . " should always resolve to a string; got something else instead"
            );
        }

        $editor = str_replace("%line", rawurlencode($line), $editor);
        $editor = str_replace("%file", rawurlencode($filePath), $editor);

        return $editor;
    }

    /**
     * @var string
     */
    public function setPageTitle($title)
    {
        $this->pageTitle = (string) $title;
    }

    /**
     * @return string
     */
    public function getPageTitle()
    {
        return $this->pageTitle;
    }

    /**
     * @return string
     */
    public function getResourcesPath()
    {
        return $this->resourcesPath;
    }

    /**
     * @param string $resourcesPath
     */
    public function setResourcesPath($resourcesPath)
    {
        if(!is_dir($resourcesPath)) {
            throw new InvalidArgumentException(
                "$resourcesPath is not a valid directory"
            );
        }

        $this->resourcesPath = $resourcesPath;
    }
}
