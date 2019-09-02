<?php
/**
 *  OEMBED PLUGIN 
 * 
 *  Version history
 *    2008-07-31 - release v0.6 by Dwayne Bent <dbb.pub0@liqd.org>
 *    2019-09-01 - resuscitation & realignment with "Greebo" 
 *
 *  Licensed under the GPL 2 [http://www.gnu.org/licenses/gpl.html]
 *
 **/

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
define('OEMBED_BASE',DOKU_PLUGIN.'oembed/');
require_once(DOKU_PLUGIN.'syntax.php');
require_once(DOKU_INC.'inc/HTTPClient.php');
require_once(DOKU_INC.'inc/JSON.php');

class syntax_plugin_oembed extends DokuWiki_Syntax_Plugin {
    var $errors       = array();
    var $version      = '1.0';
    var $regex_master = '/^{{>\s*(?<url>.+?)(?:\s+(?<params>.+?))??\s*}}$/';

    function getType(){
        return 'substition';
    }

    function getAllowedTypes() {
        return array();
    }

    function getPType(){
        return 'block';
    }

    function getSort(){
        return 285;
    }

    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('{{>.+?}}', $mode, 'plugin_oembed');
    }

    function handle($match, $state, $pos, Doku_Handler $handler){
        if($state == DOKU_LEXER_SPECIAL){
            if($parsed_tag = $this->parseTag($match)){
                $oembed_data = $this->resolve($parsed_tag);
                return array('oembed_data' => $oembed_data,
                             'tag'         => $parsed_tag,
                             'errors'      => $this->errors);
            }
        }
        
        return false;
    }

    function render($mode, Doku_Renderer $renderer, $data) {
        if($mode == 'xhtml'){
            $renderer->doc .= $this->renderXHTML($data);
        }

        return false;
    }

    /***************************************************************************
     * PARSE FUNCTIONS
     *     Convert input strings to a usable form
     **************************************************************************/

    /*
     * Parse the entire matched string
     *
     * $tag: The entire matched string
     *
     * returns:
     *     false on error otherwise
     *     array of parsed data:
     *         url: the target url
     *         params: array of parsed parameters, see parseParams()
     */
    function parseTag($tag){
        if(preg_match($this->regex_master, $tag, $matches)){
            return array('url'    => $matches['url'],
                         'params' => $this->parseParams($matches['params']));
        }

        return false;
    }

    /*
     * Parse the tag parameters
     *
     * $params: whitespace delimited list of parameters (no trailing or leading
     *          whitespace)
     *
     * returns:
     *     array of parsed parameters:
     *         provider: array of provider parameters:
     *             name => value
     *         plugin: array of plugin parameters
     *             name => value
     */
    function parseParams($params){
        $parsed_params = array('provider' => array(), 'plugin' => array());

        if($params != null){
            foreach(preg_split('/\s+/', $params) as $param){
                if(preg_match('/^(?<type>!|\?)(?<name>\S+?)(?:=(?<value>\S+?))?$/', $param, $matches)){
                    if($matches['type'] == '?'){
                        $parsed_params['provider'][$matches['name']] = $matches['value'];
                    }
                    else if($matches['type'] == '!'){
                        $parsed_params['plugin'][$matches['name']] = $matches['value'];
                    }
                }
            }
        }

        return $parsed_params;
    }

    /*
     * Parse an HTTP response containing OEmbed data
     *
     * $response: array of HTTP response data
     *     status: numerical HTTP status code
     *     headers: array of HTTP headers
     *         name => value
     *     body: body of the response
     *
     * returns: false on error or array of parsed oembed data:
     *         name => value
     */
    function parseResponse($response){
        if($response['status'] != 200) return $this->error("Provider returned HTTP Status {$response['status']} for {$tag['url']}");
        if(!$type = $this->parseContentType($response['headers']['content-type'])) return false;

        $oembed = array();

        switch($type){
            case 'xml':
                if(!$xml = simplexml_load_string($response['body'])) return $this->error("Unable to parse XML: {$response['body']}");

                foreach($xml as $element){
                    $oembed[$element->getName()] = (string) $element;
                }

                break;
            case 'json':
                $json = new JSON(JSON_LOOSE_TYPE);
                $oembed = $json->decode($response['body']);

                break;
            default:
                return $this->error("Internal error occured. Found type: {$type}");
        }

        if($oembed['version'] != '1.0') return $this->error("Unsupported OEmbed version: {$oembed['vesrion']}");
        return $oembed;
    }

    /*
     * Parse a content-type string from an HTTP header
     *
     * $header: The content-type string
     *
     * returns: false on error or 'json' for JSON content or 'xml' for XML content
     */
    function parseContentType($header){
        if(!preg_match('/^\s*(?<type>[^;\s]+)(.*)?/', $header, $matches)){
            return $this->error("Invalid Content-Type header: {$header}");
        }

        switch($matches['type']){
            case 'text/xml':
                return 'xml';
            case 'application/json':
                return 'json';
            // non-spec content-types, only supported for compatibility
            case 'application/xml':
                return 'xml';
            case 'text/json':
                return 'json';
            case 'text/plain':
                return 'json';
            default:
                return $this->error("Unsupported Content-Type: {$matches['type']}");
        }
    }

    /***************************************************************************
     * RESOLVE FUNCTIONS
     *     Given parsed tag data get OEmbed data
     **************************************************************************/

    /*
     * Given parsed tag information, return OEmbed data
     *
     * $tag: Parsed tag information, as from parseTag()
     *
     * returns: false on error or array of OEmbed data
     *     oembed: array of OEmbed data as returned from provider
     *     query_url: URL used to get the OEmbed data
     *     target_url: URL to which the OEmbed data refers
     */
    function resolve($tag){

        // try to resolve using cache
        if($data = $this->resolveCache($tag)) return $data;

        // try to resolve directly
        if(array_key_exists('direct', $tag['params']['plugin'])){
            if($this->getConf('enable_direct_link')){
                return $this->resolveDirect($tag);
            }
        }

        if($this->getConf('resolution_priority') == 'link discovery'){
            // try link discovery
            if($this->getConf('enable_link_discovery')){
                if($data = $this->resolveDiscovery($tag)) return $data;
            }

            // try local provider list
            if($this->getConf('enable_provider_list')){
                if($data = $this->resolveProviderList($tag)) return $data;
            }
        }
        else if($this->getConf('resolution_priority') == 'provider list'){
            // try local provider list
            if($this->getConf('enable_provider_list')){
                if($data = $this->resolveProviderList($tag)) return $data;
            }

            // try link discovery
            if($this->getConf('enable_link_discovery')){
                if($data = $this->resolveDiscovery($tag)) return $data;
            }
        }
        return $this->error("All resolution methods failed");
    }

    /*
     * Analogous to resolve(), using the cache for resolution
     */
    function resolveCache($tag){
        return false;
    }

    /*
     * Analogous to resolve(), using a directly entered API endpoint for
     * resolution
     */
    function resolveDirect($tag){
        $query_url = $this->buildURL($tag['url'], $tag['params']['provider']);
        if(!$response = $this->fetch($query_url)) return false;
        if(!$oembed = $this->parseResponse($response)) return false;

        return array('oembed'     => $oembed,
                     'query_url'  => $query_url,
                     'target_url' => $tag['params']['provider']['url']);
    }

    /*
     * Analogous to resolve(), using link discovery for resolution
     */
    function resolveDiscovery($tag){
        
        if(!$response = $this->fetch($tag['url'])) return false;
        if(!$link_url = $this->getOEmbedLink($response['body'])) return false;

        $query_url = $this->buildURL($link_url, $tag['params']['provider']);

        if(!$response = $this->fetch($query_url)) return false;
        if(!$oembed = $this->parseResponse($response)) return false;


 
        return array('oembed'     => $oembed,
                     'query_url'  => $query_url,
                     'target_url' => $tag['url']);
    }

    /*
     * Analogous to resolve(), using the local provider list for resolution
     */
    function resolveProviderList($tag){
        if(!$api = $this->getProviderAPI($tag['url'])) return false;

        $api = str_replace("{format}", $this->getConf('format_preference'), $api);
        $params = array_merge($tag['params']['provider'], array('url' => $tag['url']));
        $query_url = $this->buildURL($api, $params);

        if(!$response = $this->fetch($query_url)) return false;
        if(!$oembed = $this->parseResponse($response)) return false;

        return array('oembed'     => $oembed,
                     'query_url'  => $query_url,
                     'target_url' => $tag['url']);
    }

    /***************************************************************************
     * RENDER FUNCTIONS
     *     Convert OEmbed data to a presentable form
     **************************************************************************/

    /*
     * Given OEmbed data as returned by resolve(), produces a valid XHTML
     * representation
     *
     * $data: OEmbed data as returned by resolve()
     *
     * returns: XHTML representation of OEmbed data
     */
    function renderXHTML($data){
        $content = '';

        if(!$data['oembed_data']){
            $content .= "OEmbed Error";
            $content .= "<ul>";
            foreach($data['errors'] as $error){
                $content .= "<li>".$error."</li>";
            }
            $content .= "</ul>";

            return $content;
        }

        $oembed = $this->sanitizeOEmbed($data['oembed_data']['oembed']);
        
        if(array_key_exists('thumbnail', $data['tag']['params']['plugin'])){
            if($oembed['thumbnail_url']){
                $img = '<img src="'.$oembed['thumbnail_url'].'" alt="'.$oembed['title'].'" title="'.$oembed['title'].'" height="'.$oembed['thumbnail_height'].'px" width="'.$oembed['thumbnail_width'].'px"/>';
                $content = '<a href="'.$data['oembed_data']['target_url'].'">'.$img.'</a>';
            }
            else{
                $content = $this->renderXHTMLLink($data);
            }
        }
        else{
            switch($oembed['type']){
                case 'photo':
                    if($this->getConf('fullwidth_images')){
                        $content = '<img src="'.$oembed['url'].'" alt="'.$oembed['title'].'" title="'.$oembed['title'].'" width=100% />';
                    } else { 
                        $content = '<img src="'.$oembed['url'].'" alt="'.$oembed['title'].'" title="'.$oembed['title'].'" height="'.$oembed['height'].'px" width="'.$oembed['width'].'px"/>';
                    }
                    break;
                case 'video':
                    $content = $oembed['html'];
                    break;
                case 'link':
                    $content = $this->renderXHTMLLink($data);
                    break;
                case 'rich':
                    $content = $oembed['html'];
                    break;
                default:
                    $content = "OEmbed Error <ul><li>Unsupported media type: {$oembed['type']}</li></ul>";
            }
        }

        return $content;
    }

    /*
     * Given OEmbed data as returned by resolve(), produces a valid XHTML
     * representation as a simple link
     *
     * $data: OEmbed data as returned by resolve()
     *
     * returns: XHTML representation of OEmbed data as a simple link
     */
    function renderXHTMLLink($data){
        $text .= ($data['oembed_data']['oembed']['provider_name'] != null) ? $data['oembed_data']['oembed']['provider_name'].': ' : '';
        $text .= $data['oembed_data']['oembed']['title'];
        $text .= ($data['oembed_data']['oembed']['author_name'] != null) ? ' &ndash; '.$data['oembed_data']['oembed']['author_name'] : '';
        return '<a class="urlextern" href="'.$data['oembed_data']['target_url'].'">'.$text.'</a>';
    }

    /***************************************************************************
     * UTILITY FUNCTIONS
     *     Provides shared functionality
     **************************************************************************/

    /*
     * Stores a message in the errors array and returns false
     *
     * $msg: message to store
     *
     * returns: false
     */
    function error($msg){
        array_push($this->errors, $msg);
        return false;
    }

    /*
     * Performs an HTTP GET request on the given URL
     *
     * $url: URL to perform the request on
     *
     * returns: false on error or array representing the HTTP response
     *     status: numerical HTTP status code
     *     headers: array of HTTP headers
     *         name => value
     *     body: HTTP response body
     */
    function fetch($url){
        $client = new DokuHTTPClient();
        if(!$client->sendRequest($url)){
            return $this->error("Error sending request to provider: {$url}");
        }

        return array('status'  => $client->status,
                     'headers' => $client->resp_headers,
                     'body'    => $client->resp_body);
    }

    /*
     * Given a base URL, create a new URL using the given parameters. Query
     * values are URL encoded.
     *
     * $base: base URL, any existing parameter values should be URL encoded.
     * $params: array of parameters to add to URL
     *     name => value
     *
     * returns: the new URL
     */
    function buildURL($base, $params){
        $url = $base;

        $first = strpos($base,"?") === false ? true : false;
        foreach($params as $name => $value){
            if($first){ $url .= "?"; $first = false; }
            else { $url .= "&"; }

            $url .= $name."=".rawurlencode($value);
        }

        return $url;
    }

    /*
     * Given raw HTML, tries to extract oembed discovery link
     *
     * Based on code by Keith Devens:
     * http://keithdevens.com/weblog/archive/2002/Jun/03/RSSAuto-DiscoveryPHP
     *
     * Parameters:
     *   $html: raw HTML
     *
     * Returns: false on error or no link present or an OEmbed discovery link
     */
    function getOEmbedLink($html){
        $ret_link = false;

        if(!$html) return false;

        // search through the HTML, save all <link> tags
        // and store each link's attributes in an associative array
        preg_match_all('/<link\s+(.*?)\s*\/?>/si', $html, $matches);
        $links = $matches[1];
        $final_links = array();
        $link_count = count($links);
        for($n=0; $n<$link_count; $n++){
            $attributes = preg_split('/\s+/s', $links[$n]);
            foreach($attributes as $attribute){
                $att = preg_split('/\s*=\s*/s', $attribute, 2);
                if(isset($att[1])){
                    $att[1] = preg_replace('/([\'"]?)(.*)\1/', '$2', $att[1]);
                    $final_link[strtolower($att[0])] = $att[1];
                }
            }
            $final_links[$n] = $final_link;
        }

        // now figure out which one points to the OEmbed data
        for($n=0; $n<$link_count; $n++){
            if(strtolower($final_links[$n]['rel']) == 'alternate'){
                if(strtolower($final_links[$n]['type']) == 'application/json+oembed'){
                    if($this->getConf('format_preference') == 'json'){
                        return $final_links[$n]['href'];
                    }
                    else{
                        $ret_link = $final_links[$n]['href'];
                    }
                }

                // application/xml+oembed only exists for compatability not in spec
                if(strtolower($final_links[$n]['type']) == 'text/xml+oembed' or
                   strtolower($final_links[$n]['type']) == 'application/xml+oembed'){
                    if($this->getConf('format_preference') == 'xml'){
                        return $final_links[$n]['href'];
                    }
                    else{
                        $ret_link = $final_links[$n]['href'];
                    }
                }
            }
        }

        return $ret_link;
    }

    /*
     * Given a URL, finds a OEmbed provider API endpoint which can be used with
     * it from the local provider list.
     *
     * $url: URL to search a provider for
     *
     * Returns: false on error or no provider find or the API endpoint of an
     *          appropriate provider
     */
    function getProviderAPI($url){
        $providers_path = OEMBED_BASE.'providers.xml';
        if(!$providers = simplexml_load_file($providers_path)) return false;

        foreach($providers->provider as $provider){
            foreach($provider->scheme as $scheme){
                $regex = "@^".str_replace("@","\@",$scheme)."$@i";
                if(preg_match($regex, trim($url))){
                    $attrs = $provider->attributes();
                    if(($api = $attrs['api']) != null){
                        return $api;
                    }
                }
            }
        }

        return false;
    }

    /*
     * Runs htmlspecialchars() on values in OEmbed data EXCEPT for html values
     *
     * $oembed: array of OEmbed data from parseResponse()
     *
     * Returns: identical array to $oembed in which all values except for html
     *          are run through htmlspecialchars()
     */
    function sanitizeOEmbed($oembed){
        $retarray = array();

        foreach($oembed as $key => $value){
            if($key == 'html'){
                $retarray[$key] = $value;
            }
            else{
                $retarray[$key] = htmlspecialchars($value);
            }
        }
        
        return $retarray;
    }

    /***************************************************************************
     * DEBUG FUNCTIONS
     *     For testing and devlopment, not regularly used
     **************************************************************************/

    function _log($msg){
        $fh = fopen(OEMBED_BASE."oembed.log",'a');
        $curtime = date('Y-m-d H:i:s');
        fwrite($fh, "[{$curtime}] {$msg}\n");
        fclose($fh);
    }

    function _logParsedTag($parsed_tag){
        $this->_log("Parsed Tag");
        $this->_log("    URL: {$parsed_tag['url']}");
        $this->_log("    Provider Params:");
        foreach($parsed_tag['params']['provider'] as $key => $value){
            $this->_log("        {$key} => {$value}");
        }
        $this->_log("    Plugin Params:");
        foreach($parsed_tag['params']['plugin'] as $key => $value){
            $this->_log("        {$key} => {$value}");
        }
    }

    function _logOEmbedData($oembed){
        $this->_log("OEmbed Data:");
        $this->_log("    target_url: {$oembed['target_url']}");
        $this->_log("    query_url: {$oembed['query_url']}");
        $this->_log("    Response:");
        foreach($oembed['oembed'] as $name => $value){
            $this->_log("        {$name}: {$value}");
        }
    }

    function _logErrors($errors){
        $this->_log("Errors:");
        foreach($errors as $error){
            $this->_log("    {$error}");
        }
    }
}

