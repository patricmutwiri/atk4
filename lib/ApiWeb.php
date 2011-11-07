<?php // vim:ts=4:sw=4:et:fdm=marker
/**
 * ApiWeb extends an api of CommandLine applications with knowlnedge of HTML
 * templates, understanding of pages and routing.
 * 
 * @link http://agiletoolkit.org/learn/understand/api
 * @link http://agiletoolkit.org/learn/template
*//*
==ATK4===================================================
   This file is part of Agile Toolkit 4 
    http://agiletoolkit.org/
  
   (c) 2008-2011 Romans Malinovskis <atk@agiletech.ie>
   Distributed under Affero General Public License v3
   
   See http://agiletoolkit.org/about/license
 =====================================================ATK4=*/
class ApiWeb extends ApiCLI {

    /** Cleaned up name of the currently requested page */
    public $page=null;
    
    /** ?? */
    protected $page_base=null;

    /* @obsolete - page where user is redirected after log-in */
    public $index_page='index';

    /** recorded time when execution has started */
    public $start_time=null;

    // {{{ Start-up 
    function __construct($realm=null,$skin='default'){
        $this->start_time=time()+microtime();

        $this->skin=$skin;
        try {
            parent::__construct($realm);
        }catch (Exception $e){

            // This exception is used to abort initialisation of the objects but when
            // normal rendering is still required
            if($e instanceof Exception_StopInit)return;

            $this->caughtException($e);
        }
    }
    /** Redifine this function instead of default constructor */
    function init(){
        // Do not initialize unless requsetd
        //$this->initializeSession();

        // find out which page is to display
        //$this->calculatePageName();
        $this->add('PageManager');

        // send headers, no caching
        $this->sendHeaders();

        parent::init();

        /** In addition to default initialization, set up logger and template */
        $this->getLogger();
        $this->initializeTemplate();


        if(get_class($this)=='ApiWeb'){
            $this->setConfig(array('url_postfix'=>'.php','url_prefix'=>''));
        }
    }
    /** Sends default headers. Re-define to send your own headers */
    function sendHeaders(){
        header("Content-Type: text/html; charset=utf-8");
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");               // Date in the past
        header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");  // always modified
        header("Cache-Control: no-store, no-cache, must-revalidate");   // HTTP/1.1
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");                                     // HTTP/1.0
    }
    /** Call this method if you want to see execution time on the bottom of your pages */
    function showExecutionTime(){
        $self=$this;
        $this->addHook('post-render-output',array($this,'_showExecutionTime'));
        $this->addHook('post-js-execute',array($this,'_showExecutionTimeJS'));
    }
    /** @ignore */
    function _showExecutionTime(){
        echo 'Took '.(time()+microtime()-$this->start_time).'s';
    }
    /** @ignore */
    function _showExecutionTimeJS(){
        echo "\n\n/* Took ".number_format(time()+microtime()-$this->start_time,5).'s */';
    }
    /** If version tag is defined in template, inserts current version of Agile Toolkit there. If newer verison is available, it will be reflected */
    function upgradeChecker(){
        // Checks for ATK upgrades and shows current version
        if($this->template && $this->template->is_set('version')){
            $this->add('UpgradeChecker',null,'version');
        }
    }
    // }}}

    // {{{ Obsolete
    /** @obsolete */
    function caughtException($e){
        $this->hook('caught-exception',array($e));
        echo "<font color=red>",$e,"</font>";
        echo "<p>Please use 'Logger' class for more sophisticated output<br>\$api-&gt;add('Logger');</p>";
        exit;
    }

    /** @obsolete */
    function outputWarning($msg,$shift=0){
        if($this->hook('output-warning',array($msg,$shift)))return true;
        echo "<font color=red>",$msg,"</font>";
    }
    /** @obsolete */
    function outputDebug($msg,$shift=0){
        if($this->hook('output-debug',array($msg,$shift)))return true;
        echo "<font color=red>",$msg,"</font><br>";
    }
    /** @obsolete */
    function outputInfo($msg,$shift=0){
        if($this->hook('output-info',array($msg,$shift)))return true;
        echo "<font color=red>",$msg,"</font>";
    }
    // }}}

    // {{{ Session Initialization
    /** Initializes existing or new session */
    public $_is_session_initialized=false;
    function initializeSession($create=true){
        /* Attempts to re-initialize session. If session is not found,
           new one will be created, unless $create is set to false. Avoiding
           session creation and placing cookies is to enhance user privacy. 
           Call to memorize() / recall() will automatically create session */

        if($this->_is_session_initialized)return;

        if(isset($_GET['SESSION_ID']))session_id($_GET['SESSION_ID']);


        // Change settings if defined in settings file
        $params=session_get_cookie_params();

        $params['httponly']=true;   // true by default

        foreach($params as $key=>$default){
            $params[$key]=$this->api->getConfig('session/'.$key,$default);
        }

        if($create==false && !isset($_COOKIE[$this->name]))return;
        $this->_is_session_initialized=true;
        session_set_cookie_params(
                $params['lifetime'],
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
                );
        session_name($this->name);
        session_start();
    }
    // }}}

    // {{{ Sticky GET Argument implementation. Register stickyGET to have it appended to all generated URLs
    protected $sticky_get_arguments = array();
    /** Make current get argument with specified name automatically appended to all generated URLs */
    function stickyGET($name){
        $this->sticky_get_arguments[$name]=@$_GET[$name];
    }
    /** Remove sticky GET which was set by stickyGET */
    function stickyForget($name){
        unset($this->sticky_get_arguments[$name]);
    }
    /** @ignore - used by URL class */
    function getStickyArguments(){
        return $this->sticky_get_arguments;
    }

    // }}}


    // {{{ Very Important Methods
    /** Call this method from your index file. It is the main method of Agile Toolkit */
    function main(){
        try{
            // Initialize page and all elements from here
            $this->initLayout();
        }catch(Exception $e){
            if(!($e instanceof Exception_StopInit))
                return $this->caughtException($e);
            $this->caughtException($e);
        }

        try{
            $this->hook('post-init');

            $this->hook('pre-exec');

            if(isset($_GET['submit']) && $_POST){
                $this->downCall('submitted');
            }

            $this->hook('post-submit');

            $this->execute();
        }catch(Exception $e){
            $this->caughtException($e);
        }
    }
    /** Main execution loop */ 
    function execute(){
        $this->rendered['sub-elements']=array();
        try {
            $this->hook('pre-render');
            $this->recursiveRender();
            if(isset($_GET['cut_object']))
                throw new BaseException("Unable to cut object with name='".$_GET['cut_object']."'. It wasn't initialized");
            if(isset($_GET['cut_region'])){
                if(!$this->cut_region_result)
                    throw new BaseException("Unable to cut region with name='".$_GET['cut_region']."'");
                echo $this->cut_region_result;
                return;
            }
        }catch(Exception $e){
            if($e instanceof Exception_StopRender){
                $this->hook('cut-output');
                echo $e->result;
                $this->hook('post-render-output');
                return;
            }
            throw $e;

        }
    }
    /** Renders all objects inside applications and echo all output to the browser */
    function render(){
        if(isset($this->api->jquery) && $this->api->jquery)$this->api->jquery->getJS($this);

        if(!($this->template)){
            throw new BaseException("You should specify template for API object");
        }

        $this->hook('pre-render-output');
        if(headers_sent($file,$line)){
            echo "<br/>Direct output (echo or print) ditected on $file:$line. <a target='_blank' "
                ."href='http://agiletoolkit.org/error/direct_output'>Use \$this->add('Text') instead</a>.<br/>";
        }
        echo $this->template->render();
        $this->hook('post-render-output');
    }
    // }}}
    
    // {{{ Miscelanious Functions
    /** Perform instant redirect to another page */
    function redirect($page=null,$args=array()){
        /**
         * Redirect to specified page. $args are $_GET arguments.
         * Use this function instead of issuing header("Location") stuff
         */
        header("Location: ".$this->getDestinationURL($page,$args)->__toString());
        exit;
    }
    /** Called on all templates in the system, populates some system-wide tags */
    function setTags($t){
        // absolute path to base location
        $t->trySet('atk_path',$q=
                $this->api->pathfinder->atk_location->getURL().'/');
        $t->trySet('base_path',$q=$this->api->pm->base_path);

        // We are using new capability of SMlite to process tags individually
        $t->eachTag('template',array($this,'_locateTemplate'));
        $t->eachTag('page',array($this,'_locatePage'));

        $this->hook('set-tags',array($t));
    }
    /** Returns true if browser is going to EVAL output. */
    function isAjaxOutput(){
        // TODO: rename into isJSOutput();
        return isset($_POST['ajax_submit']);
    }
    /** @obsolete Change index page, typically used after login */
    function setIndexPage($page){
        $this->index_page=$page;
        return $this;
    }
    /** @obsolete */
    function getIndexPage(){
        return $this->index_page;
    }
    /** @private */
    function _locateTemplate($path){
        return $this->locateURL('template',$path);
    }
    /** @private */
    function _locatePage($path){
        return $this->getDestinationURL($path);
    }
    /** Only show $object in the final rendering */
    function renderOnly($object){
        $_GET['cut_object']=$object->name;
        return $this;
    }
    // }}}

    // {{{ Layout implementation
    private $layout_initialized=false;
    /** Implements Layouts. Layout is region in shared template which may be replaced by object */
    function initLayout(){
        if($this->layout_initialized)throw $this->exception('Please do not call initLayout() directly from init()','Obsolete');
        $this->layout_initialized=true;
        $this->addLayout('Content');
        $this->upgradeChecker();
    }
    /** Register new layout, which, if has method and tag in the template, will be rendered */
    function addLayout($name){
        if(!$this->template)return;
        // TODO: change to functionExists()
        if(method_exists($this,$lfunc='layout_'.$name)){
            if($this->template->is_set($name)){
                $this->$lfunc();
            }
        }
        return $this;
    }
    /** Default handling of Content page. To be replaced by ApiFrontend */
    function layout_Content(){
        // This function initializes content. Content is page-dependant

        $page=str_replace('/','_',$this->page);

        if(method_exists($this,$pagefunc='page_'.$page)){
            $p=$this->add('Page',$this->page,'Content');
            $this->$pagefunc($p);
        }else{
            $this->api->locate('page',str_replace('_','/',$this->page).'.php');
            $this->add('page_'.$page,$page,'Content');
            //throw new BaseException("No such page: ".$this->page);
        }
    }
    /** Default template for the application. Redefine to add your own rules. */
    function defaultTemplate(){
        return array('shared');
    }
}
