<?php

interface router
{
    public function set_routes();
}


class hiveframe
{
    protected $state_holder = null;
    protected $prev_state = null;
    protected $data = null;
    protected $route = null;
    protected $options;

    private $enable_route = false;
    private $files;
    private $styles;
    private $js;
    private $routes;
    private $route_vars;
    private $uri = '';
    protected function __construct()
    {

        // Check if either router or view have been called
        // if not show error
        $is_instance = $this instanceof router;
        $view_exists = method_exists($this, 'view');
        if (!$is_instance && !$view_exists) {
            $className = get_class($this);

            echo "<div style='font-size:1.5rem; font-weight: bold; text-align:center; padding: 2rem;'>Either implement router interface or add view method to class ({$className})</div>";
        }
    }
    /**
     * Initiate function. This function is called in constructor function
     * in the main class. When routing is enabled all sub classes will
     * initiate the parent constructor which is not very performant. 
     * Therefore this function needs to be called once in the
     * entry class constructor.
     *
     * @return void
     */
    protected function init()
    {

        // Load directory files and styles into respective variable
        $this->getDirFiles();

        // Check getUri comments
        $this->uri = $this->getUri();

        $this->_set_options();

        // Autoload classes
        spl_autoload_register(array($this, 'require_files'));

        if ($this instanceof router) {
            $this->routes = $this->set_routes();
            $this->enable_route = true;

            if (method_exists($this, 'data'))
                $this->data = $this->data();

            if (method_exists($this, 'state'))
                $this->state_holder = $this->state($this->data);

            $this->include_routes();
        } else {
            echo $this->out();
        }
    }


    /**
     * Returns an array of various path information, including
     * the path of the calling class, the path of the main class,
     * the path of the main directory .. etc
     *
     * @param boolean $include_base
     * @return void
     */
    protected function get_path_info()
    {
        $extended_class = (new ReflectionClass(static::class))->getFileName();

        $path = array();
        $path['path'] = getcwd();
        $path['file_path'] = dirname(__FILE__);
        $path['uri'] = $this->uri;
        $path['class_name'] = get_class($this);
        $path['class_file_name'] = basename($extended_class);
        $path['class_dir'] = dirname($extended_class);
        $path['class_path'] = $extended_class;
        $path['base_url'] = $this->options['base_url'];

        return $path;
    }

    /**
     * Helper function to return an array of classes in link tag
     *
     * @return array Array of styles in current and sub directories
     */
    protected function get_styles()
    {
        if (!is_array($this->styles))
            $this->formatError("No style files were found");

        $styles = [];
        $base_url = $this->options['base_url'];

        foreach ($this->styles as $style) {
            $styles[] = "<link rel='stylesheet' type='text/css' href='{$base_url}/{$style}'>";
        }

        return $styles;
    }

    /**
     * Helper function to return an array of javascripts in script tag
     *
     * @return array Array of javascript in current and sub directories
     */
    protected function get_js()
    {
        if (!is_array($this->js))
            $this->formatError("No javascript files were found");

         $js = [];
        $base_url = $this->options['base_url'];

        // Get only files requested by user
        $files = func_get_args();
        if (is_array($files)) {
          
            foreach($files as $file)
            {
                $js_file = $this->js[$file.".js"];
                $js[] = "<script src='{$base_url}/{$js_file}'></script>";
            }
            return $js;
        }
        
        foreach ($this->js as $js_file) {
            $js[] = "<script src='{$base_url}/{$js_file}'></script>";
        }

        return $js;
    }


    /**
     * Used by subclasses to include componenets. This function faciltate the process
     * of including and instatiating the classes when requested.
     *
     * @return void
     */
    protected function include()
    {
        $components = func_get_args();
        if (!is_array($components)) {
            return;
        }

        $state = null;

        // Call current class state to pass it to view method
        // in "child" classes
        if (method_exists($this, 'state'))
            $state = $this->state($this->data);

        foreach ($components as $key) {
            $class = new $key();
            $class->set_state($state);

            /* Object propety waterfall. 
            * This helps cloning propetries from parent class 
            * to any extended class
            */
            $class->options = $this->options;
            $class->uri = $this->uri;
            $class->styles = $this->styles;
            $class->js = $this->js;

            // Assign prev_state the current state, 
            // This is used for nested components
            $class->prev_state = $this->state_holder;
            
            $this->{$key} = $class->out();
        }
    }

    protected function get_state(){
        return $this->prev_state;
    }

    protected function get_route_vars(){
        return $this->route_vars;
    }
    /**
     * Similar to include() but used when routing is enabled.
     *
     * @param array $routes
     * @return void
     */
    private function include_routes()
    {
        if (!is_array($this->routes)) {
            return;
        }
       
        $sorted_routes = $this->sort_routes();
        $route = $this->filter_routes($sorted_routes);
        
        if ($route == false) {
            $this->formatError("Route is not set for " . $this->uri);
            return;
        }
        
        define("BEEFRAME", "INIT");
        $this->route_key = $route;
        $class = new $route();
        
       
        /* Object propety waterfall. 
        * This helps cloning propetries from parent class 
        * to any extended class
        */
        $class->options = $this->options;
        $class->uri = $this->uri;
        $class->styles = $this->styles;
        $class->js = $this->js;
        $class->prev_state = $this->state_holder;
        $class->route_vars = $this->route_vars;
        
        if (method_exists($class, 'data'))
        $class->data = $class->data();
       
     if (method_exists($this, 'state'))
        $class->state_holder = $this->state($class->data);

        echo $class->view($this->state_holder, $class->data);
    }

    private function _set_options()
    {
        // Get base url
        $protocol = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $base_url = $protocol . $_SERVER['HTTP_HOST'];

        // set dir path
        $dir_path = getcwd();

        // creat options array
        $this->options = array('base_url' => $base_url . "/" . basename($dir_path), 'dir_path' => $dir_path);

        // overwrite if use called set_options method
        $options = [];
        if (method_exists($this, 'set_options'))
            $options = $this->set_options();


        $this->options = array_merge($this->options, $options);
    }

    /**
     * Sorts routes array before matching. set_routes() returns
     * routes keys with either array or string values. This method
     * process the array and return a string value array. 
     * The string value would a regex 
     *
     * @return array
     */
    private function sort_routes()
    {
        $filtered_routes = [];
        foreach ($this->routes as $r => $v) {
            $value = (is_array($v)) ? $v['route'] : $v;
            $filtered_routes[$r] = $value;
        }

        // Sort by value length, the result will be shortest to longest
        array_multisort(array_map('strlen', $filtered_routes), $filtered_routes);

        // reverse the resulting array, we need to match 
        // the most specific (longer) router first
        $filtered_routes = array_reverse($filtered_routes);

        // return array with regexp format and vars
        return array_map(array($this, 'getRouteReg'), $filtered_routes);
    }
    /**
     * Autoload class functions
     *
     * @param String $class_name
     * @return void
     */
    private function require_files($class_name)
    {
        $path = $this->getClassPath($class_name);

        if ($path != false)
            require $path;
        else
            new Exception("Class " . $class_name . " could not be found");
    }

    private function filter_routes($routes)
    {

        $result = false;
        foreach ($routes as $key => $array) {
            $route_reg = $array['route_reg'];

            $result = preg_match($route_reg, trim($this->uri, "/"), $matches);
            
            if ($result == true)
            {
                array_shift($matches);
                $this->set_route_vars($array['vars'], $matches);
                return $key;
            }
        }

        return $result;
    }

    /**
     * Undocumented function
     *
     * @param [type] $vars
     * @param [type] $matches
     * @return void
     */
    private function set_route_vars($vars, $matches){
     /*   echo "<pre>";

         print_r($vars);
        print_r($matches);
*/
        $this->route_vars = array_combine($vars, $matches);
    }
    /**
     * Set state for subclass to be used inside view function
     *
     * @param mixed $new_state
     * @return void
     */
    private function set_state($new_state)
    {
        $this->state_holder = $new_state;
    }

    /**
     * Get uri value. uri paramater exists is in url before re-writing.
     * for Example url like this: www.example.com?uri=/posts/action/5 will be
     * re-written to www.example.com/posts/action/5. This function will return
     * the uri paramter or "/" if nothing is set
     *
     * @return string uri
     */
    private function getUri()
    {
        return isset($_GET['uri']) ? "/" . $_GET['uri'] : "/";
    }

    /**
     * This function will parse route paramters into a regexp string.
     * For Example this route: /post/:action/#id will translate to
     * /^post\/\w+\/\d+
     *
     * @param string $route
     * @return void
     */
    private function getRouteReg($route)
    {
        $params = explode("/", $route);
        $new_params = [];
        $vars = [];

        // Remove empty param
        array_shift($params);
        foreach ($params as $key) {

            if (strpos($key, "#") > -1)
            {
                $new_params[] = '([0-9]+)';
                $vars[] = str_replace("#", "", $key);
            }
            else if (strpos($key, ":") > -1)
            {
                $new_params[] = '([a-zA-Z]+)';
                $vars[] = str_replace(":", "", $key);
            }
            else
            {
                $new_params[] = $key;
            }
        }

        return array(
            'route_reg' => "/^" . implode("\\/", $new_params) . "$/",
            'vars' => $vars
    );
    }

    /**
     * Process subclass data and pass it to view function.
     * Then return the resulting html
     *
     * @return string The html view
     */
    private function out()
    {
        if (method_exists($this, 'data'))
            $this->data = $this->data();


        $view = $this->view($this->state_holder, $this->data);
        return $view;
    }

    /**
     * This function displays an error and throws an exception.
     * The error display is formatted using direct styling as 
     * stylesheet will not be available
     *
     * @param string $error_string The error string
     * @return void
     */
    private function formatError($error_string)
    {
        echo "<div style='font-size:1.3rem; font-weight: bold; text-align:center; padding: 1.3rem;'>{$error_string}</div>";

        throw new Exception($error_string);
    }

    /**
     * Get files from all directories and sub-directories.
     * List all PHP and CSS files for later inclusing
     *
     * @return void
     */
    private function getDirFiles()
    {
        $dirIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(getcwd()));
        
        $files = array();
        $styles = array();
        $js = array();

        foreach ($dirIterator as $file) {
            $filePath = '';
            if($file->isDir())
                continue;

            $base_name = $file->getBasename();
            $path_name = $file->getPathname();

            if ($file->getExtension() == 'php') {
                $filePath = $path_name;
                $filePath = ltrim($filePath, DIRECTORY_SEPARATOR);
                $files[$base_name] = $filePath;
            } else if ($file->getExtension() == 'css') {
                $filePath = str_replace(getcwd(), "", $path_name);
                $filePath = ltrim($filePath, DIRECTORY_SEPARATOR);
                $styles[$base_name] = str_replace("\\", "/", $filePath);
            } else if ($file->getExtension() == 'js') {
                $filePath = str_replace(getcwd(), "", $path_name);
                $filePath = ltrim($filePath, DIRECTORY_SEPARATOR);
                $js[$base_name] = str_replace("\\", "/", $filePath);
            }
        }
    
        $this->files = $files;
        $this->styles = $styles;
        $this->js = $js;
        
   
    }

    /**
     * Retrive certain class path for a php file when 
     * requested. Mostly for auto load function
     *
     * @param string The file name
     * @return void
     */
    private function getClassPath($name)
    {
        if (array_key_exists($name . ".php", $this->files))
            return $this->files[$name . ".php"];

        return false;
    }
}
